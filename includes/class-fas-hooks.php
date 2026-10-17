<?php
/**
 * 事件监听与定时任务
 *
 * 监听子比论坛帖子发布/更新，延迟调度生成任务；注册 cron 处理函数；
 * 提供前端摘要展示（短代码 + 可选自动注入）。
 *
 * v1.37.2 起，自动生成的主判据是**数据判据**而不是状态转换：
 *
 *   ① 接缝仍是 `transition_post_status`（WP 核心每次写入都会触发，且早于
 *      `save_post` / `wp_insert_post` 动作），但只看 `$new_status`：只对
 *      「已发布（publish）」的帖子工作，草稿 / 待审 / 回收站一律不介入。
 *   ② 真正决定要不要跑的是 `needs_generation()`：**缺摘要就补摘要、缺标签就补标签**，
 *      两者齐备就什么都不做。于是「全新帖」「草稿转发布」「已发布帖被再次保存」
 *      走的是同一条判据，不再需要区分「是不是首次发布」。
 *   ③ 这条判据天然自愈：v1.37.0 坏掉期间发布的存量缺摘要帖，只要被编辑保存
 *      一次就会自动补上，无需逐篇点「批量生成」。
 *   ④ **反例（v1.37.0 的 BUG，勿再踩）**：`wp_insert_post` 动作在数据库写入**之后**、
 *      且 `clean_post_cache()` 之后才触发，此时 `get_post()` 读到的已经是新行，
 *      在钩子里怎么捕获都拿不到「保存前状态」。因此本类**不依赖任何旧状态**，
 *      只读落库后的 meta 结果——这也是为什么这条判据不会重蹈覆辙。
 *   ⑤ **反例（v1.37.1 的盲区，勿再踩）**：只用「非 publish → publish」判据时，
 *      同一次请求里的第二次写入（换封面 / 主题补字段）以及「上次生成失败的帖」
 *      都不会再排队；配合 `_fas_auto_hash` 幂等锚，只要事件跑之前内容又变过，
 *      事件就会空转放弃。v1.37.2 删除了该幂等锚，改由 `needs_generation()`
 *      在调度点与事件执行点各校验一次。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Hooks {

	/** @var FAS_Hooks */
	private static $instance = null;

	/** 延迟生成事件名（摘要 + 标签）。 */
	const EVENT_GENERATE = 'fas_generate_event';

	/** 延迟内链事件名：与摘要事件**完全独立**，互不影响、各自成败互不牵连。 */
	const EVENT_ILINK = 'fas_ilink_auto_event';

	/** 发布后延迟秒数（等标签关联等后续写入完成）。 */
	const DELAY_GENERATE = 5;

	/** 发布后延迟秒数（内链在摘要之后再跑，避免同时打满 API）。 */
	const DELAY_ILINK = 10;

	/** v2.0.0：帖子标题改动后重新整理 AI SEO 的事件名。 */
	const EVENT_RETITLE = 'fas_seo_retitle_event';

	/** 标题改动后的延迟秒数（等编辑器保存后的其它写入完成）。 */
	const DELAY_RETITLE = 10;

	/**
	 * 单例。
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 主判据：核心每次写入都会触发 transition_post_status（早于 save_post /
		// wp_insert_post），本类只看 $new_status 是否为 publish，再看数据缺什么。
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );

		// cron 处理函数。
		add_action( self::EVENT_GENERATE, array( $this, 'handle_generate_event' ), 10, 1 );
		add_action( self::EVENT_ILINK, array( $this, 'handle_ilink_auto_event' ), 10, 1 );
		add_action( 'fas_retry_event', array( $this, 'handle_retry_event' ) );

		// v2.0.0：帖子标题被改动后重新整理 AI SEO。
		// transition_post_status 只在「状态变化」时触发，编辑已发布帖改标题不会走那条链路，
		// 所以这里单独接 post_updated（能拿到改前/改后两份 post，判断标题是否真的变了）。
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( self::EVENT_RETITLE, array( $this, 'handle_retitle_event' ), 10, 1 );

		// 前端摘要展示：短代码 + 可选自动注入 + 样式。
		add_shortcode( 'zib_ai_summary', array( $this, 'render_summary_shortcode' ) );
		add_filter( 'the_content', array( $this, 'maybe_prepend_summary' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_front_assets' ) );
	}

	/**
	 * 前端资源：开启摘要展示时加载样式。
	 */
	public function enqueue_front_assets() {
		if ( ! empty( FAS_Options::get_value( 'show_in_content', 0 ) ) && is_singular( FAS_Forum::post_type() ) ) {
			wp_enqueue_style( 'fas-front', FAS_PLUGIN_URL . 'assets/css/front.css', array(), FAS_VERSION );
		}
	}

	/* =====================================================================
	 * 保存期判定
	 * ===================================================================== */

	/**
	 * 判断「本次请求是否属于插件不该介入的写入」。
	 *
	 * 排除：FAS_Generator 自身写回、自动保存（DOING_AUTOSAVE）、revision / autosave 行。
	 *
	 * 注意：**不排除 REST 写入**。旧版一刀切排除 REST_REQUEST，理由是「REST 下拿不到
	 * 旧状态」——那是建立在 `wp_insert_post` 钩子（写库之后）的旧实现上的错误假设。
	 * 现在判据只看落库后的 meta 结果（见 needs_generation()），与「旧状态」无关，
	 * 编辑器经 REST 保存草稿不会进入 publish 分支，真发布则应当被处理，不该被吞掉。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return bool
	 */
	private function should_ignore_write( $post_id ) {
		// 写回过程中的自我触发，防递归。
		if ( FAS_Generator::$is_writing ) {
			return true;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return true;
		}
		return false;
	}

	/**
	 * 数据判据：这篇帖子**还需要**自动生成吗？
	 *
	 *   * 摘要功能开着、`_fas_summary` 为空 → 需要（补摘要）。
	 *   * 标签功能开着、`_fas_tags` 为空 → 需要（补标签）。
	 *   * 两者齐备 → 不需要，一次 API 都不打。
	 *
	 * 失败保护（用户决策）：`_fas_status === 'failed'` 的帖子不再走自动链路，
	 * 避免反复烧 API；延迟重试机制（fas_retry_event）与后台手动重跑入口不受影响。
	 *
	 * 单独抽出来是为了让「主判据」可以被单元级验证（行为测试脚本按同一算法移植）。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return bool
	 */
	private function needs_generation( $post_id ) {
		$options = FAS_Options::get();

		// 上次生成是硬失败（鉴权 / 参数错误等不可重试错误）→ 不再自动重试。
		$status = (string) get_post_meta( $post_id, '_fas_status', true );
		if ( 'failed' === $status ) {
			return false;
		}

		if ( ! empty( $options['summary_enabled'] )
			&& '' === trim( (string) get_post_meta( $post_id, '_fas_summary', true ) ) ) {
			return true;
		}

		if ( ! empty( $options['tags_enabled'] ) ) {
			$tags = get_post_meta( $post_id, '_fas_tags', true );
			if ( empty( $tags ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 状态转换监听（主判据 + 延迟调度）。
	 *
	 * 挂在 `transition_post_status`（WP 核心 post.php 的 wp_transition_post_status()）。
	 * 该钩子**每一次写入都会触发**（含 publish → publish），在 save_post /
	 * wp_insert_post 动作之前，且此时正文已写入数据库，因此可直接读 meta 判据。
	 *
	 * 判据分两层：
	 *   ① 只处理「新状态是 publish」的帖子（草稿 / 待审 / 回收站不介入）；
	 *   ② `needs_generation()` —— 缺摘要 / 缺标签才排队，齐备则一次 API 都不打。
	 *
	 * 注意：本判定**只存在于钩子层**。批量页与单条手动入口直接调用
	 * FAS_Generator::generate_for_post()，不经过这里，因此不受新判据影响。
	 *
	 * @param string  $new_status 转换后的状态。
	 * @param string  $old_status 转换前的状态（v1.37.2 起不再使用，保留形参以匹配钩子签名）。
	 * @param WP_Post $post       帖子对象。
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! FAS_Forum::is_forum_post( $post->post_type ) ) {
			return;
		}

		$post_id = (int) $post->ID;

		if ( $this->should_ignore_write( $post_id ) ) {
			return;
		}

		// ① 只对「已发布」的帖子自动生成；草稿 / 待审 / 回收站不介入。
		//    注意：$old_status 有意不使用——「是不是首次发布」不再是判据。
		if ( 'publish' !== (string) $new_status ) {
			return;
		}

		$options = FAS_Options::get();
		if ( empty( $options['enabled'] ) || empty( $options['auto_on_publish'] ) ) {
			return;
		}
		// API 未配置完整时不空跑（与批量入口一致）。
		if ( ! FAS_Options::is_configured() ) {
			return;
		}

		// ② 数据判据：有摘要就不生成摘要，没有就生成；标签同理。
		if ( ! $this->needs_generation( $post_id ) ) {
			return;
		}

		// 延迟调度，避免阻塞保存流程；同一帖已有待跑事件时不重复排队。
		if ( ! wp_next_scheduled( self::EVENT_GENERATE, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + self::DELAY_GENERATE, self::EVENT_GENERATE, array( $post_id ) );
		}
	}

	/* =====================================================================
	 * 延迟事件处理
	 * ===================================================================== */

	/**
	 * 生成事件处理：摘要 + 标签，首次生成时一并跑 SEO 标题链路与内链事件。
	 *
	 * 二次校验（防止 5 秒窗口内状态变化 / 反复编辑造成重复生成）：
	 *   ① 帖子仍存在、仍是论坛帖子、**仍是 publish**；
	 *   ② 开关仍开启、API 仍配置完整；
	 *   ③ 数据判据仍成立：这 5 秒里摘要 / 标签可能已被手动入口、批量页或
	 *      上一次事件补齐，齐备则直接跳过，一次 API 都不打。
	 *
	 * 注意（v1.37.2）：旧的 `_fas_auto_hash` 幂等锚已删除。它记的是「调度那一刻」
	 * 的内容指纹，只要帖子在事件真正跑起来之前又被写过一次，事件就会走进
	 * 「内容又变了」分支**放弃生成**——这正是「帖子没有摘要」的残留故障机制。
	 * 现在改由 ③ 的 needs_generation() 判定，内容变没变都不影响「缺就补」。
	 *
	 * @param int $post_id 帖子 ID。
	 */
	public function handle_generate_event( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! FAS_Forum::is_forum_post( $post->post_type ) || 'publish' !== $post->post_status ) {
			return;
		}

		$options = FAS_Options::get();
		if ( empty( $options['enabled'] ) || empty( $options['auto_on_publish'] ) || ! FAS_Options::is_configured() ) {
			return;
		}

		// 二次校验：摘要与标签都已齐备（或上次是硬失败）→ 不再调 API。
		if ( ! $this->needs_generation( $post_id ) ) {
			return;
		}

		// 摘要 + 标签（缺什么补什么，generate_for_post() 内部按同一判据分流）。
		FAS_Generator::instance()->generate_for_post( $post_id, false );

		// 一并跑「AI SEO 标题」链路。
		$this->maybe_generate_seo( $post_id );

		// 调度独立的正文内链事件（默认关闭时完全不动）。
		$this->maybe_schedule_ilink( $post_id );
	}

	/**
	 * 失败重试事件处理。
	 */
	public function handle_retry_event( $post_id ) {
		FAS_Generator::instance()->generate_for_post( $post_id, false );
	}

	/**
	 * 首次生成时一并生成 AI SEO 三要素（写入子比「独立SEO」字段）。
	 *
	 * 失败不影响摘要/标签（各自独立），仅记录状态。
	 *
	 * @param int $post_id 帖子 ID。
	 */
	private function maybe_generate_seo( $post_id ) {
		if ( ! class_exists( 'FAS_Title' ) || ! method_exists( 'FAS_Title', 'apply_title' ) ) {
			return;
		}
		// 已生成过 SEO 的帖子不重复调用（除非用户显式开启「覆盖已存在」）。
		if ( '' !== (string) get_post_meta( $post_id, '_fas_seo_generated_at', true )
			&& empty( FAS_Options::get_value( 'overwrite_existing', 0 ) ) ) {
			return;
		}

		$r = FAS_Title::apply_title( $post_id );
		if ( is_array( $r ) && isset( $r['code'] ) && 'ok' === $r['code'] ) {
			update_post_meta( $post_id, '_fas_seo_status', 'success' );
		} elseif ( is_array( $r ) && isset( $r['message'] ) ) {
			update_post_meta( $post_id, '_fas_seo_status', (string) $r['code'] );
			update_post_meta( $post_id, '_fas_seo_error', (string) $r['message'] );
		}
	}

	/**
	 * 帖子保存后：标题变了就安排一次 AI SEO 重新整理（v2.0.0）。
	 *
	 * 典型场景：帖子标题里的版本号从「2.3.12」改成「2.3.18」，SEO 标题/关键词/描述
	 * 里还留着旧版本号。这里只做「检测 + 排队」，真正的生成放在延迟事件里，
	 * 避免编辑器保存时同步打 API 拖慢页面。
	 *
	 * 触发条件（全部满足）：
	 *   1. 论坛帖子且仍是已发布状态；
	 *   2. 标题确实变了（trim 后比较，避免只改空格也重跑）；
	 *   3. 这篇帖子的 SEO 是本插件生成过的（有 `_fas_seo_generated_at`）——
	 *      没生成过的交给首次发布链路，不在编辑时抢跑；
	 *   4. API 配置完整。
	 *
	 * @param int     $post_id     帖子 ID。
	 * @param WP_Post $post_after  保存后的 post。
	 * @param WP_Post $post_before 保存前的 post。
	 */
	public function on_post_updated( $post_id, $post_after, $post_before ) {
		$post_id = (int) $post_id;

		if ( ! $post_after instanceof WP_Post || ! $post_before instanceof WP_Post ) {
			return;
		}
		if ( ! FAS_Forum::is_forum_post( $post_after->post_type ) ) {
			return;
		}
		if ( 'publish' !== $post_after->post_status ) {
			return;
		}

		$old_title = trim( (string) $post_before->post_title );
		$new_title = trim( (string) $post_after->post_title );
		if ( '' === $new_title || $old_title === $new_title ) {
			return;
		}

		if ( '' === (string) get_post_meta( $post_id, '_fas_seo_generated_at', true ) ) {
			return;
		}
		if ( ! FAS_Options::is_configured() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::EVENT_RETITLE, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + self::DELAY_RETITLE, self::EVENT_RETITLE, array( $post_id ) );
		}
	}

	/**
	 * 标题变更后的 AI SEO 重新整理（延迟事件处理）。
	 *
	 * 执行前二次校验：帖子还在、仍是已发布、API 仍可用，并且标题与上次生成时
	 * 记录的 `_fas_seo_source_title` 不同（同一请求里连续保存多次也只跑一次）。
	 *
	 * @param int $post_id 帖子 ID。
	 */
	public function handle_retitle_event( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! FAS_Forum::is_forum_post( $post->post_type ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! FAS_Options::is_configured() || ! class_exists( 'FAS_Title' ) ) {
			return;
		}

		$current = trim( (string) $post->post_title );
		$source  = (string) get_post_meta( $post_id, '_fas_seo_source_title', true );
		if ( '' !== $source && $source === $current ) {
			return;
		}

		$r = FAS_Title::apply_title( $post_id );
		if ( is_array( $r ) && isset( $r['code'] ) ) {
			update_post_meta( $post_id, '_fas_seo_status', 'ok' === $r['code'] ? 'success' : (string) $r['code'] );
			if ( 'ok' !== $r['code'] && isset( $r['message'] ) ) {
				update_post_meta( $post_id, '_fas_seo_error', (string) $r['message'] );
			}
		}
	}

	/**
	 * 保存已发布帖后调度**独立**的正文内链事件（仅 ilink_enabled=1 时；默认 0 完全不动）。
	 *
	 * @param int $post_id 帖子 ID。
	 */
	private function maybe_schedule_ilink( $post_id ) {
		if ( empty( FAS_Options::get_value( 'ilink_enabled', 0 ) ) ) {
			return;
		}
		if ( ! FAS_Options::is_configured() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::EVENT_ILINK, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + self::DELAY_ILINK, self::EVENT_ILINK, array( $post_id ) );
		}
	}

	/**
	 * 正文内链自动事件处理（独立事件 `fas_ilink_auto_event`）。
	 *
	 * 与摘要事件完全解耦：内链失败 / 未开启 / 正文过短都不会影响摘要与标签。
	 *
	 * @param int $post_id 帖子 ID。
	 */
	public function handle_ilink_auto_event( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! FAS_Forum::is_forum_post( $post->post_type ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( empty( FAS_Options::get_value( 'ilink_enabled', 0 ) ) || ! FAS_Options::is_configured() ) {
			return;
		}
		if ( ! class_exists( 'FAS_Auto_Link' ) ) {
			return;
		}

		// generate_for_post() 内部自带幂等（已有映射直接跳过）与排除板块校验。
		FAS_Auto_Link::instance()->generate_for_post( $post_id, false );
	}

	/* =====================================================================
	 * 前端展示
	 * ===================================================================== */

	/**
	 * 渲染摘要模块 HTML（摘要 + 相关标签已融为一体，含顶部圆形头像）。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return string
	 */
	public function summary_box_html( $post_id ) {
		$summary = trim( (string) get_post_meta( $post_id, '_fas_summary', true ) );
		$tags    = $this->get_display_tags( $post_id );

		// 摘要与标签均为空时，不渲染模块。
		if ( '' === $summary && empty( $tags ) ) {
			return '';
		}

		$html  = '<div class="fas-ai-summary-box" aria-label="AI 摘要">';
		$html .= $this->avatar_html();
		$html .= '<div class="fas-ai-summary-head"><span class="fas-ai-declare">摘要由极界AI提供服务</span></div>';

		if ( '' !== $summary ) {
			$html .= '<div class="fas-ai-summary-body">' . esc_html( $summary ) . '</div>';
		}

		if ( ! empty( $tags ) ) {
			$html .= '<div class="fas-ai-tags">';
			foreach ( $tags as $tag ) {
				$tag = trim( (string) $tag );
				if ( '' === $tag ) {
					continue;
				}
				$link = $this->get_tag_link( $tag );
				if ( $link ) {
					$html .= '<a class="fas-ai-tag" href="' . esc_url( $link ) . '" rel="tag">' . esc_html( $tag ) . '</a>';
				} else {
					$html .= '<span class="fas-ai-tag">' . esc_html( $tag ) . '</span>';
				}
			}
			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * 头像 HTML：顶部中间的圆形头像，一半在模块内、一半在模块外。
	 *
	 * @return string
	 */
	private function avatar_html() {
		$url = apply_filters( 'fas_ai_avatar_url', (string) FAS_Options::get_value( 'avatar_url', '' ) );

		if ( '' !== $url ) {
			return '<div class="fas-ai-avatar"><img src="' . esc_url( $url ) . '" alt="AI" loading="lazy" /></div>';
		}

		$svg  = '<svg viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="AI">';
		$svg .= '<circle cx="32" cy="32" r="31" fill="#111827"/>';
		$svg .= '<circle cx="32" cy="32" r="31" fill="none" stroke="#4f8cff" stroke-width="2"/>';
		$svg .= '<path d="M32 13 L35.5 26.5 L49 30 L35.5 33.5 L32 47 L28.5 33.5 L15 30 L28.5 26.5 Z" fill="#4f8cff"/>';
		$svg .= '</svg>';

		return '<div class="fas-ai-avatar">' . $svg . '</div>';
	}

	/**
	 * 读取用于前端展示的标签：仅使用 AI 生成的 _fas_tags，不回退帖子自身标签。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return array
	 */
	private function get_display_tags( $post_id ) {
		$tags = get_post_meta( $post_id, '_fas_tags', true );
		if ( ! is_array( $tags ) || empty( $tags ) ) {
			return array();
		}
		$clean = array();
		foreach ( $tags as $t ) {
			$t = trim( (string) $t );
			if ( '' !== $t ) {
				$clean[] = $t;
			}
		}
		return $clean;
	}

	/**
	 * 获取单个标签的归档链接（存在 term 时），否则返回空字符串。
	 *
	 * @param string $tag_name 标签名。
	 * @return string
	 */
	private function get_tag_link( $tag_name ) {
		// v1.39.0 起标签固定「复用已有 forum_tag」：存在对应归档 term 时输出
		// 归档链接（聚合内链），不存在则由调用方退化为纯文本，无需模式判断。
		if ( ! FAS_Forum::tag_taxonomy_exists() ) {
			return '';
		}
		$term = get_term_by( 'name', $tag_name, FAS_Forum::tag_taxonomy() );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		$link = get_term_link( $term );
		return is_wp_error( $link ) ? '' : $link;
	}

	/**
	 * 渲染前端 AI 摘要模块（摘要与相关标签已融为一体）。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return string
	 */
	public function render_front_module( $post_id ) {
		return $this->summary_box_html( $post_id );
	}

	/**
	 * 短代码 [zib_ai_summary post_id=""]。
	 *
	 * @param array $atts 短代码属性。
	 * @return string
	 */
	public function render_summary_shortcode( $atts ) {
		$atts = shortcode_atts(
			array( 'post_id' => 0 ),
			$atts,
			'zib_ai_summary'
		);

		$post_id = (int) $atts['post_id'];
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}
		if ( ! $post_id || ! FAS_Forum::is_forum_post( get_post_type( $post_id ) ) ) {
			return '';
		}

		return $this->render_front_module( $post_id );
	}

	/**
	 * 在论坛帖子正文顶部自动插入 AI 摘要模块（摘要框 + 相关标签区），
	 * 当开启 show_in_content 时生效。
	 *
	 * 子比论坛帖子详情页若未走 the_content 渲染，可改用短代码
	 * [zib_ai_summary] 在主题模板中手动放置。
	 *
	 * @param string $content 正文 HTML。
	 * @return string
	 */
	public function maybe_prepend_summary( $content ) {
		if ( empty( FAS_Options::get_value( 'show_in_content', 0 ) ) ) {
			return $content;
		}
		if ( ! is_singular( FAS_Forum::post_type() ) ) {
			return $content;
		}

		$box = $this->render_front_module( get_the_ID() );
		if ( '' === $box ) {
			return $content;
		}

		return $box . $content;
	}
}
