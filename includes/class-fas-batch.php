<?php
/**
 * 单篇 Meta Box + AJAX 生成接口
 *
 * 帖子编辑页侧边栏的单篇「生成 / 重新生成 / 补标签」入口与统一 AJAX
 * 生成接口（含权限与 nonce 校验）。发布自动链路（FAS_Hooks）会补齐缺失
 * 的摘要与标签；本类保留的单篇手动入口用于强制重跑与硬失败帖的手动补救。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Batch {

	/** @var FAS_Batch */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// v1.39.0：「批量处理」后台页与其批量 AJAX 已移除（发布自动链路会
		// 缺什么补什么，存量硬失败帖可用本类保留的 Meta Box 单篇入口重跑）。
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'wp_ajax_fas_generate', array( $this, 'handle_ajax' ) );
	}

	/**
	 * 单篇编辑页 Meta Box。
	 */
	public function add_meta_box( $post_type ) {
		if ( ! FAS_Forum::is_forum_post( $post_type ) ) {
			return;
		}
		add_meta_box(
			'fas_meta_box',
			'AI 摘要与标签',
			array( $this, 'render_meta_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Meta Box 渲染。
	 *
	 * @param WP_Post $post 当前帖子。
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'fas_generate', 'fas_nonce' );

		$summary = get_post_meta( $post->ID, '_fas_summary', true );
		$status  = get_post_meta( $post->ID, '_fas_status', true );
		$error   = get_post_meta( $post->ID, '_fas_error', true );
		$tags    = get_post_meta( $post->ID, '_fas_tags', true );
		$tags    = is_array( $tags ) ? $tags : array();
		$plate   = FAS_Forum::get_plate_name( $post->ID );

		?>
		<div id="fas-meta-box" class="fas-meta-box">
			<div class="fas-meta-status">
				<span class="fas-meta-status-label">状态</span>
				<span class="fas-status fas-status-<?php echo esc_attr( $this->status_class( $status ) ); ?>"><?php echo esc_html( $this->status_label( $status ) ); ?></span>
			</div>
			<?php if ( $plate ) : ?>
				<p class="fas-meta-item"><span class="fas-meta-label">版块</span> <?php echo esc_html( $plate ); ?></p>
			<?php endif; ?>
			<?php if ( $summary ) : ?>
				<p class="fas-meta-item"><span class="fas-meta-label">摘要</span></p>
				<textarea class="fas-summary-preview" rows="4" readonly><?php echo esc_textarea( $summary ); ?></textarea>
			<?php endif; ?>
			<?php if ( ! empty( $tags ) ) : ?>
				<p class="fas-meta-item"><span class="fas-meta-label">标签</span></p>
				<div class="fas-meta-tags">
					<?php foreach ( $tags as $tag ) : ?>
						<span class="fas-meta-tag"><?php echo esc_html( $tag ); ?></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $error ) : ?>
				<p class="fas-meta-error"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<p class="fas-meta-actions">
				<button type="button" class="button button-primary fas-generate-btn" data-post-id="<?php echo (int) $post->ID; ?>" data-force="1"><?php echo $summary ? '重新生成' : '生成摘要与标签'; ?></button>
				<button type="button" class="button fas-generate-tags-btn" data-post-id="<?php echo (int) $post->ID; ?>" data-force="1">仅补标签</button>
				<span class="spinner"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * 统计信息：全部/已生成/待生成/失败。
	 *
	 * public：供「论坛AI摘要 → 总览」的统计卡复用，避免同一口径写两套 SQL。
	 *
	 * @return array
	 */
	public function stats() {
		global $wpdb;
		$pt = FAS_Forum::post_type();

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
				$pt
			)
		);

		$done = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				 AND pm.meta_key = '_fas_summary' AND pm.meta_value != ''",
				$pt
			)
		);

		$failed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				 AND pm.meta_key = '_fas_status' AND pm.meta_value = 'failed'",
				$pt
			)
		);

		// 有摘要但缺标签的帖子（历史遗留/标签解析失败/内容过短）。
		$no_tags = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} s ON p.ID = s.post_id AND s.meta_key = '_fas_summary' AND s.meta_value != ''
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				 AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} t
					WHERE t.post_id = p.ID AND t.meta_key = '_fas_tags'
					AND t.meta_value != '' AND t.meta_value != 'a:0:{}'
				 )",
				$pt
			)
		);

		return array(
			'total'   => $total,
			'done'    => $done,
			'pending' => max( 0, $total - $done ),
			'failed'  => $failed,
			'no_tags' => $no_tags,
		);
	}

	/**
	 * 缺失摘要的已发布帖子 ID 列表（按 ID 升序）。
	 *
	 * 「缺失」判定 = 没有 _fas_summary 元数据，或该元数据为空字符串。
	 * 设置页「一键补齐缺失摘要」用它一次性取回固定 ID 列表，前端按顺序
	 * 逐篇调用既有的 fas_generate 接口——中途停止也不会跳批或漏批
	 *（不采用「每批重查列表 + 累积 offset」，那正是 1.16.1 / 1.20.0 的跳批事故成因）。
	 *
	 * @param int $limit 最多返回多少条（0 = 全部）。
	 * @return int[]
	 */
	public function missing_summary_ids( $limit = 0 ) {
		global $wpdb;
		$pt  = FAS_Forum::post_type();
		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			 WHERE p.post_type = %s AND p.post_status = 'publish'
			 AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID AND pm.meta_key = '_fas_summary' AND pm.meta_value != ''
			 )
			 ORDER BY p.ID ASC";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $pt ) );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * 设置页右栏图表数据：标签 Top N（按已发布帖子数）与每帖标签数分布。
	 *
	 * 两条查询都较重（要 JOIN 三张表并分组），因此统一缓存 5 分钟；
	 * 设置页刷新不会反复压数据库。
	 *
	 * @param int $limit Top 标签数量。
	 * @return array { top: array<array{name,count}>, dist: array<string,int> }
	 */
	public function rail_stats( $limit = 8 ) {
		$cache = get_transient( 'fas_rail_stats' );
		if ( is_array( $cache ) && isset( $cache['top'], $cache['dist'] ) ) {
			return $cache;
		}

		global $wpdb;
		$pt  = FAS_Forum::post_type();
		$tax = FAS_Forum::tag_taxonomy();

		$top_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.name AS name, COUNT(DISTINCT p.ID) AS c
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND tt.taxonomy = %s
				 GROUP BY t.term_id
				 ORDER BY c DESC, t.name ASC
				 LIMIT %d",
				$pt,
				$tax,
				(int) $limit
			)
		);

		$top = array();
		foreach ( (array) $top_rows as $r ) {
			$top[] = array( 'name' => (string) $r->name, 'count' => (int) $r->c );
		}

		// 每帖标签数分布：0 / 1 / 2 / 3 / 4 / 5+。
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c, COUNT(*) AS n FROM (
					SELECT p.ID, COUNT(DISTINCT tt.term_id) AS c
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					LEFT JOIN {$wpdb->term_taxonomy} tt
						ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
					WHERE p.post_type = %s AND p.post_status = 'publish'
					GROUP BY p.ID
				 ) x GROUP BY c",
				$tax,
				$pt
			)
		);

		$dist = array( '0' => 0, '1' => 0, '2' => 0, '3' => 0, '4' => 0, '5' => 0 );
		foreach ( (array) $rows as $r ) {
			$k = (int) $r->c;
			if ( $k >= 5 ) {
				$dist['5'] += (int) $r->n;
			} else {
				$dist[ (string) $k ] += (int) $r->n;
			}
		}

		$out = array( 'top' => $top, 'dist' => $dist );
		set_transient( 'fas_rail_stats', $out, 300 );
		return $out;
	}

	/**
	 * AJAX 单篇生成处理。
	 */
	public function handle_ajax() {
		check_ajax_referer( 'fas_generate', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '权限不足' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$force   = ! empty( $_POST['force'] );
		$tags_only = ! empty( $_POST['tags_only'] );

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => '缺少帖子 ID' ) );
		}

		$result = $tags_only
			? FAS_Generator::instance()->generate_tags_for_post( $post_id, $force )
			: FAS_Generator::instance()->generate_for_post( $post_id, $force );

		$tags = get_post_meta( $post_id, '_fas_tags', true );
		$tags = is_array( $tags ) ? $tags : array();

		wp_send_json_success(
			array(
				'code'    => $result['code'],
				'message' => $result['message'],
				'summary' => get_post_meta( $post_id, '_fas_summary', true ),
				'tags'    => $tags,
				'status'  => get_post_meta( $post_id, '_fas_status', true ),
			)
		);
	}

	/**
	 * 状态对应的 CSS 类名（将 pending_retry 归并到 pending）。
	 */
	/**
	 * 状态文本。
	 */
	private function status_label( $status ) {
		$map = array(
			'success'       => '已生成',
			'failed'        => '失败',
			'pending_retry' => '待重试',
			'skipped'       => '已跳过',
			'none'          => '未生成',
		);
		if ( empty( $status ) ) {
			$status = 'none';
		}
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	private function status_class( $status ) {
		$map = array(
			'success'       => 'success',
			'failed'        => 'failed',
			'pending_retry' => 'pending',
			'skipped'       => 'skipped',
			'none'          => 'none',
		);
		if ( empty( $status ) ) {
			$status = 'none';
		}
		return isset( $map[ $status ] ) ? $map[ $status ] : 'none';
	}
}
