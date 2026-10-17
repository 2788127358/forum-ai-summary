<?php
/**
 * 搜索摘要优化（标签页 / 板块页 description 补全）
 *
 * 解决子比主题标签归档页与板块页的 meta description 质量低的问题：
 *
 * 1. 标签页：子比主题在 zib_description() 的 is_tax/is_tag 分支，优先读
 *    term meta `zib_other_data['term_seo']['description']`，空则回退原生 term description。
 *    发帖时随手创建的标签只填了半句话（约 15 字），本类调用 AI 把标签 description
 *    补全为 60~120 字，按「是什么 / 包含哪些 / 怎么选」结构撰写，同时写入子比 term_seo
 *    字段与原生 term description（双写，保证生效并兼容其他 SEO 插件）。
 *
 * 2. 板块页：板块（plate post type）是 singular，其 description 走 zib_description()
 *    的 is_singular 分支，读 post meta `zib_other_data['description']`。本类对不足 60 字的
 *    板块调用 AI 补全到 60~120 字，写入同一个 post meta key。
 *
 * 后台新增「搜索摘要」子菜单，提供标签/板块列表、一键批量补全与单篇重新生成。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Seo_Description {

	/** 每批处理数量（AI 生成较慢，逐条由前端循环）。 */
	const BATCH_SIZE = 1;

	/** 标签描述批量生成：一次 API 调用同时生成几个标签的描述。 */
	const TERM_BATCH_SIZE = 5;

	/** 达标最小字数（不足则需补全）。 */
	const MIN_LEN = 60;

	/** 描述最大字数（超出截断）。 */
	const MAX_LEN = 120;

	/** 取作 AI 素材的关联帖子数量上限。 */
	const SAMPLE_POSTS = 10;

	/** @var FAS_Seo_Description */
	private static $instance = null;

	/**
	 * 本次请求内「真正由 created_term 新建出来」的标签 term_id 集合。
	 *
	 * 用于把 created_term 与 edited_term 区分开：WordPress 的两个钩子形参完全一致，
	 * 唯一的可靠区分方式就是记录本次请求里哪些词是新建的。
	 *
	 * @var array term_id => true
	 */
	private static $created_terms = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 优先级 14：菜单分组「标签与 SEO」的第二项，紧随「标签管理」。

		// 自动补全：发帖后 / 新建标签后，延迟调度为新标签生成描述。
		add_action( 'wp_insert_post', array( $this, 'maybe_schedule_on_post' ), 30, 3 );
		add_action( 'created_term', array( $this, 'maybe_schedule_on_created_term' ), 10, 3 );
		// 标签被编辑（rename / 改 slug / 改描述）不得触发自动补全：created_term 与
		// edited_term 都挂同一回调（WordPress 两个钩子的前三个形参完全一致），
		// 由回调内的「是否本次请求新建的词」判定区分。
		add_action( 'edited_term', array( $this, 'maybe_schedule_on_created_term' ), 10, 3 );
		add_action( 'fas_desc_auto_event', array( $this, 'handle_auto_event' ), 10, 2 );
	}

	/* =====================================================================
	 * 标签（forum_tag term）description
	 * ===================================================================== */

	/**
	 * 读取标签当前「生效」的 SEO 描述：优先子比 term_seo 字段，空则原生 term description。
	 *
	 * @param int $term_id 标签 term ID。
	 * @return string
	 */
	public static function get_term_description( $term_id ) {
		$data = get_term_meta( $term_id, 'zib_other_data', true );
		$desc = '';
		if ( is_array( $data ) && isset( $data['term_seo']['description'] ) ) {
			$desc = trim( (string) $data['term_seo']['description'] );
		}
		if ( '' === $desc ) {
			$term = get_term( $term_id );
			if ( $term && ! is_wp_error( $term ) ) {
				$desc = trim( (string) $term->description );
			}
		}
		return $desc;
	}

	/**
	 * 将 AI 生成的描述写入标签：双写子比 term_seo 字段与原生 term description。
	 *
	 * @param int    $term_id 标签 term ID。
	 * @param string $desc    描述内容。
	 * @return void
	 */
	public static function set_term_description( $term_id, $desc ) {
		// 1) 子比主题优先读取的 term_seo 字段。
		$data = get_term_meta( $term_id, 'zib_other_data', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! isset( $data['term_seo'] ) || ! is_array( $data['term_seo'] ) ) {
			$data['term_seo'] = array();
		}
		$data['term_seo']['description'] = $desc;
		update_term_meta( $term_id, 'zib_other_data', $data );

		// 2) 原生 term description（兜底 + 兼容其他 SEO 插件/搜索引擎）。
		$term = get_term( $term_id );
		if ( $term && ! is_wp_error( $term ) ) {
			wp_update_term(
				$term_id,
				$term->taxonomy,
				array( 'description' => $desc )
			);
		}
	}

	/**
	 * 获取标签下若干帖子标题作为 AI 归纳素材。
	 *
	 * @param int $term_id 标签 term ID。
	 * @return string[]
	 */
	public static function get_term_sample_titles( $term_id ) {
		$posts = get_posts(
			array(
				'post_type'      => FAS_Forum::post_type(),
				'post_status'    => 'publish',
				'numberposts'    => self::SAMPLE_POSTS,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => FAS_Forum::tag_taxonomy(),
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);
		$titles = array();
		foreach ( (array) $posts as $pid ) {
			$t = FAS_Forum::get_title( $pid );
			if ( '' !== trim( $t ) ) {
				$titles[] = $t;
			}
		}
		return $titles;
	}

	/**
	 * 为单个标签生成并写入 AI 描述。
	 *
	 * @param int  $term_id 标签 term ID。
	 * @param bool $force   是否强制重新生成（忽略已达标判断）。
	 * @return array {changed, code, message, description}
	 */
	public static function generate_term_desc( $term_id, $force = false ) {
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) ) {
			return array( 'changed' => false, 'code' => 'skip', 'message' => '标签不存在' );
		}

		$name    = $term->name;
		$current = self::get_term_description( $term_id );
		$len     = mb_strlen( $current, 'UTF-8' );

		if ( ! $force && $len >= self::MIN_LEN ) {
			return array( 'changed' => false, 'code' => 'already', 'message' => '已达 ' . self::MIN_LEN . ' 字，跳过' );
		}

		$titles = self::get_term_sample_titles( $term_id );

		// 生成（可重试错误自动重试最多 3 次）。
		$desc       = '';
		$last_error = '';
		$max_attempts = 3;
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			try {
				$api  = new FAS_Api();
				$desc = $api->generate_term_description( $name, $titles );
				if ( '' !== trim( $desc ) ) {
					break;
				}
				$last_error = '模型返回空内容';
			} catch ( FAS_Exception $e ) {
				$last_error = $e->getMessage();
				if ( ! $e->retryable ) {
					break; // 不可重试（鉴权/参数错误等），不再重试。
				}
				if ( $attempt < $max_attempts ) {
					usleep( 300000 ); // 0.3 秒后再试，缓解限流/抖动。
				}
			}
		}

		if ( '' === trim( $desc ) ) {
			return array( 'changed' => false, 'code' => 'error', 'message' => $last_error ?: '生成失败' );
		}

		$desc = $api->normalize_description( $desc );

		// 长度校验：过短视为失败（避免把无效内容写入并误判为成功）。
		$len = mb_strlen( $desc, 'UTF-8' );
		if ( $len < self::MIN_LEN ) {
			return array( 'changed' => false, 'code' => 'error', 'message' => '生成结果过短（' . $len . ' 字）' );
		}

		self::set_term_description( $term_id, $desc );
		update_term_meta( $term_id, '_fas_desc_generated_at', time() );

		return array(
			'changed'     => true,
			'code'        => 'ok',
			'message'     => '已生成（' . $len . ' 字）',
			'description' => $desc,
		);
	}

	/**
	 * 批量生成多个标签的描述（一次 API 调用），并逐个写入。
	 *
	 * @param int[] $term_ids 标签 term ID 列表。
	 * @param bool  $force    是否强制重新生成。
	 * @return array {changed, failed, skipped}
	 */
	public static function generate_term_descs_batch( $term_ids, $force = false ) {
		$terms = array();
		foreach ( $term_ids as $tid ) {
			$term = get_term( $tid );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			// 已达标且非强制则跳过。
			if ( ! $force && mb_strlen( self::get_term_description( $tid ), 'UTF-8' ) >= self::MIN_LEN ) {
				continue;
			}
			$terms[] = array(
				'term_id' => (int) $tid,
				'name'    => $term->name,
				'titles'  => self::get_term_sample_titles( $tid ),
			);
		}

		if ( empty( $terms ) ) {
			return array( 'changed' => 0, 'failed' => 0, 'skipped' => count( $term_ids ) );
		}

		// 批量调用 AI（可重试错误自动重试最多 3 次）。
		$desc_map     = array();
		$last_error   = '';
		$max_attempts = 3;
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			try {
				$api      = new FAS_Api();
				$desc_map = $api->generate_term_descriptions_batch( $terms );
				if ( ! empty( $desc_map ) ) {
					break;
				}
				$last_error = '模型返回空结果';
			} catch ( FAS_Exception $e ) {
				$last_error = $e->getMessage();
				if ( ! $e->retryable ) {
					break;
				}
				if ( $attempt < $max_attempts ) {
					usleep( 300000 );
				}
			}
		}

		// 批量整体失败：降级为逐个生成，保证功能可用。
		if ( empty( $desc_map ) ) {
			$changed = 0;
			$failed  = 0;
			foreach ( $terms as $t ) {
				$r = self::generate_term_desc( $t['term_id'], $force );
				if ( $r['changed'] ) {
					$changed++;
				} elseif ( 'error' === $r['code'] ) {
					$failed++;
				}
			}
			return array( 'changed' => $changed, 'failed' => $failed, 'skipped' => 0 );
		}

		// 逐个写入（按标签名匹配 AI 返回结果）。
		$changed = 0;
		$failed  = 0;
		foreach ( $terms as $t ) {
			$desc = isset( $desc_map[ $t['name'] ] ) ? trim( (string) $desc_map[ $t['name'] ] ) : '';

			// 该标签未返回描述：降级单条生成。
			if ( '' === $desc ) {
				$r = self::generate_term_desc( $t['term_id'], $force );
				if ( $r['changed'] ) {
					$changed++;
				} elseif ( 'error' === $r['code'] ) {
					$failed++;
				}
				continue;
			}

			$api  = new FAS_Api();
			$desc = $api->normalize_description( $desc, self::MAX_LEN );
			$len  = mb_strlen( $desc, 'UTF-8' );
			if ( $len < self::MIN_LEN ) {
				$failed++;
				continue;
			}

			self::set_term_description( $t['term_id'], $desc );
			update_term_meta( $t['term_id'], '_fas_desc_generated_at', time() );
			$changed++;
		}

		return array( 'changed' => $changed, 'failed' => $failed, 'skipped' => 0 );
	}



	/**
	 * 获取全部待补全标签 term ID（description 不足 MIN_LEN 字）。
	 *
	 * （get_short_term_ids 已随 v1.41.0 后台页面移除）
	 */

	/* =====================================================================
	 * 板块（plate post type）description
	 * ===================================================================== */

	/**
	 * 读取板块当前 SEO 描述（post meta zib_other_data['description']）。
	 *
	 * @param int $plate_id 板块 post ID。
	 * @return string
	 */
	public static function get_plate_description( $plate_id ) {
		$data = get_post_meta( $plate_id, 'zib_other_data', true );
		if ( is_array( $data ) && isset( $data['description'] ) ) {
			return trim( (string) $data['description'] );
		}
		return '';
	}

	/**
	 * 写入板块 SEO 描述（post meta zib_other_data['description']，保留其他字段）。
	 *
	 * @param int    $plate_id 板块 post ID。
	 * @param string $desc     描述内容。
	 * @return void
	 */
	public static function set_plate_description( $plate_id, $desc ) {
		$data = get_post_meta( $plate_id, 'zib_other_data', true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data['description'] = $desc;
		update_post_meta( $plate_id, 'zib_other_data', $data );
		wp_cache_delete( 'post_meta_' . (int) $plate_id, 'zib_option_meta_data' );
	}

	/**
	 * 获取板块下若干帖子标题作为 AI 归纳素材。
	 *
	 * @param int $plate_id 板块 post ID。
	 * @return string[]
	 */
	public static function get_plate_sample_titles( $plate_id ) {
		$titles = array();
		$posts  = get_posts(
			array(
				'post_type'      => FAS_Forum::post_type(),
				'post_status'    => 'publish',
				'numberposts'    => self::SAMPLE_POSTS,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => FAS_Forum::plate_meta_key(),
				'meta_value'     => $plate_id,
			)
		);
		foreach ( (array) $posts as $pid ) {
			$t = FAS_Forum::get_title( $pid );
			if ( '' !== trim( $t ) ) {
				$titles[] = $t;
			}
		}
		return $titles;
	}

	/**
	 * 为单个板块生成并写入 AI 描述。
	 *
	 * @param int  $plate_id 板块 post ID。
	 * @param bool $force    是否强制重新生成。
	 * @return array {changed, code, message, description}
	 */
	public static function generate_plate_desc( $plate_id, $force = false ) {
		$plate = get_post( $plate_id );
		if ( ! $plate || ! FAS_Forum::is_plate( $plate->post_type ) ) {
			return array( 'changed' => false, 'code' => 'skip', 'message' => '板块不存在' );
		}

		$name    = get_the_title( $plate_id );
		$current = self::get_plate_description( $plate_id );
		$len     = mb_strlen( $current, 'UTF-8' );

		if ( ! $force && $len >= self::MIN_LEN ) {
			return array( 'changed' => false, 'code' => 'already', 'message' => '已达 ' . self::MIN_LEN . ' 字，跳过' );
		}

		$titles  = self::get_plate_sample_titles( $plate_id );
		$summary = trim( wp_strip_all_tags( (string) $plate->post_content ) );

		// 生成（可重试错误自动重试最多 3 次）。
		$desc       = '';
		$last_error = '';
		$max_attempts = 3;
		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			try {
				$api  = new FAS_Api();
				$desc = $api->generate_plate_description( $name, $titles, $summary );
				if ( '' !== trim( $desc ) ) {
					break;
				}
				$last_error = '模型返回空内容';
			} catch ( FAS_Exception $e ) {
				$last_error = $e->getMessage();
				if ( ! $e->retryable ) {
					break;
				}
				if ( $attempt < $max_attempts ) {
					usleep( 300000 );
				}
			}
		}

		if ( '' === trim( $desc ) ) {
			return array( 'changed' => false, 'code' => 'error', 'message' => $last_error ?: '生成失败' );
		}

		$desc = $api->normalize_description( $desc );

		// 长度校验：过短视为失败。
		$len = mb_strlen( $desc, 'UTF-8' );
		if ( $len < self::MIN_LEN ) {
			return array( 'changed' => false, 'code' => 'error', 'message' => '生成结果过短（' . $len . ' 字）' );
		}

		self::set_plate_description( $plate_id, $desc );
		update_post_meta( $plate_id, '_fas_plate_desc_generated_at', time() );

		return array(
			'changed'     => true,
			'code'        => 'ok',
			'message'     => '已生成（' . $len . ' 字）',
			'description' => $desc,
		);
	}



	/* =====================================================================
	 * 自动补全（发帖后 / 新建标签后）
	 * ===================================================================== */

	/**
	 * 发帖保存/发布后，延迟调度为新关联的标签补全描述。
	 *
	 * ⚠️ 本回调挂在 `wp_insert_post` 上，**但只看新状态、不读旧状态**，因此不受
	 * 「`wp_insert_post` 动作在写库之后才触发、旧状态已不可得」这一时序陷阱影响
	 * （该陷阱正是 v1.37.0 摘要链路失效的原因，见 `FAS_Hooks::on_transition_post_status()`）。
	 * 代价是每次保存已发布帖都会调度一次事件，但真正决定生成与否的是
	 * `handle_auto_event()` 内的二次校验（描述不足 60 字、`_fas_desc_generated_at`
	 * 为空、至少关联 1 篇已发布帖），既不会重复生成，也不会覆盖人工描述。
	 * **勿「顺手」改成捕获旧状态的写法**，也勿改为 `transition_post_status`：
	 * 此处语义是「发布后补全该帖关联的标签描述」，本就与「首次发布」无关。
	 *
	 * @param int     $post_id 帖子 ID。
	 * @param WP_Post $post    帖子对象。
	 * @param bool    $update  是否为更新。
	 */
	public function maybe_schedule_on_post( $post_id, $post, $update ) {
		if ( ! FAS_Forum::is_forum_post( $post->post_type ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		$options = FAS_Options::get();
		if ( empty( $options['enabled'] ) ) {
			return;
		}
		// 延迟 8 秒：等待标签关联建立（发帖流程中标签在帖子保存后才写入）。
		wp_schedule_single_event( time() + 8, 'fas_desc_auto_event', array( 'post', $post_id ) );
	}

	/**
	 * 新建标签后（手动或程序创建 forum_tag），延迟调度补全描述。
	 *
	 * v1.37.0 加固：
	 *   - 该回调同时挂在 created_term 与 edited_term 上。**编辑标签不得触发**补全，
	 *     因此先把 term_id 标记为「本次请求新建」，只有真正来自 created_term 的词
	 *     才允许调度（term_id 不会被 WordPress 复用，这个标记是可靠的）。
	 *   - 创建时已带人工描述（长度达标）的词不调度、也不会在事件里被覆盖。
	 *
	 * @param int    $term_id  新建的 term ID。
	 * @param int    $tt_id    term_taxonomy ID。
	 * @param string $taxonomy 分类法。
	 */
	public function maybe_schedule_on_created_term( $term_id, $tt_id, $taxonomy ) {
		unset( $tt_id );

		if ( $taxonomy !== FAS_Forum::tag_taxonomy() ) {
			return;
		}

		$term_id = (int) $term_id;

		// created_term 优先（同一请求里两者都可能触发，created 先发生）。
		if ( 'created_term' === current_filter() ) {
			self::$created_terms[ $term_id ] = true;
		}
		if ( empty( self::$created_terms[ $term_id ] ) ) {
			return; // 标签被编辑（重命名 / 改 slug / 改描述），不触发自动补全。
		}

		$options = FAS_Options::get();
		if ( empty( $options['enabled'] ) ) {
			return;
		}
		// 已有人工描述（达标）的不调度。
		if ( mb_strlen( self::get_term_description( $term_id ), 'UTF-8' ) >= self::MIN_LEN ) {
			return;
		}
		wp_schedule_single_event( time() + 8, 'fas_desc_auto_event', array( 'term', $term_id ) );
	}

	/**
	 * 标签是否在延迟事件执行时仍应被自动补全。
	 *
	 * v1.37.0 新增的二次校验，全部通过才生成：
	 *   ① 该 term 仍存在、且仍属于论坛标签分类法（可能已被删除 / 合并）；
	 *   ② 该 term 当前仍**没有人工描述**（长度低于达标线）；
	 *   ③ `_fas_desc_generated_at` 为空 —— 说明从未由本插件自动生成过
	 *      （既保证幂等，也保证不会覆盖别人后来的人工描述）；
	 *   ④ 至少关联 1 篇**已发布**的帖子 —— 无帖子的空标签不做描述，
	 *      避免为「发帖时手滑新建、随即弃用」的标签生成低质归档页文案。
	 *
	 * @param int $term_id 标签 term ID。
	 * @return bool
	 */
	public static function should_autofill_term( $term_id ) {
		$term_id = (int) $term_id;
		if ( $term_id <= 0 || ! FAS_Forum::tag_taxonomy_exists() ) {
			return false;
		}

		// ① 仍存在且分类法正确。
		$term = get_term( $term_id );
		if ( ! $term || is_wp_error( $term ) || $term->taxonomy !== FAS_Forum::tag_taxonomy() ) {
			return false;
		}

		// ② 已有人工描述 / 已达标 → 绝不覆盖。
		if ( mb_strlen( self::get_term_description( $term_id ), 'UTF-8' ) >= self::MIN_LEN ) {
			return false;
		}

		// ③ 插件从未自动生成过（幂等 + 不覆盖后加的人工描述）。
		if ( '' !== (string) get_term_meta( $term_id, '_fas_desc_generated_at', true ) ) {
			return false;
		}

		// ④ 至少关联 1 篇已发布帖子。
		return self::term_has_published_post( $term_id );
	}

	/**
	 * 该标签是否至少关联 1 篇已发布的论坛帖子。
	 *
	 * @param int $term_id 标签 term ID。
	 * @return bool
	 */
	private static function term_has_published_post( $term_id ) {
		$objects = get_objects_in_term( (int) $term_id, FAS_Forum::tag_taxonomy() );
		if ( is_wp_error( $objects ) || empty( $objects ) ) {
			return false;
		}
		foreach ( $objects as $oid ) {
			if ( 'publish' === get_post_status( (int) $oid ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 自动补全事件处理：收集待补全标签，逐个生成描述。
	 *
	 * 事件执行时对每个候选标签做二次校验（见 should_autofill_term()）：
	 * 已达标 / 已有人工描述 / 已被插件生成过 / 无已发布帖子的标签一律跳过。
	 * 注意：**被跳过不等于已达标**，因此仍需按「先过滤、再分批」的顺序，
	 * 避免把大批无需补全的标签算进每批配额而把真正待补全的饿死。
	 *
	 * @param string    $type 'post'（按帖子查标签）/ 'term'（单个标签）/ 'terms'（标签 ID 数组）。
	 * @param int|int[] $id   帖子 ID / 标签 ID / 标签 ID 数组。
	 */
	public function handle_auto_event( $type, $id ) {
		$term_ids = array();

		if ( 'post' === $type ) {
			$terms    = wp_get_object_terms( (int) $id, FAS_Forum::tag_taxonomy(), array( 'fields' => 'ids' ) );
			$term_ids = is_wp_error( $terms ) ? array() : array_map( 'intval', $terms );
		} elseif ( 'term' === $type ) {
			$term_ids = array( (int) $id );
		} else { // 'terms'
			$term_ids = array_map( 'intval', (array) $id );
		}

		$term_ids = array_filter( array_unique( $term_ids ) );

		// 过滤出「仍待补全」的标签：描述不足 + 无人工描述 + 未自动生成过 + 有已发布帖。
		$pending = array();
		foreach ( $term_ids as $tid ) {
			if ( self::should_autofill_term( $tid ) ) {
				$pending[] = (int) $tid;
			}
		}

		if ( empty( $pending ) ) {
			return;
		}

		// 每批最多批量生成 TERM_BATCH_SIZE 个（一次 API 调用），剩余重新调度，避免 cron 超时。
		$batch = array_slice( $pending, 0, self::TERM_BATCH_SIZE );
		$rest  = array_slice( $pending, self::TERM_BATCH_SIZE );

		self::generate_term_descs_batch( $batch, false );

		if ( ! empty( $rest ) ) {
			wp_schedule_single_event( time() + 15, 'fas_desc_auto_event', array( 'terms', $rest ) );
		}
	}
}
