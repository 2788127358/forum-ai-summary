<?php
/**
 * 帖子「相关推荐」独立模块
 *
 * 在每篇论坛帖子正文与评论模块之间，输出一个「相关推荐」横向滚动卡片区，
 * 取与该帖标签相同 / 关键词相近的帖子，随机抽取 8 篇，用于补足 P2C5 内链
 * 布局偏薄、高度依赖导航而非相关内容的问题。
 *
 * 位置机制：服务端挂在 the_content（确保内链真实存在于 HTML、SEO 友好），
 * 前端 front.js 在 DOM 就绪后把 `.fas-related` 节点移动到评论模块 `#comments`
 * 之前（正文下方 / 评论上方），从而形成独立卡片；评论关闭时兜底放到正文
 * article 之后。
 *
 * 关联策略（按优先级，取到足够数量即止）：
 *   1. 相同 forum_tag 标签的帖子；
 *   2. 关键词相似（标题 + AI 标签提取出的核心词做标题搜索）；
 *   3. 同板块（plate）帖子兜底；
 *   4. 随机 / 最新帖子兜底。
 * 最终去重、排除自身后按「均衡模式」挑出前 N 篇。
 *
 * 内链均衡（v1.34.0，解决「内链孤岛 / 马太效应」）
 * ---------------------------------------------------------------------------
 * 旧实现每一层候选都是 `ORDER BY post_date DESC` 取 limit×3 条再洗牌，等于
 * 候选池**全部是最新帖**——老帖无论怎么随机都进不了池子，长期只有热门新帖
 * 反复获得入链，正是马太效应型的孤岛。三种模式：
 *   - off    ：旧行为（时间倒序取样），保留给需要精确复现旧结果的场景；
 *   - random ：各层候选改为随机采样（ORDER BY RAND），候选池对全量帖子开放；
 *   - fair   ：在 random 之上再按「曝光次数」反向加权 —— 被推荐得越少越优先，
 *              曝光次数记录在 post meta `_fas_rel_imp`，使全站帖子入链机会趋匀。
 * 另外支持「同板块数量上限」，避免单次推荐 8 篇全来自同一板块。
 *
 * 曝光的写入时机：只在本帖模块**构建时**（缓存未命中）累加，且累积在内存里、
 * 延迟到 `shutdown` 一次性批量写库，因此页面渲染阶段没有任何额外写操作。
 *
 * 性能说明（重要）：
 *   - 整个模块 HTML 用 transient 缓存，默认 1 小时，命中时仅 1 次查询；
 *   - 未命中时批量一次取回全部候选帖子（避免 N+1 逐篇 get_post）；
 *   - 封面优先取「正文第一张配图」的**缩略图**（经附件 ID 取几 KB 的 150×150 缩略图，
 *     无缩略图时回退原图），前端再按可视区懒加载，避免 8 张原图首屏并发拖慢整页；
 *     三级（正文首图 → 特色图 → 主题缩略图 meta）都取不到时，最后回退到「基本设置」
 *     页配置的默认分享图，不再出现首字占位空图卡；
 *   - 关键词检索仅对 post_title 做 LIKE（不做 post_content 全表扫描）。
 *
 * SEO 说明：模块内链接**刻意不加 rel="nofollow"**，让搜索引擎沿「专题 → 帖子
 * → 相关帖子」正常传递权重，提升新帖发现速度与老帖持续召回。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Related {

	/** @var FAS_Related */
	private static $instance = null;

	/** @var array 请求内累积的「待记曝光」帖子 ID（去重）。 */
	private static $pending_imp = array();

	/** @var bool 是否已挂载 shutdown 批量写入。 */
	private static $imp_scheduled = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 正文末尾追加相关推荐（优先级低于摘要框的置顶注入，避免互相干扰）。
		add_filter( 'the_content', array( $this, 'maybe_append_related' ), 30 );
		add_shortcode( 'zib_related', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );

		// 帖子保存/删除时失效相关推荐缓存（全局版本号自增，天然失效全部旧缓存）。
		add_action( 'save_post', array( $this, 'bust_cache' ), 20, 1 );
		add_action( 'deleted_post', array( $this, 'bust_cache' ), 20, 1 );

		// 后台「重置推荐曝光统计」（admin-post.php 入口，非设置表单）。
		add_action( 'admin_post_fas_related_reset', array( $this, 'handle_reset' ) );
	}

	/**
	 * 前端资源：相关推荐模块的样式与滚动交互脚本。
	 */
	public function enqueue_front_assets() {
		if ( ! $this->is_enabled() || ! is_singular( FAS_Forum::post_type() ) ) {
			return;
		}
		wp_enqueue_style( 'fas-front', FAS_PLUGIN_URL . 'assets/css/front.css', array(), FAS_VERSION );
		wp_enqueue_script( 'fas-related', FAS_PLUGIN_URL . 'assets/js/front.js', array(), FAS_VERSION, true );
	}

	/**
	 * 是否启用相关推荐。
	 *
	 * @return bool
	 */
	private function is_enabled() {
		return ! empty( FAS_Options::get_value( 'related_enabled', 1 ) );
	}

	/**
	 * 推荐数量（默认 8）。
	 *
	 * @return int
	 */
	private function count() {
		$n = (int) FAS_Options::get_value( 'related_count', 8 );
		return max( 1, min( 20, $n ) );
	}

	/**
	 * 内链均衡模式：off｜random｜fair。
	 *
	 * @return string
	 */
	private function balance_mode() {
		$mode = (string) apply_filters( 'fas_related_balance_mode', (string) FAS_Options::get_value( 'related_balance', 'fair' ) );
		return in_array( $mode, array( 'off', 'random', 'fair' ), true ) ? $mode : 'fair';
	}

	/**
	 * 单次推荐中同一板块的帖子数量上限（0 = 不限）。
	 *
	 * @return int
	 */
	private function plate_cap() {
		return max( 0, (int) apply_filters( 'fas_related_plate_cap', (int) FAS_Options::get_value( 'related_plate_max', 0 ) ) );
	}

	/**
	 * 曝光计数所用的 post meta key。
	 *
	 * @return string
	 */
	private function impression_meta_key() {
		return apply_filters( 'fas_related_impression_meta_key', '_fas_rel_imp' );
	}

	/* =====================================================================
	 * 缓存
	 * ===================================================================== */

	/**
	 * 缓存版本号：帖子保存/删除时自增，使全部旧缓存自然失效。
	 *
	 * @return int
	 */
	private function cache_version() {
		return (int) get_option( 'fas_related_ver', 1 );
	}

	/**
	 * 生成缓存键。
	 *
	 * @param int $post_id 当前帖子 ID。
	 * @param int $limit   推荐数量。
	 * @return string
	 */
	private function cache_key( $post_id, $limit ) {
		// 缓存键携带插件版本号：升级插件后旧缓存自动失效，避免残留旧版 HTML。
		return 'fas_related_' . FAS_VERSION . '_' . $this->cache_version() . '_' . (int) $post_id . '_' . (int) $limit;
	}

	/**
	 * 正常缓存时长（默认 1 小时）。
	 *
	 * @return int
	 */
	private function cache_ttl() {
		return (int) apply_filters( 'fas_related_cache_ttl', HOUR_IN_SECONDS );
	}

	/**
	 * 空结果缓存时长（5 分钟，避免无相关帖时每次都空跑检索）。
	 *
	 * @return int
	 */
	private function empty_ttl() {
		return 5 * MINUTE_IN_SECONDS;
	}

	/**
	 * 帖子保存/删除时失效相关推荐缓存。
	 *
	 * @param int $post_id 帖子 ID。
	 */
	public function bust_cache( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! FAS_Forum::is_forum_post( get_post_type( $post_id ) ) ) {
			return;
		}
		update_option( 'fas_related_ver', $this->cache_version() + 1 );
	}

	/* =====================================================================
	 * 渲染入口
	 * ===================================================================== */

	/**
	 * 在论坛帖子正文末尾自动追加相关推荐模块。
	 *
	 * @param string $content 正文 HTML。
	 * @return string
	 */
	public function maybe_append_related( $content ) {
		if ( ! $this->is_enabled() ) {
			return $content;
		}
		if ( is_feed() ) {
			return $content;
		}
		if ( ! is_singular( FAS_Forum::post_type() ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$module = $this->render_module( $post_id );
		if ( '' === $module ) {
			return $content;
		}

		return $content . $module;
	}

	/**
	 * 短代码 [zib_related post_id=""]。
	 *
	 * @param array $atts 短代码属性。
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'post_id' => 0, 'count' => 0 ), $atts, 'zib_related' );

		$post_id = (int) $atts['post_id'];
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		if ( ! $post_id || ! FAS_Forum::is_forum_post( get_post_type( $post_id ) ) ) {
			return '';
		}

		return $this->render_module( $post_id, (int) $atts['count'] );
	}

	/**
	 * 渲染相关推荐模块 HTML（带 transient 缓存）。
	 *
	 * @param int $post_id 当前帖子 ID。
	 * @param int $count   覆盖推荐数量（0 表示使用设置值）。
	 * @return string
	 */
	public function render_module( $post_id, $count = 0 ) {
		$limit = $count > 0 ? max( 1, min( 20, $count ) ) : $this->count();

		$cache_key = $this->cache_key( $post_id, $limit );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$ids = $this->get_related_ids( $post_id, $limit );
		if ( empty( $ids ) ) {
			set_transient( $cache_key, '', $this->empty_ttl() );
			return '';
		}

		$html = $this->build_module_html( $ids );
		set_transient( $cache_key, $html, $this->cache_ttl() );

		return $html;
	}

	/**
	 * 一次性取回全部候选帖子并组装模块 HTML（避免逐篇 get_post 的 N+1 查询）。
	 *
	 * @param int[] $ids 推荐帖子 ID 列表。
	 * @return string
	 */
	private function build_module_html( $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return '';
		}

		$posts = get_posts(
			array(
				'post_type'        => FAS_Forum::post_type(),
				'post_status'      => 'publish',
				'post__in'         => $ids,
				'numberposts'      => count( $ids ),
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);

		$map = array();
		foreach ( $posts as $p ) {
			$map[ (int) $p->ID ] = $p;
		}

		$cards = '';
		foreach ( $ids as $rid ) {
			$rid = (int) $rid;

			// 主查询未命中时逐个回退，确保「推荐数量」被完整渲染。
			$post = isset( $map[ $rid ] ) ? $map[ $rid ] : get_post( $rid );
			if ( $post && 'publish' === $post->post_status && FAS_Forum::is_forum_post( $post->post_type ) ) {
				$cards .= $this->render_card( $post );
			}
		}

		if ( '' === $cards ) {
			return '';
		}

		$html  = '<div class="fas-related">';
		$html .= '<div class="fas-related-head">';
		$html .= '<h3 class="fas-related-title">相关推荐</h3>';
		$html .= '</div>';
		$html .= '<button type="button" class="fas-related-arrow fas-related-prev" aria-label="向左滑动">' . $this->chevron( 'left' ) . '</button>';
		$html .= '<button type="button" class="fas-related-arrow fas-related-next" aria-label="向右滑动">' . $this->chevron( 'right' ) . '</button>';
		$html .= '<div class="fas-related-scroll">' . $cards . '</div>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * 生成加粗的左右箭头 SVG。
	 *
	 * @param string $dir left|right。
	 * @return string
	 */
	private function chevron( $dir ) {
		$path = ( 'left' === $dir )
			? '<path d="M12 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>'
			: '<path d="M4 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>';
		return '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">' . $path . '</svg>';
	}

	/**
	 * 渲染单张推荐卡片（链接不带 rel="nofollow"）。
	 *
	 * @param WP_Post $post 帖子对象（已批量加载，避免再次查库）。
	 * @return string
	 */
	private function render_card( $post ) {
		$title = apply_filters( 'fas_post_title', $post->post_title, $post );
		if ( '' === trim( $title ) ) {
			$title = $post->post_title;
		}

		$thumb_url = $this->get_thumb_url( $post );
		if ( '' !== $thumb_url ) {
			// 封面图懒加载：src 放 1×1 透明占位，真实地址存 data-src，由 front.js 用
			// IntersectionObserver 在卡片滚动进可视区时才赋值加载，避免 8 张封面
			// （尤其几百 KB~2MB 的大图）在首屏一次性请求拖慢整页。
			$thumb_html = '<span class="fas-related-thumb"><img class="fas-lazy" data-src="' . esc_url( $thumb_url ) . '" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" alt="' . esc_attr( $title ) . '" loading="lazy" decoding="async"></span>';
		} else {
			$initial    = mb_substr( $title, 0, 1, 'UTF-8' );
			$thumb_html = '<span class="fas-related-thumb fas-related-thumb-empty"><span class="fas-related-initial">' . esc_html( $initial ) . '</span></span>';
		}

		return '<a class="fas-related-card" href="' . esc_url( get_permalink( $post ) ) . '">'
			. $thumb_html
			. '<span class="fas-related-name"><span class="fas-related-name-inner">' . esc_html( $title ) . '</span></span>'
			. '</a>';
	}

	/**
	 * 获取推荐卡片的封面图 URL，按优先级回退：
	 *   1. 正文第一张配图（优先经附件 ID 取 fas-cover 300×300 / thumbnail 缩略图，无缩略图回退原图）；
	 *   2. 特色图片（_thumbnail_id，同样优先缩略图尺寸）；
	 *   3. 主题常用缩略图 meta 兜底；
	 *   4. 默认分享 / 兜底封面图（「基本设置」页配置的 1200×630 默认图）。
	 *
	 * @param WP_Post $post 帖子对象（正文已在内存中，无需再查库）。
	 * @return string 图片 URL，取不到返回空字符串。
	 */
	private function get_thumb_url( $post ) {
		$post_id    = (int) $post->ID;
		$thumb_size = apply_filters( 'fas_related_thumb_size', 'thumbnail' );

		// 1) 正文第一张配图（已加载的正文，免查询）。
		$content = apply_filters( 'fas_post_content', $post->post_content, $post );
		if ( '' !== $content && preg_match_all( '/<img[^>]+>/i', $content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				// 1.1) 关键优化：子比编辑器会在图片上标记附件 ID（data-edit-file-id）。
				//      优先用附件 ID 取「缩略图」而非原图——本地截图原图常达几百 KB~2MB，
				//      8 张卡片原图同载曾导致帖子页 1 分多钟才加载完；缩略图仅几 KB。
				if ( preg_match( '/data-edit-file-id=["\'](\d+)["\']/i', $tag, $fid ) ) {
					$aid = (int) $fid[1];
					// 优先取自定义封面尺寸 fas-cover（300×300，尺寸仍在注册，新上传图自动生成；
					// 存量附件的 metadata 也保留着该变体），再回退主题默认 thumbnail（150×150），
					// 都没有才退回原图。
					foreach ( array( 'fas-cover', $thumb_size ) as $sz ) {
						$thumb = wp_get_attachment_image_url( $aid, $sz );
						if ( $thumb ) {
							return $thumb;
						}
					}
				}

				// 1.2) 无附件 ID、或附件未生成缩略图时，退回原图地址
				//      （data-src / src 等），跳过 data:image 占位图与空地址。
				foreach ( array( 'data-src', 'data-original', 'data-lazy-src', 'src' ) as $attr ) {
					if ( preg_match( '/' . $attr . '=["\']([^"\']+)["\']/i', $tag, $m ) ) {
						$url = trim( (string) $m[1] );
						if ( '' !== $url && 0 !== strpos( $url, 'data:image' ) ) {
							return $url;
						}
					}
				}
			}
		}

		// 2) 特色图片（优先缩略图尺寸）。
		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$url = wp_get_attachment_image_url( $thumb_id, $thumb_size );
			if ( $url ) {
				return $url;
			}
			$url = wp_get_attachment_image_url( $thumb_id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}

		// 3) 主题常用缩略图 meta 兜底。
		foreach ( array( 'zib_thumbnail_url', '_thumbnail_url', 'thumb' ) as $key ) {
			$meta = get_post_meta( $post_id, $key, true );
			if ( is_string( $meta ) && '' !== trim( $meta ) ) {
				return trim( $meta );
			}
		}

		// 四级都没有：返回空串，由调用方渲染首字占位（v2.0.0 起不再有全站默认分享图）。
		return '';
	}

	/* =====================================================================
	 * 关联检索
	 * ===================================================================== */

	/**
	 * 收集相关帖子 ID 列表。
	 *
	 * @param int $post_id 当前帖子 ID。
	 * @param int $limit   目标数量。
	 * @return int[]
	 */
	public function get_related_ids( $post_id, $limit = 8 ) {
		$post_id = (int) $post_id;
		$limit   = max( 1, (int) $limit );
		$mode    = $this->balance_mode();

		// 候选池倍率：均衡模式下放大到 6×，给随机 / 加权留出挑选空间；
		// 关闭均衡时沿用旧的 3× 倍率，保持结果可复现。
		$factor = ( 'off' === $mode ) ? 3 : (int) apply_filters( 'fas_related_pool_factor', 6 );
		$factor = max( 1, min( 20, $factor ) );
		$sample = $limit * $factor;

		// 关键改动：均衡模式下各层候选改为随机采样，而不是「时间倒序取前 N」，
		// 否则候选池被最新帖垄断，老帖永远进不了推荐。
		$orderby = ( 'off' === $mode ) ? 'date' : 'rand';

		$pool = array();

		// 1) 相同标签。
		$term_ids = wp_get_object_terms( $post_id, FAS_Forum::tag_taxonomy(), array( 'fields' => 'ids' ) );
		$term_ids = is_wp_error( $term_ids ) ? array() : array_map( 'intval', $term_ids );
		if ( ! empty( $term_ids ) ) {
			$pool = array_merge( $pool, $this->query_by_tax( $post_id, $term_ids, $sample, $orderby ) );
		}

		// 2) 关键词相似（标题 + AI 标签）。
		if ( count( $pool ) < $limit ) {
			$pool = array_merge( $pool, $this->query_by_keywords( $post_id, $sample, $orderby ) );
		}

		// 3) 同板块兜底。
		if ( count( $pool ) < $limit ) {
			$plate_id = FAS_Forum::get_plate_id( $post_id );
			if ( $plate_id ) {
				$pool = array_merge( $pool, $this->query_by_plate( $post_id, $plate_id, $sample, $orderby ) );
			}
		}

		// 4) 随机 / 最新帖子兜底。
		if ( count( $pool ) < $limit ) {
			$pool = array_merge( $pool, $this->query_recent( $post_id, $sample, $orderby ) );
		}

		// 去重、排除自身。
		$pool = array_values( array_unique( array_filter( array_map( 'intval', $pool ) ) ) );
		$pool = array_values( array_diff( $pool, array( $post_id ) ) );
		if ( empty( $pool ) ) {
			return array();
		}

		// 排序：fair 用「曝光次数」反向加权（越少被推荐越靠前）；其余随机打散。
		if ( 'fair' === $mode ) {
			$ordered = $this->weighted_order( $pool );
		} else {
			shuffle( $pool );
			$ordered = $pool;
		}

		// 选出前 N 篇（可选限制同板块数量，提升板块分散度）。
		$picked = $this->pick_with_plate_cap( $ordered, $limit );

		// 记录曝光（仅均衡模式）：请求内累积，shutdown 时一次批量写库。
		if ( 'off' !== $mode && ! empty( $picked ) ) {
			$this->record_impressions( $picked );
		}

		return $picked;
	}

	/**
	 * 按「曝光次数」做反向加权随机排序（Efraimidis–Spirakis 无放回加权抽样）。
	 *
	 * 每个候选取 key = U^(1/w)，w = 1/(1+曝光次数)，再按 key 降序排列。
	 * 曝光为 0 的帖子权重最高（w=1），曝光越多权重越低，从而让冷门老帖优先入链。
	 *
	 * @param int[] $ids 候选帖子 ID。
	 * @return int[] 已排序的帖子 ID（曝光少的在前）。
	 */
	private function weighted_order( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		// 一次性预载 meta 缓存，避免逐个帖子查库。
		update_meta_cache( 'post', $ids );
		$key = $this->impression_meta_key();

		$scored = array();
		foreach ( $ids as $id ) {
			$imp = max( 0, (int) get_post_meta( $id, $key, true ) );
			$w   = 1.0 / ( 1.0 + $imp );

			// U ∈ [0,1)；U=0 时 pow(0,·)=0，排到最后，不影响正确性。
			$u = mt_rand( 0, 999999 ) / 1000000.0;

			$scored[ $id ] = pow( $u, 1.0 / $w );
		}

		arsort( $scored, SORT_NUMERIC );
		return array_keys( $scored );
	}

	/**
	 * 从有序候选里挑前 N 篇；可选限制「同一板块最多出现几次」。
	 *
	 * 被板块上限挡下的候选会进入补位队列，数量不足时按原顺序补齐，
	 * 保证最终仍能凑满 N 篇。
	 *
	 * @param int[] $ordered 有序候选 ID。
	 * @param int   $limit   目标数量。
	 * @return int[]
	 */
	private function pick_with_plate_cap( $ordered, $limit ) {
		$ordered = array_values( array_unique( array_filter( array_map( 'intval', $ordered ) ) ) );
		$limit   = (int) $limit;
		$cap     = $this->plate_cap();

		if ( $cap <= 0 || count( $ordered ) <= $limit ) {
			return array_slice( $ordered, 0, $limit );
		}

		update_meta_cache( 'post', $ordered );
		$plate_key = FAS_Forum::plate_meta_key();

		$counts = array();
		$picked = array();
		$rest   = array();

		foreach ( $ordered as $id ) {
			if ( count( $picked ) >= $limit ) {
				$rest[] = $id;
				continue;
			}

			$plate = (string) get_post_meta( $id, $plate_key, true );
			$plate = ( '' === $plate ) ? '0' : $plate;

			$used = isset( $counts[ $plate ] ) ? (int) $counts[ $plate ] : 0;
			if ( $used < $cap ) {
				$counts[ $plate ] = $used + 1;
				$picked[]         = $id;
			} else {
				$rest[] = $id;
			}
		}

		// 板块限制导致数量不足时补位。
		if ( count( $picked ) < $limit ) {
			foreach ( $rest as $id ) {
				if ( count( $picked ) >= $limit ) {
					break;
				}
				$picked[] = $id;
			}
		}

		return $picked;
	}

	/* =====================================================================
	 * 曝光统计（内链均衡的数据基础）
	 * ===================================================================== */

	/**
	 * 记录一批帖子的推荐曝光：请求内累积，shutdown 时一次批量写库。
	 *
	 * @param int[] $ids 被推荐出去的帖子 ID。
	 */
	private function record_impressions( $ids ) {
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				self::$pending_imp[ $id ] = true;
			}
		}

		if ( ! self::$imp_scheduled ) {
			self::$imp_scheduled = true;
			// WordPress 在 PHP shutdown 阶段会 do_action('shutdown')，
			// 此时页面已输出完毕，写库不再阻塞用户。
			add_action( 'shutdown', array( $this, 'flush_impressions' ), 99 );
		}
	}

	/**
	 * shutdown 阶段批量写回曝光计数。
	 */
	public function flush_impressions() {
		if ( empty( self::$pending_imp ) ) {
			return;
		}

		$ids               = array_keys( self::$pending_imp );
		self::$pending_imp = array();
		$key               = $this->impression_meta_key();

		foreach ( $ids as $id ) {
			$n = (int) get_post_meta( $id, $key, true );
			update_post_meta( $id, $key, $n + 1 );
		}
	}

	/**
	 * 汇总推荐曝光统计（后台展示用）。
	 *
	 * @return array{posts:int,covered:int,uncovered:int,coverage:float,total_imp:int,avg:float,max:int}
	 */
	public function get_stats() {
		global $wpdb;

		$key = $this->impression_meta_key();
		$pt  = FAS_Forum::post_type();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				$pt
			)
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(CASE WHEN CAST(pm.meta_value AS UNSIGNED) > 0 THEN 1 ELSE 0 END) AS covered,
				        SUM(CAST(pm.meta_value AS UNSIGNED)) AS total_imp,
				        MAX(CAST(pm.meta_value AS UNSIGNED)) AS max_imp
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'",
				$key,
				$pt
			)
		);

		$covered   = $row ? (int) $row->covered : 0;
		$total_imp = $row ? (int) $row->total_imp : 0;
		$max_imp   = $row ? (int) $row->max_imp : 0;

		return array(
			'posts'     => $total,
			'covered'   => $covered,
			'uncovered' => max( 0, $total - $covered ),
			'coverage'  => $total > 0 ? round( $covered / $total * 100, 1 ) : 0.0,
			'total_imp' => $total_imp,
			'avg'       => $covered > 0 ? round( $total_imp / $covered, 1 ) : 0.0,
			'max'       => $max_imp,
		);
	}

	/**
	 * 清空全部推荐曝光统计，并刷新相关推荐缓存。
	 */
	public function reset_impressions() {
		global $wpdb;

		$key = $this->impression_meta_key();
		$pt  = FAS_Forum::post_type();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE pm FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s AND p.post_type = %s",
				$key,
				$pt
			)
		);

		// 版本号自增 → 全部旧的相关推荐 transient 自然失效。
		update_option( 'fas_related_ver', $this->cache_version() + 1 );
	}

	/**
	 * 后台「重置推荐曝光统计」请求处理（admin-post.php）。
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( '权限不足。', 'forum-ai-summary' ) );
		}
		check_admin_referer( 'fas_related_reset' );

		$this->reset_impressions();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => 'fas-settings',
					'fas-related-reset' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* =====================================================================
	 * 候选查询
	 * ===================================================================== */

	/**
	 * 按标签查询相关帖子。
	 *
	 * @param int    $exclude_id 排除的帖子 ID。
	 * @param int[]  $term_ids   标签 term ID。
	 * @param int    $limit      数量上限。
	 * @param string $orderby    排序方式：rand（随机采样）｜date（最新优先）。
	 * @return int[]
	 */
	private function query_by_tax( $exclude_id, $term_ids, $limit, $orderby = 'rand' ) {
		$q = get_posts(
			array(
				'post_type'     => FAS_Forum::post_type(),
				'post_status'   => 'publish',
				'numberposts'   => $limit,
				'fields'        => 'ids',
				'post__not_in'  => array( $exclude_id ),
				'no_found_rows' => true,
				'orderby'       => $orderby,
				'tax_query'     => array(
					array(
						'taxonomy' => FAS_Forum::tag_taxonomy(),
						'field'    => 'term_id',
						'terms'    => $term_ids,
					),
				),
			)
		);
		return is_wp_error( $q ) ? array() : array_map( 'intval', $q );
	}

	/**
	 * 按关键词查询相关帖子（标题 + AI 标签提取核心词，仅对 post_title 做 LIKE，
	 * 不做 post_content 全表扫描，避免大表全文检索拖慢页面）。
	 *
	 * @param int    $exclude_id 排除的帖子 ID。
	 * @param int    $limit      数量上限。
	 * @param string $orderby    rand（随机）｜date（最新优先）。
	 * @return int[]
	 */
	private function query_by_keywords( $exclude_id, $limit, $orderby = 'rand' ) {
		$keywords = $this->extract_keywords( $exclude_id );
		if ( empty( $keywords ) ) {
			return array();
		}

		global $wpdb;
		$likes = array();
		foreach ( $keywords as $k ) {
			$likes[] = $wpdb->prepare( 'post_title LIKE %s', '%' . $wpdb->esc_like( $k ) . '%' );
		}
		$where = implode( ' OR ', $likes );

		// 排序片段为受控字面量，不参与拼接用户输入。
		$order = ( 'rand' === $orderby ) ? 'RAND()' : 'post_date DESC';

		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID != %d AND ({$where}) ORDER BY {$order} LIMIT %d",
			FAS_Forum::post_type(),
			$exclude_id,
			$limit
		);

		$ids = $wpdb->get_col( $sql );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * 按板块查询相关帖子。
	 *
	 * @param int    $exclude_id 排除的帖子 ID。
	 * @param int    $plate_id   板块 post ID。
	 * @param int    $limit      数量上限。
	 * @param string $orderby    排序方式。
	 * @return int[]
	 */
	private function query_by_plate( $exclude_id, $plate_id, $limit, $orderby = 'rand' ) {
		$q = get_posts(
			array(
				'post_type'     => FAS_Forum::post_type(),
				'post_status'   => 'publish',
				'numberposts'   => $limit,
				'fields'        => 'ids',
				'post__not_in'  => array( $exclude_id ),
				'no_found_rows' => true,
				'orderby'       => $orderby,
				'meta_key'      => FAS_Forum::plate_meta_key(),
				'meta_value'    => $plate_id,
			)
		);
		return is_wp_error( $q ) ? array() : array_map( 'intval', $q );
	}

	/**
	 * 兜底查询：随机（均衡模式）或最新帖子。
	 *
	 * @param int    $exclude_id 排除的帖子 ID。
	 * @param int    $limit      数量上限。
	 * @param string $orderby    排序方式。
	 * @return int[]
	 */
	private function query_recent( $exclude_id, $limit, $orderby = 'rand' ) {
		$q = get_posts(
			array(
				'post_type'     => FAS_Forum::post_type(),
				'post_status'   => 'publish',
				'numberposts'   => $limit,
				'fields'        => 'ids',
				'post__not_in'  => array( $exclude_id ),
				'no_found_rows' => true,
				'orderby'       => $orderby,
			)
		);
		return is_wp_error( $q ) ? array() : array_map( 'intval', $q );
	}

	/**
	 * 从标题 + AI 标签提取核心关键词（用于「关键词大致相同」匹配）。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return string[]
	 */
	private function extract_keywords( $post_id ) {
		$title = FAS_Forum::get_title( $post_id );
		$tags  = get_post_meta( $post_id, '_fas_tags', true );
		$text  = $title . ' ' . ( is_array( $tags ) ? implode( ' ', $tags ) : (string) $tags );

		// 去掉英文/数字/版本号，再去掉标点，只保留中文片段。
		$text   = preg_replace( '/[0-9A-Za-z][0-9A-Za-z.\- ]*/u', ' ', $text );
		$text   = preg_replace( '/[【】\[\]（）()《》「」"\'，。、！？：；,.!?:;\-\/\\\\|]+/u', ' ', $text );
		$tokens = array_filter( array_map( 'trim', (array) preg_split( '/\s+/u', trim( $text ) ) ) );

		$stop = array( '软件', '资源', '下载', '免费', '教程', '分享', '最新', '版本', '破解', '绿色', '工具', '推荐', '大全', '论坛', '合集' );

		$keywords = array();
		foreach ( $tokens as $t ) {
			$len = mb_strlen( $t, 'UTF-8' );
			if ( $len < 2 || $len > 12 ) {
				continue;
			}
			if ( in_array( $t, $stop, true ) ) {
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

		return array_slice( $keywords, 0, 3 );
	}
}
