<?php
/**
 * 正文自动内链（AI 语义内链）
 *
 * 目的：解决「内链孤岛」的另一条路径——相关推荐给的是**模块级**入链（每帖 8 条，
 * 位于正文之外），本功能给的是**正文级**入链：让 AI 读懂帖子在讲什么，从站内
 * 语义最相关的帖子中挑一篇，在正文里选一个**本就存在的具体词**做锚文本，链过去。
 *
 * ---------------------------------------------------------------------------
 * 为什么不让 AI 直接写链接
 * ---------------------------------------------------------------------------
 * 让模型输出带 <a> 的 HTML 是内链功能里最危险的做法：模型会改写原文、插到
 * 标签中间、把标题/代码块也链掉，甚至直接幻觉出一段不存在的句子。
 * 本实现把它拆成「模型只做选择题，插件做填空题」：
 *   1. 插件先检索候选帖（同标签 / 标题关键词 / 同板块），把「编号 + 标题 + 标签」
 *      交给模型；
 *   2. 模型只回答两件事：选第几号（target），以及正文里哪几个词可以做锚文本
 *      （anchors，必须是正文里原样出现过的连续片段）；
 *   3. 插件用确定性算法把 <a> 插入到正文**文本节点内部**——绝不改动原文任何一个
 *      字，绝不跨越标签边界，绝不动 <a>/<pre>/<code>/<script>/<h1>-<h6> 等区域。
 * 因此：锚文本找不到就自动降级（换下一个候选词），再找不到就干脆不插入。
 *
 * ---------------------------------------------------------------------------
 * 渲染期插入，不落库
 * ---------------------------------------------------------------------------
 * 生成的只是「帖子 ID → 目标帖 + 锚文本候选」这份映射（存 post meta `_fas_ilink`），
 * 真正的 <a> 是在 the_content 过滤时**临时拼出来的**。好处：
 *   - 随时可关（关掉即全部消失），不污染数据库原文、不需要批量回滚；
 *   - 帖子编辑后锚文本若已不存在，渲染时自然跳过，不会留下坏链接；
 *   - 与「相关推荐」「外链处理」互不干扰（挂 15，早于二者的 20 / 30）。
 *
 * 一条帖子里只要一个正文内链就够——因为浏览型入链已由「相关推荐」承担（每帖 8 条），
 * 正文里再堆链接只会稀释锚文本权重、也容易被判为过度优化。默认每帖 1 条，可调 1~5。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Auto_Link {

	/** @var FAS_Auto_Link */
	private static $instance = null;

	/** 结果映射所在的 post meta key。 */
	const META_LINKS = '_fas_ilink';

	/** 允许插入 <a> 的 class。 */
	const LINK_CLASS = 'fas-inlink';

	/** 禁止插入链接的标签（进入这些标签内部一律跳过）。 */
	private static $protected_tags = array(
		'a',
		'pre',
		'code',
		'script',
		'style',
		'textarea',
		'button',
		'select',
		'option',
		'svg',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 挂在 15：晚于核心格式化（wpautop/shortcode 在 10~11，保证段落已成形），
		// 又早于「AI 摘要卡片」的置顶注入（20）——否则摘要文案里若出现同一个锚词，
		// 链接会优先落在摘要卡里而不是正文中。外链处理(20)与相关推荐(30)都在其后，
		// 且它们只处理外链 / 追加模块，不会碰到我们插入的站内链接。
		add_filter( 'the_content', array( $this, 'apply_links' ), 15 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );

		// v1.39.0：「正文自动内链」后台页与批量/单篇/清空接口已移除。
		// 功能本身保留：the_content(15) 渲染、发布自动链路（fas_ilink_auto_event）
		// 生成映射、设置页「自动内链」配置；clear_all() 仍可程序调用清空映射。
	}

	/* =====================================================================
	 * 配置
	 * ===================================================================== */

	/**
	 * 是否启用正文自动内链。
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return ! empty( FAS_Options::get_value( 'ilink_enabled', 0 ) );
	}

	/**
	 * 每帖正文内链数量（1~5，默认 1）。
	 *
	 * @return int
	 */
	private function per_post() {
		$n = (int) apply_filters( 'fas_ilink_per_post', (int) FAS_Options::get_value( 'ilink_per_post', 1 ) );
		return max( 1, min( 5, $n ) );
	}

	/**
	 * 交给 AI 的候选帖数量（默认 20）。
	 *
	 * @return int
	 */
	private function candidate_count() {
		$n = (int) apply_filters( 'fas_ilink_candidates', (int) FAS_Options::get_value( 'ilink_candidates', 20 ) );
		return max( 5, min( 40, $n ) );
	}

	/**
	 * 正文最短字数：低于此长度不生成内链（太短的帖子没有可用的语义上下文）。
	 *
	 * @return int
	 */
	private function min_chars() {
		return max( 20, (int) FAS_Options::get_value( 'ilink_min_chars', 120 ) );
	}

	/**
	 * 排除的板块 ID（这些板块按站点约定「视为不存在」，既不作为来源、也不作为目标）。
	 *
	 * @return int[]
	 */
	private function excluded_plates() {
		$raw = (string) FAS_Options::get_value( 'ilink_exclude_plates', '' );
		$ids = array_filter( array_map( 'intval', preg_split( '/[,，\s]+/', $raw ) ) );
		$ids = array_values( array_unique( $ids ) );
		return array_values( array_map( 'intval', (array) apply_filters( 'fas_ilink_excluded_plates', $ids ) ) );
	}

	/**
	 * 判断某帖是否属于「被排除板块」。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return bool
	 */
	private function is_excluded_post( $post_id ) {
		$excluded = $this->excluded_plates();
		if ( empty( $excluded ) ) {
			return false;
		}
		$plate = (int) FAS_Forum::get_plate_id( $post_id );
		return $plate > 0 && in_array( $plate, $excluded, true );
	}

	/* =====================================================================
	 * 渲染期应用（the_content）
	 * ===================================================================== */

	/**
	 * 前端样式：锚文本的低调虚线下划线样式。
	 *
	 * 用与「相关推荐」相同的 `fas-front` 句柄加载 front.css——句柄相同，
	 * 相关推荐也启用时不会重复加载。
	 */
	public function enqueue_front_assets() {
		if ( ! $this->is_enabled() || ! is_singular( FAS_Forum::post_type() ) ) {
			return;
		}
		wp_enqueue_style( 'fas-front', FAS_PLUGIN_URL . 'assets/css/front.css', array(), FAS_VERSION );
	}

	/**
	 * 在正文中应用已生成的内链映射。
	 *
	 * @param string $content 正文 HTML。
	 * @return string
	 */
	public function apply_links( $content ) {
		if ( ! $this->is_enabled() ) {
			return $content;
		}
		if ( is_feed() || is_embed() || is_admin() ) {
			return $content;
		}
		if ( ! is_singular( FAS_Forum::post_type() ) ) {
			return $content;
		}

		$post_id = (int) get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$records = $this->get_records( $post_id );
		if ( empty( $records ) ) {
			return $content;
		}

		$limit = $this->per_post();
		$done  = 0;

		foreach ( $records as $rec ) {
			if ( $done >= $limit ) {
				break;
			}
			$url = isset( $rec['url'] ) ? (string) $rec['url'] : '';
			if ( '' === $url ) {
				continue;
			}
			$title   = isset( $rec['title'] ) ? (string) $rec['title'] : '';
			$anchors = isset( $rec['anchors'] ) ? (array) $rec['anchors'] : array();

			foreach ( $anchors as $anchor ) {
				$anchor = is_string( $anchor ) ? trim( $anchor ) : '';
				if ( '' === $anchor ) {
					continue;
				}
				// 锚词在正文里根本不存在时直接跳过：insert_once() 内部按块做
				// mb_stripos，全文找不到则任何块里也必然找不到（块拼起来就是全文），
				// 所以这层预判与「跑一次 insert_once 返回 null」完全等价——
				// 但省掉了为每个不存在的锚词重切分整篇 HTML 的开销
				// （帖子被编辑后锚词失效是最常见的情况）。
				if ( false === mb_stripos( $content, $anchor, 0, 'UTF-8' ) ) {
					continue;
				}
				$replaced = $this->insert_once( $content, $anchor, $url, $title );
				if ( null !== $replaced ) {
					$content = $replaced;
					$done++;
					break;
				}
			}
		}

		return $content;
	}

	/**
	 * 把锚文本第一次出现的位置包成一个 <a>（只在文本节点内、只在保护区域之外）。
	 *
	 * 实现要点：先把 HTML 按「标签 / 文本」切成 token 流，再用一个深度计数器跟踪
	 * 是否处于保护标签内部；只有在深度为 0 的文本 token 里才允许替换，因此绝不会
	 * 跨标签、绝不会破坏已有链接或代码块。
	 *
	 * @param string $html   正文 HTML。
	 * @param string $anchor 锚文本（正文中已存在的连续片段）。
	 * @param string $url    目标地址。
	 * @param string $title  目标帖标题（作为 title 属性）。
	 * @return string|null 替换后的 HTML；未找到可插入位置时返回 null。
	 */
	private function insert_once( $html, $anchor, $url, $title ) {
		if ( '' === $html || '' === $anchor ) {
			return null;
		}

		// 引号感知的标签切分：能正确处理 <img alt="a>b"> 这类属性里带 '>' 的标签。
		$re    = '#(<[^>"\']*(?:"[^"]*"|\'[^\']*\'|[^>"\']*)*>)#';
		$parts = preg_split( $re, $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$depth     = 0;
		$a_len     = mb_strlen( $anchor, 'UTF-8' );
		$protected = self::$protected_tags;

		foreach ( $parts as $i => $part ) {
			if ( '' === $part || null === $part ) {
				continue;
			}

			// —— 标签 token：维护保护深度。
			if ( '<' === $part[0] ) {
				if ( preg_match( '#^<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9]*)#', $part, $m ) ) {
					$name = strtolower( $m[2] );
					if ( in_array( $name, $protected, true ) ) {
						if ( '/' === $m[1] ) {
							$depth = max( 0, $depth - 1 );
						} else {
							$depth++;
						}
					}
				}
				continue;
			}

			// —— 文本 token：保护区域内不处理。
			if ( $depth > 0 ) {
				continue;
			}

			$pos = mb_stripos( $part, $anchor, 0, 'UTF-8' );
			if ( false === $pos ) {
				continue;
			}

			// 命中的位置必须完全落在实体（&...;）之外。
			if ( $this->inside_entity( $part, $pos ) ) {
				continue;
			}

			$before = mb_substr( $part, 0, $pos, 'UTF-8' );
			$hit    = mb_substr( $part, $pos, $a_len, 'UTF-8' );
			$after  = mb_substr( $part, $pos + $a_len, null, 'UTF-8' );

			$tag = '<a class="' . esc_attr( self::LINK_CLASS ) . '" href="' . esc_url( $url ) . '"'
				. ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' )
				. '>';

			$parts[ $i ] = $before . $tag . esc_html( $hit ) . '</a>' . $after;

			return implode( '', $parts );
		}

		return null;
	}

	/**
	 * 判断给定位置是否落在 HTML 实体（如 &amp; &#39;）内部。
	 *
	 * @param string $text 文本片段。
	 * @param int    $pos  字符位置（UTF-8 字符数）。
	 * @return bool
	 */
	private function inside_entity( $text, $pos ) {
		if ( $pos <= 0 ) {
			return false;
		}
		$start  = max( 0, $pos - 12 );
		$before = mb_substr( $text, $start, $pos - $start, 'UTF-8' );
		$amp    = mb_strrpos( $before, '&', 0, 'UTF-8' );
		if ( false === $amp ) {
			return false;
		}
		$tail = mb_substr( $before, $amp, null, 'UTF-8' );
		return false === strpos( $tail, ';' );
	}

	/* =====================================================================
	 * 元数据读写
	 * ===================================================================== */

	/**
	 * 读取某帖的内链映射记录。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return array
	 */
	public function get_records( $post_id ) {
		$data = get_post_meta( (int) $post_id, self::META_LINKS, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * 判断某帖是否已生成内链映射。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return bool
	 */
	private function has_records( $post_id ) {
		return ! empty( $this->get_records( $post_id ) );
	}

	/* =====================================================================
	 * 生成
	 * ===================================================================== */

	/**
	 * 为单篇帖子生成内链映射。
	 *
	 * @param int  $post_id 帖子 ID。
	 * @param bool $force   是否强制重新生成。
	 * @return array {code, message}
	 */
	public function generate_for_post( $post_id, $force = false ) {
		$post_id = (int) $post_id;

		if ( ! FAS_Options::is_configured() ) {
			return $this->result( 'error', '插件未启用或 API 未配置完整（密钥/地址/模型）' );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! FAS_Forum::is_forum_post( $post->post_type ) || 'publish' !== $post->post_status ) {
			return $this->result( 'skipped', '非已发布的子比论坛帖子，已忽略' );
		}

		if ( $this->is_excluded_post( $post_id ) ) {
			return $this->result( 'skipped', '该帖属于已排除板块，按站点约定不处理' );
		}

		if ( ! $force && $this->has_records( $post_id ) ) {
			return $this->result( 'already', '已有内链映射，已跳过' );
		}

		$title   = FAS_Forum::get_title( $post_id );
		$content = FAS_Forum::get_content( $post_id );

		$clean     = FAS_Generator::instance()->clean_content( $content );
		$clean_len = mb_strlen( $clean, 'UTF-8' );
		if ( '' === trim( $clean ) || $clean_len < $this->min_chars() ) {
			update_post_meta( $post_id, '_fas_ilink_status', 'skipped' );
			update_post_meta( $post_id, '_fas_ilink_error', '正文过短（' . $clean_len . ' 字），未调用 AI' );
			return $this->result( 'skipped', '正文过短，跳过' );
		}

		$max = (int) FAS_Options::get_value( 'max_input_chars', 3000 );
		$max = max( 200, $max );
		if ( $clean_len > $max ) {
			$clean = mb_substr( $clean, 0, $max, 'UTF-8' );
		}

		$candidates = $this->build_candidates( $post_id, $this->candidate_count() );
		if ( empty( $candidates ) ) {
			update_post_meta( $post_id, self::META_LINKS, array() );
			update_post_meta( $post_id, '_fas_ilink_status', 'no_target' );
			update_post_meta( $post_id, '_fas_ilink_error', '站内没有可作为内链目标的候选帖' );
			return $this->result( 'skipped', '没有候选目标帖' );
		}

		try {
			$api  = new FAS_Api();
			$data = $api->pick_internal_link( $title, $clean, $candidates, $this->per_post() );
		} catch ( FAS_Exception $e ) {
			update_post_meta( $post_id, '_fas_ilink_status', 'failed' );
			update_post_meta( $post_id, '_fas_ilink_error', $e->getMessage() );
			return $this->result( 'failed', $e->getMessage() );
		}

		$picked = isset( $data['links'] ) ? (array) $data['links'] : array();
		if ( empty( $picked ) ) {
			update_post_meta( $post_id, self::META_LINKS, array() );
			update_post_meta( $post_id, '_fas_ilink_status', 'no_target' );
			update_post_meta( $post_id, '_fas_ilink_error', '模型判定没有语义相关的候选帖' );
			return $this->result( 'skipped', '模型判定无可内链目标' );
		}

		$records    = array();
		$anchor_hit = false;
		$any_target = false;

		foreach ( $picked as $item ) {
			$tid = isset( $item['target'] ) ? (int) $item['target'] : 0;
			if ( $tid <= 0 || $tid === $post_id ) {
				continue;
			}
			$target = get_post( $tid );
			if ( ! $target || 'publish' !== $target->post_status || ! FAS_Forum::is_forum_post( $target->post_type ) ) {
				continue;
			}
			if ( $this->is_excluded_post( $tid ) ) {
				continue;
			}
			$any_target = true;

			$anchors = isset( $item['anchors'] ) ? (array) $item['anchors'] : array();
			$anchors = array_values( array_filter( array_map( 'strval', $anchors ) ) );
			if ( empty( $anchors ) ) {
				continue;
			}

			// 生成期即校验锚文本能否在原文中落位：任一锚词能落位才保存该条记录。
			// 全部落位失败的条目跳过——渲染期它必然插不进去（正文里的文本匹配是
			// 确定性的），存下来只会让 meta 膨胀并让 no_anchor/no_target 语义变模糊。
			$can_place = false;
			foreach ( $anchors as $a ) {
				if ( null !== $this->insert_once( $content, $a, '#', '' ) ) {
					$can_place = true;
					break;
				}
			}
			if ( ! $can_place ) {
				continue;
			}
			$anchor_hit = true;

			$records[] = array(
				'target'  => $tid,
				'url'     => get_permalink( $tid ),
				'title'   => FAS_Forum::get_title( $tid ),
				'anchor'  => $anchors[0],
				'anchors' => $anchors,
				'reason'  => isset( $item['reason'] ) ? (string) $item['reason'] : '',
			);
		}

		if ( empty( $records ) ) {
			update_post_meta( $post_id, self::META_LINKS, array() );
			if ( $any_target ) {
				// 候选目标有效，但模型给的锚词没有一个能在正文落位。
				update_post_meta( $post_id, '_fas_ilink_status', 'no_anchor' );
				update_post_meta( $post_id, '_fas_ilink_error', '锚文本未在正文中找到，渲染时不插入' );
				return $this->result( 'skipped', '锚文本无法在正文落位' );
			}
			update_post_meta( $post_id, '_fas_ilink_status', 'no_target' );
			update_post_meta( $post_id, '_fas_ilink_error', '候选目标帖均已失效或被排除' );
			return $this->result( 'skipped', '候选目标帖无效' );
		}

		update_post_meta( $post_id, self::META_LINKS, $records );
		update_post_meta( $post_id, '_fas_ilink_status', $anchor_hit ? 'success' : 'no_anchor' );
		update_post_meta( $post_id, '_fas_ilink_error', $anchor_hit ? '' : '锚文本未在正文中找到，渲染时可能不插入' );
		update_post_meta( $post_id, '_fas_ilink_at', time() );

		return $this->result( 'success', '已生成 ' . count( $records ) . ' 条内链' . ( $anchor_hit ? '' : '（锚文本待复核）' ) );
	}

	/**
	 * 检索候选目标帖。
	 *
	 * 分三层，按相关性从强到弱：同标签 → 标题关键词命中 → 同板块。
	 * 与「相关推荐」不同，这里不追求均衡（那是浏览型入链的事），**只取最相关的**，
	 * 因为正文内链的价值几乎全在语义贴合度上。
	 *
	 * @param int $post_id 当前帖 ID。
	 * @param int $limit   候选数量上限。
	 * @return array 每项 {id, title, tags}
	 */
	private function build_candidates( $post_id, $limit ) {
		$post_id  = (int) $post_id;
		$limit    = max( 5, (int) $limit );
		$excluded = $this->excluded_plates();

		$ids = array();
		$add = function ( $list ) use ( &$ids, $post_id, $limit ) {
			foreach ( (array) $list as $id ) {
				$id = (int) $id;
				if ( $id > 0 && $id !== $post_id && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
				if ( count( $ids ) >= $limit ) {
					return;
				}
			}
		};

		$exclude_ids = array( $post_id );

		// 1) 同标签。
		$term_ids = wp_get_object_terms( $post_id, FAS_Forum::tag_taxonomy(), array( 'fields' => 'ids' ) );
		$term_ids = is_wp_error( $term_ids ) ? array() : array_map( 'intval', $term_ids );
		if ( ! empty( $term_ids ) ) {
			$add(
				$this->query_candidates(
					array(
						'tax_query' => array(
							array(
								'taxonomy' => FAS_Forum::tag_taxonomy(),
								'field'    => 'term_id',
								'terms'    => $term_ids,
							),
						),
					),
					$exclude_ids,
					$limit
				)
			);
		}

		// 2) 标题关键词命中（只 LIKE post_title，不做正文全表扫描）。
		if ( count( $ids ) < $limit ) {
			$keywords = $this->extract_keywords( $post_id );
			if ( ! empty( $keywords ) ) {
				global $wpdb;
				$likes = array();
				foreach ( $keywords as $k ) {
					$likes[] = $wpdb->prepare( 'post_title LIKE %s', '%' . $wpdb->esc_like( $k ) . '%' );
				}
				$where = implode( ' OR ', $likes );
				$sql   = $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID != %d AND ({$where}) ORDER BY post_date DESC LIMIT %d",
					FAS_Forum::post_type(),
					$post_id,
					$limit * 2
				);
				$add( $wpdb->get_col( $sql ) );
			}
		}

		// 3) 同板块兜底。
		if ( count( $ids ) < $limit ) {
			$plate_id = (int) FAS_Forum::get_plate_id( $post_id );
			if ( $plate_id > 0 && ! in_array( $plate_id, $excluded, true ) ) {
				$add( $this->query_candidates( array(), $exclude_ids, $limit, $plate_id ) );
			}
		}

		// 4) 随机兜底：保证短站点也能凑够候选。
		if ( count( $ids ) < $limit ) {
			$add( $this->query_candidates( array(), $exclude_ids, $limit, 0, 'rand' ) );
		}

		$ids = array_slice( array_values( $ids ), 0, $limit );

		// 过滤排除板块 + 组装返回结构（一次预载 meta，避免 N+1）。
		if ( empty( $ids ) ) {
			return array();
		}
		update_meta_cache( 'post', $ids );

		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $excluded && $this->is_excluded_post( $id ) ) {
				continue;
			}
			$title = FAS_Forum::get_title( $id );
			if ( '' === trim( (string) $title ) ) {
				continue;
			}
			$tags = get_post_meta( $id, '_fas_tags', true );
			$tags = is_array( $tags ) ? array_slice( array_map( 'strval', $tags ), 0, 5 ) : array();

			$out[] = array(
				'id'    => $id,
				'title' => $title,
				'tags'  => $tags,
			);
		}

		return $out;
	}

	/**
	 * 候选查询：统一排除自身、排除板块、只取已发布论坛帖。
	 *
	 * @param array  $extra    附加查询参数。
	 * @param int[]  $exclude  必须排除的帖子 ID。
	 * @param int    $limit    数量上限。
	 * @param int    $plate_id 限定板块（0 表示不限）。
	 * @param string $orderby  排序：date｜rand。
	 * @return int[]
	 */
	private function query_candidates( $extra, $exclude, $limit, $plate_id = 0, $orderby = 'date' ) {
		$args = array(
			'post_type'        => FAS_Forum::post_type(),
			'post_status'      => 'publish',
			'numberposts'      => max( 1, (int) $limit ) * 2,
			'fields'           => 'ids',
			'post__not_in'     => array_map( 'intval', (array) $exclude ),
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'orderby'          => $orderby,
		);

		if ( $plate_id > 0 ) {
			$args['meta_key']   = FAS_Forum::plate_meta_key();
			$args['meta_value'] = $plate_id;
		}

		$q = get_posts( array_merge( $args, (array) $extra ) );
		return is_wp_error( $q ) ? array() : array_map( 'intval', $q );
	}

	/**
	 * 从标题 + AI 标签提取核心关键词（用于候选检索）。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return string[]
	 */
	private function extract_keywords( $post_id ) {
		$title = (string) FAS_Forum::get_title( $post_id );
		$tags  = get_post_meta( (int) $post_id, '_fas_tags', true );
		$text  = $title . ' ' . ( is_array( $tags ) ? implode( ' ', $tags ) : (string) $tags );

		$text   = preg_replace( '/[0-9A-Za-z][0-9A-Za-z.\- ]*/u', ' ', $text );
		$text   = preg_replace( '/[【】\[\]（）()《》「」"\'，。、！？：；,.!?:;\-\/\\\\|]+/u', ' ', $text );
		$tokens = array_filter( array_map( 'trim', (array) preg_split( '/\s+/u', trim( (string) $text ) ) ) );

		$stop = array( '软件', '资源', '下载', '免费', '教程', '分享', '最新', '版本', '破解', '绿色', '工具', '推荐', '大全', '论坛', '合集', '安卓', '电脑' );

		$keywords = array();
		foreach ( $tokens as $t ) {
			$len = mb_strlen( $t, 'UTF-8' );
			if ( $len < 2 || $len > 12 || in_array( $t, $stop, true ) ) {
				continue;
			}
			$keywords[] = $t;
		}

		$keywords = array_values( array_unique( $keywords ) );
		usort(
			$keywords,
			function ( $a, $b ) {
				return mb_strlen( $b, 'UTF-8' ) - mb_strlen( $a, 'UTF-8' );
			}
		);

		return array_slice( $keywords, 0, 4 );
	}

	/* =====================================================================
	 * 统计
	 * ===================================================================== */

	/**
	 * 清空全部内链映射（不影响帖子原文）。
	 */
	public function clear_all() {
		global $wpdb;

		$keys = array( self::META_LINKS, '_fas_ilink_status', '_fas_ilink_error', '_fas_ilink_at' );
		foreach ( $keys as $key ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$key
				)
			);
		}
	}

	/* =====================================================================
	 * 后台页面
	 * ===================================================================== */

	/* =====================================================================
	 * AJAX
	 * ===================================================================== */

	/**
	 * 统一结果结构。
	 *
	 * @param string $code    状态码。
	 * @param string $message 描述。
	 * @return array
	 */
	private function result( $code, $message ) {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}
}
