<?php
/**
 * 后台设置页（v2.1.0 · Editorial 版）
 *
 * 视觉方向：米白纸面 + 衬线大数字 + 细分隔线 + 吸顶毛玻璃栏；
 * 布局：左侧表单（4 页签），右侧数据栏（标签 Top / 每帖标签数分布）。
 *
 * 所有样式都挂在 `.fas-admin` 作用域内，不影响 wp-admin 其它页面。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Admin {

	/** @var FAS_Admin */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 优先级 5：父级必须早于子菜单注册，否则子菜单 page hook 名算错 → 403/404。
		add_action( 'admin_menu', array( $this, 'add_menu' ), 5 );
		add_action( 'admin_menu', array( $this, 'add_basic_menu' ), 25 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu() {
		add_menu_page(
			'论坛 AI 摘要',
			'论坛AI摘要',
			'manage_options',
			'fas-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-format-chat',
			80
		);
		add_submenu_page(
			'fas-settings',
			'总览',
			'总览',
			'manage_options',
			'fas-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function add_basic_menu() {
		add_submenu_page(
			'fas-settings',
			'基本设置',
			'基本设置',
			'manage_options',
			'fas-settings'
		);
	}

	public function register_settings() {
		register_setting(
			'fas_options_group',
			'fas_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'fas-' ) ) {
			return;
		}
		wp_enqueue_style( 'fas-admin', FAS_PLUGIN_URL . 'assets/css/admin.css', array(), FAS_VERSION );
		wp_enqueue_script( 'fas-admin', FAS_PLUGIN_URL . 'assets/js/admin.js', array(), FAS_VERSION, true );
	}

	/* =====================================================================
	 * 页面
	 * ===================================================================== */

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$o     = FAS_Options::get();
		$stat  = $this->overview_stats();
		$rail  = FAS_Batch::instance()->rail_stats( 8 );
		$ready = FAS_Options::is_configured();
		?>
		<div class="wrap fas-admin">
			<form method="post" action="options.php" id="fas-options-form">
				<?php settings_fields( 'fas_options_group' ); ?>

				<div class="fas-topbar">
					<div class="fas-wordmark">论坛 AI 摘要<em>v<?php echo esc_html( FAS_VERSION ); ?></em></div>
					<div class="fas-spacer"></div>
					<span class="fas-status<?php echo $ready ? ' is-live' : ''; ?>">
						<i></i><?php echo $ready ? esc_html( $o['provider'] ) . ' · 运行中' : '未配置模型'; ?>
					</span>
					<button type="submit" class="fas-btn fas-btn-solid">保存设置</button>
				</div>

				<div class="fas-masthead">
					<div>
						<h1>设置</h1>
						<p>为论坛帖子生成摘要与标签，并把 SEO 一并做完。</p>
					</div>
				</div>

				<div class="fas-figures">
					<div class="fas-fig"><b><?php echo (int) $stat['total']; ?></b><small>已发布帖子</small></div>
					<div class="fas-fig"><b><?php echo (int) $stat['done']; ?></b><small>已有摘要</small></div>
					<div class="fas-fig"><b><?php echo (int) $stat['no_tags']; ?></b><small>缺标签</small></div>
					<div class="fas-fig"><b><?php echo (int) $stat['coverage']; ?><span>%</span></b><small>推荐覆盖</small></div>
				</div>

				<?php if ( ! $ready ) : ?>
					<div class="fas-notice">尚未填写 API 密钥 / 地址 / 模型，插件不会执行生成。</div>
				<?php endif; ?>

				<div class="fas-layout">
					<main class="fas-main">
						<div class="fas-tabs" role="tablist">
							<button type="button" class="fas-tab is-active" data-tab="basic" role="tab">基本</button>
							<button type="button" class="fas-tab" data-tab="content" role="tab">内容</button>
							<button type="button" class="fas-tab" data-tab="seo" role="tab">SEO</button>
							<button type="button" class="fas-tab" data-tab="advanced" role="tab">高级</button>
							<i class="fas-tab-ink" aria-hidden="true"></i>
						</div>

						<!-- 基本 -->
						<section class="fas-panel" id="fas-tab-basic">
							<?php
							$this->row_checkbox( 'enabled', '启用插件', '总开关', $o );
							$this->row_select(
								'provider',
								'模型供应商',
								'',
								$o,
								array(
									'openai'    => 'OpenAI',
									'deepseek'  => 'DeepSeek',
									'anthropic' => 'Anthropic',
									'custom'    => '自定义（OpenAI 兼容）',
								)
							);
							$this->render_provider_fields( $o );
							$this->row_checkbox( 'auto_on_publish', '发布时自动生成', '发帖 5 秒后跑', $o );
							$this->row_checkbox( 'show_in_content', '正文顶部显示摘要', '用短代码可手动放', $o );
							?>
						</section>

						<!-- 内容 -->
						<section class="fas-panel" id="fas-tab-content" hidden>
							<h2 class="fas-h">摘要</h2>
							<?php
							$this->row_checkbox( 'summary_enabled', '生成摘要', '关闭只生成标签', $o );
							$this->row_number( 'summary_chars', '目标字数', '默认 120', $o, 20, 2000 );
							$this->row_select( 'summary_style', '风格', '', $o, array( 'summary' => '概括型', 'bullets' => '要点型' ) );
							$this->row_checkbox( 'write_excerpt', '同时写入原生摘要字段', 'post_excerpt', $o );
							?>

							<h2 class="fas-h">标签</h2>
							<?php
							$this->row_checkbox( 'tags_enabled', '生成标签', '只复用站内已有标签', $o );
							$this->row_number( 'tags_count', '数量上限', '默认 3 个', $o, 1, 10 );
							$this->row_select( 'tags_lang', '语言', '', $o, array( 'zh' => '中文', 'bilingual' => '中英双语' ) );
							$this->row_checkbox( 'tags_append', '追加到已有标签', '不勾选则替换', $o );
							?>

							<h2 class="fas-h">内链与推荐</h2>
							<?php
							$this->row_checkbox( 'ilink_enabled', '正文自动内链', '默认关闭', $o );
							$this->row_number( 'ilink_per_post', '每帖内链条数', '默认 1 条', $o, 1, 5 );
							$this->row_checkbox( 'related_enabled', '文末相关推荐', '默认开启', $o );
							$this->row_number( 'related_count', '推荐条数', '默认 8 条', $o, 1, 20 );
							$this->row_select(
								'related_balance',
								'内链均衡',
								'避免老帖零曝光',
								$o,
								array( 'fair' => '曝光均衡（推荐）', 'random' => '随机', 'off' => '关闭' )
							);
							?>

							<?php $this->render_backfill_card(); ?>
						</section>

						<!-- SEO -->
						<section class="fas-panel" id="fas-tab-seo" hidden>
							<h2 class="fas-h">AI SEO 标题</h2>
							<?php
							$this->row_text( 'title_brand', '品牌词', '如「你的站名」，可留空', $o );
							$this->row_text( 'title_categories', '分类词', '逗号分隔', $o );
							$this->row_number( 'title_max_length', '标题最大长度', '默认 40 字', $o, 10, 200 );
							$this->row_checkbox( 'title_include_category', '标题带分类词', '关闭则只用于关键词', $o );
							$this->row_checkbox( 'title_include_plate', '标题带板块名', '关闭则不带板块', $o );
							?>

							<h2 class="fas-h">标签 SEO</h2>
							<?php
							$this->row_text( 'tag_noindex_generic', '泛词标签 noindex', '逗号分隔，这些标签页不进索引', $o );
							$this->row_checkbox( 'tag_slug_pinyin', '新标签自动拼音别名', '只影响新标签', $o );
							?>
							<p class="fas-note">标题改动后会自动重跑 SEO，无需手动操作。</p>
						</section>

						<!-- 高级 -->
						<section class="fas-panel" id="fas-tab-advanced" hidden>
							<h2 class="fas-h">模型参数</h2>
							<?php
							$this->row_number( 'max_tokens', '最大输出 token', '默认 500', $o, 50, 8000 );
							$this->row_number( 'temperature', '温度', '越低越稳定', $o, 0, 2, true );
							$this->row_number( 'timeout', '请求超时（秒）', '默认 30', $o, 5, 120 );
							$this->row_checkbox( 'overwrite_existing', '覆盖已有摘要', '重新生成时覆盖', $o );
							?>

							<h2 class="fas-h">输入与重试</h2>
							<?php
							$this->row_number( 'max_input_chars', '最大输入字数', '超出截断', $o, 200, 20000 );
							$this->row_number( 'min_input_chars', '最小输入字数', '低于则不调 API', $o, 1, 500 );
							$this->row_text( 'short_text', '内容过短时的文案', '', $o );
							$this->row_number( 'max_retries', '失败重试次数', '默认 3 次', $o, 0, 10 );
							$this->row_number( 'retry_delay', '重试间隔（秒）', '按次数递增', $o, 1, 60 );
							?>

							<h2 class="fas-h">细节</h2>
							<?php
							$this->row_number( 'ilink_candidates', '内链候选数', '默认 20', $o, 5, 40 );
							$this->row_number( 'ilink_min_chars', '内链最短正文', '默认 120 字', $o, 20, 2000 );
							$this->row_text( 'ilink_exclude_plates', '内链排除板块 ID', '逗号分隔', $o );
							$this->row_number( 'related_plate_max', '同板块推荐上限', '0 为不限', $o, 0, 20 );
							$this->row_text( 'avatar_url', '摘要头像地址', '留空用内置头像', $o );
							?>

							<h2 class="fas-h">子比 SEO 字段</h2>
							<?php
							$this->row_text( 'seo_meta_key', '存储字段名', '默认 zib_other_data', $o );
							$this->row_text( 'seo_title_key', '标题键名', '默认 title', $o );
							$this->row_text( 'seo_keywords_key', '关键词键名', '默认 keywords', $o );
							$this->row_text( 'seo_description_key', '描述键名', '默认 description', $o );
							?>

							<h2 class="fas-h">系统检测</h2>
							<?php $this->render_diagnostic_panel(); ?>
						</section>

						<div class="fas-foot">
							<button type="submit" class="fas-btn fas-btn-solid">保存设置</button>
							<span class="fas-note">保存后立即生效</span>
						</div>
					</main>

					<aside class="fas-rail">
						<section class="fas-card">
							<h3>标签 Top <?php echo (int) count( $rail['top'] ); ?></h3>
							<?php $this->render_rank( $rail['top'] ); ?>
							<p class="fas-legend">按已发布帖子数排序</p>
						</section>

						<section class="fas-card">
							<h3>每帖标签数</h3>
							<?php $this->render_dist_svg( $rail['dist'] ); ?>
							<p class="fas-legend"><?php echo (int) $this->peak_bucket( $rail['dist'] ); ?> 篇有 <?php echo esc_html( '5' === (string) $this->peak_key( $rail['dist'] ) ? '5 个以上' : $this->peak_key( $rail['dist'] ) . ' 个' ); ?>标签</p>
						</section>

						<section class="fas-card">
							<h3>相关推荐覆盖</h3>
							<?php $cov = max( 0, min( 100, (float) $stat['coverage'] ) ); ?>
							<div class="fas-split" role="img" aria-label="相关推荐覆盖 <?php echo esc_attr( $cov ); ?>%">
								<i style="width:<?php echo esc_attr( $cov ); ?>%"></i><i class="is-rest" style="width:<?php echo esc_attr( 100 - $cov ); ?>%"></i>
							</div>
							<p class="fas-legend"><b><?php echo (int) $stat['covered']; ?></b> 篇进过推荐 · <b><?php echo (int) $stat['uncovered']; ?></b> 篇从未进入</p>
							<p class="fas-legend">平均每篇被推荐 <b><?php echo esc_html( $stat['avg_imp'] ); ?></b> 次，累计曝光 <b><?php echo (int) $stat['total_imp']; ?></b> 次</p>
						</section>
					</aside>
				</div>
			</form>
		</div>
		<?php
	}

	/* =====================================================================
	 * 右栏图表
	 * ===================================================================== */

	/**
	 * 标签排行（横向 meter）。
	 *
	 * @param array $top array<array{name,count}>。
	 */
	private function render_rank( $top ) {
		if ( empty( $top ) ) {
			echo '<p class="fas-legend">暂无标签数据。</p>';
			return;
		}
		$max = max( 1, (int) $top[0]['count'] );
		echo '<ol class="fas-rank">';
		$i = 0;
		foreach ( $top as $row ) {
			$pct = min( 100, (int) round( $row['count'] / $max * 100 ) );
			printf(
				'<li><span class="n">%s</span><span class="c">%d</span><span class="meter"><i style="width:%d%%;animation-delay:%dms"></i></span></li>',
				esc_html( $row['name'] ),
				(int) $row['count'],
				(int) $pct,
				(int) ( $i * 60 )
			);
			$i++;
		}
		echo '</ol>';
	}

	/**
	 * 每帖标签数分布（内联 SVG 柱状图，无外部依赖）。
	 *
	 * @param array $dist 桶 => 篇数。
	 */
	private function render_dist_svg( $dist ) {
		$w    = 300;
		$h    = 86;
		$pad  = 16;
		$n    = max( 1, count( $dist ) );
		$bw   = $w / $n;
		$max  = max( 1, max( array_map( 'intval', $dist ) ) );
		$peak = (string) $this->peak_key( $dist );

		printf( '<svg class="fas-chart" viewBox="0 0 %d %d" role="img" aria-label="每帖标签数分布">', (int) $w, (int) $h );

		$i = 0;
		foreach ( $dist as $k => $v ) {
			$v     = (int) $v;
			$bar_h = $max > 0 ? (int) round( $v / $max * ( $h - $pad - 6 ) ) : 0;
			$x     = (int) round( $i * $bw + 5 );
			$y     = (int) ( $h - $pad - $bar_h );
			$fill  = ( (string) $k === $peak ) ? '#14130F' : '#C9C2B3';

			printf(
				'<rect x="%d" y="%d" width="%d" height="%d" fill="%s"></rect>',
				$x,
				$y,
				(int) round( $bw - 10 ),
				$bar_h,
				esc_attr( $fill )
			);
			printf(
				'<text x="%d" y="%d" font-size="9" fill="#928C80" text-anchor="middle">%s</text>',
				(int) round( $x + ( $bw - 10 ) / 2 ),
				(int) ( $h - 4 ),
				esc_html( '5' === (string) $k ? '5+' : (string) $k )
			);
			$i++;
		}

		echo '</svg>';
	}

	/**
	 * 分布中篇数最多的桶。
	 */
	private function peak_key( $dist ) {
		$best = '3';
		$max  = -1;
		foreach ( $dist as $k => $v ) {
			if ( (int) $v > $max ) {
				$max  = (int) $v;
				$best = (string) $k;
			}
		}
		return $best;
	}

	private function peak_bucket( $dist ) {
		return isset( $dist[ (string) $this->peak_key( $dist ) ] ) ? $dist[ (string) $this->peak_key( $dist ) ] : 0;
	}

	/* =====================================================================
	 * 表单行
	 * ===================================================================== */

	private function row_checkbox( $key, $label, $hint, $o ) {
		$id  = 'fas-' . $key;
		$val = ! empty( $o[ $key ] );
		echo '<div class="fas-row">';
		echo '<label class="fas-label" for="' . esc_attr( $id ) . '">' . esc_html( $label );
		if ( '' !== $hint ) {
			echo '<span class="fas-hint">' . esc_html( $hint ) . '</span>';
		}
		echo '</label><div class="fas-ctrl">';
		echo '<label class="fas-switch">';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="fas_options[' . esc_attr( $key ) . ']" value="1"' . checked( $val, true, false ) . '>';
		echo '<span class="fas-switch-track"><span class="fas-switch-dot"></span></span>';
		echo '<span class="fas-switch-text">' . ( $val ? 'ON' : 'OFF' ) . '</span>';
		echo '</label></div></div>';
	}

	private function row_text( $key, $label, $hint, $o ) {
		$id = 'fas-' . $key;
		echo '<div class="fas-row"><label class="fas-label" for="' . esc_attr( $id ) . '">' . esc_html( $label );
		if ( '' !== $hint ) {
			echo '<span class="fas-hint">' . esc_html( $hint ) . '</span>';
		}
		echo '</label><div class="fas-ctrl"><input type="text" class="fas-input" id="' . esc_attr( $id ) . '" name="fas_options[' . esc_attr( $key ) . ']" value="' . esc_attr( isset( $o[ $key ] ) ? $o[ $key ] : '' ) . '"></div></div>';
	}

	private function row_number( $key, $label, $hint, $o, $min, $max, $is_float = false ) {
		$id = 'fas-' . $key;
		echo '<div class="fas-row"><label class="fas-label" for="' . esc_attr( $id ) . '">' . esc_html( $label );
		if ( '' !== $hint ) {
			echo '<span class="fas-hint">' . esc_html( $hint ) . '</span>';
		}
		printf(
			'</label><div class="fas-ctrl"><input type="number" class="fas-input fas-input-num" id="%s" name="fas_options[%s]" value="%s" min="%s" max="%s" step="%s"></div></div>',
			esc_attr( $id ),
			esc_attr( $key ),
			esc_attr( isset( $o[ $key ] ) ? $o[ $key ] : '' ),
			esc_attr( $min ),
			esc_attr( $max ),
			$is_float ? '0.1' : '1'
		);
	}

	private function row_select( $key, $label, $hint, $o, $options ) {
		$id = 'fas-' . $key;
		echo '<div class="fas-row"><label class="fas-label" for="' . esc_attr( $id ) . '">' . esc_html( $label );
		if ( '' !== $hint ) {
			echo '<span class="fas-hint">' . esc_html( $hint ) . '</span>';
		}
		echo '</label><div class="fas-ctrl"><select class="fas-input fas-select" id="' . esc_attr( $id ) . '" name="fas_options[' . esc_attr( $key ) . ']">';
		$cur = isset( $o[ $key ] ) ? (string) $o[ $key ] : '';
		foreach ( $options as $v => $text ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $v ), selected( $cur, (string) $v, false ), esc_html( $text ) );
		}
		echo '</select></div></div>';
	}

	/**
	 * 供应商配置块（按当前选择显示其一）。
	 */
	private function render_provider_fields( $o ) {
		$blocks = array(
			'openai'    => array(
				'title' => 'OpenAI',
				'rows'  => array(
					array( 'openai_api_key', 'API Key', '' ),
					array( 'openai_base_url', '接口地址', '' ),
					array( 'openai_model', '模型', '如 gpt-4o-mini' ),
				),
			),
			'deepseek'  => array(
				'title' => 'DeepSeek',
				'rows'  => array(
					array( 'deepseek_api_key', 'API Key', '' ),
					array( 'deepseek_base_url', '接口地址', '' ),
					array( 'deepseek_model', '模型', '如 deepseek-chat' ),
				),
			),
			'anthropic' => array(
				'title' => 'Anthropic',
				'rows'  => array(
					array( 'anthropic_api_key', 'API Key', '' ),
					array( 'anthropic_base_url', '接口地址', '' ),
					array( 'anthropic_model', '模型', '' ),
					array( 'anthropic_version', 'API 版本', '' ),
				),
			),
			'custom'    => array(
				'title' => '自定义（OpenAI 兼容）',
				'rows'  => array(
					array( 'custom_api_key', 'API Key', '' ),
					array( 'custom_base_url', '接口地址', '' ),
					array( 'custom_model', '模型', '' ),
					array( 'custom_chat_path', '请求路径', '如 /v1/chat/completions' ),
				),
			),
		);

		foreach ( $blocks as $key => $block ) {
			$hidden = ( $key !== ( isset( $o['provider'] ) ? $o['provider'] : 'openai' ) );
			printf( '<div class="fas-provider" id="fas-provider-%s"%s>', esc_attr( $key ), $hidden ? ' hidden' : '' );
			foreach ( $block['rows'] as $row ) {
				$this->row_text( $row[0], $row[1], $row[2], $o );
			}
			echo '</div>';
		}
	}

	/**
	 * 存量补齐卡片。
	 */
	private function render_backfill_card() {
		$ids = FAS_Batch::instance()->missing_summary_ids();
		$n   = count( $ids );
		?>
		<h2 class="fas-h">存量补齐</h2>
		<div class="fas-card fas-card-inline">
			<?php if ( $n > 0 ) : ?>
				<p>有 <b><?php echo (int) $n; ?></b> 篇已发布帖子还没有摘要。</p>
				<input type="hidden" id="fas_nonce" value="<?php echo esc_attr( wp_create_nonce( 'fas_generate' ) ); ?>">
				<div class="fas-actions">
					<button type="button" class="fas-btn fas-btn-solid" id="fas-backfill-start" data-ids="<?php echo esc_attr( wp_json_encode( $ids ) ); ?>">一键补齐（<?php echo (int) $n; ?> 篇）</button>
					<button type="button" class="fas-btn" id="fas-backfill-stop" hidden>停止</button>
				</div>
				<div class="fas-progress" id="fas-backfill-progress" hidden><i></i></div>
				<p class="fas-note" id="fas-backfill-log" aria-live="polite"></p>
			<?php else : ?>
				<p>全部已发布帖子都有摘要。</p>
				<p class="fas-note">某篇生成失败时，可在帖子编辑页右侧「AI 摘要与标签」里重跑。</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * 总览统计。
	 */
	private function overview_stats() {
		$batch = FAS_Batch::instance()->stats();
		$rel   = FAS_Related::instance()->get_stats();

		return array(
			'total'      => isset( $batch['total'] ) ? (int) $batch['total'] : 0,
			'done'       => isset( $batch['done'] ) ? (int) $batch['done'] : 0,
			'no_tags'    => isset( $batch['no_tags'] ) ? (int) $batch['no_tags'] : 0,
			'coverage'   => isset( $rel['coverage'] ) ? $rel['coverage'] : 0,
			'covered'    => isset( $rel['covered'] ) ? (int) $rel['covered'] : 0,
			'uncovered'  => isset( $rel['uncovered'] ) ? (int) $rel['uncovered'] : 0,
			'avg_imp'    => isset( $rel['avg'] ) ? $rel['avg'] : 0,
			'total_imp'  => isset( $rel['total_imp'] ) ? (int) $rel['total_imp'] : 0,
		);
	}

	/**
	 * 系统检测表。
	 */
	private function render_diagnostic_panel() {
		$d = FAS_Forum::detect();

		$rows = array(
			'论坛帖子 post type'            => array( $d['post_type']['name'], $d['post_type']['registered'] ),
			'版块 post type'                => array( $d['plate_post_type']['name'], $d['plate_post_type']['registered'] ),
			'帖子标签分类法'                 => array( $d['tag_taxonomy']['name'], $d['tag_taxonomy']['registered'] ),
			'帖子话题分类法'                 => array( $d['topic_taxonomy']['name'], $d['topic_taxonomy']['registered'] ),
			'版块分类分类法'                 => array( $d['plate_cat_taxonomy']['name'], $d['plate_cat_taxonomy']['registered'] ),
			'主题函数 zib_bbs_get_plate_id'  => array( 'zib_bbs_get_plate_id()', $d['helpers']['zib_bbs_get_plate_id'] ),
		);

		$theme = $d['theme']['name'] ? $d['theme']['name'] . ' ' . $d['theme']['version'] : '未知';
		?>
		<table class="fas-table">
			<thead><tr><th>检测项</th><th>名称</th><th>状态</th></tr></thead>
			<tbody>
				<tr><td>当前主题</td><td><?php echo esc_html( $theme ); ?></td><td><?php echo $d['theme']['is_zibll'] ? '<span class="fas-ok">子比</span>' : '<span class="fas-bad">非子比</span>'; ?></td></tr>
				<tr><td>已发布帖子数</td><td><?php echo (int) $d['post_type']['count']; ?> 篇</td><td><span class="fas-ok">正常</span></td></tr>
				<?php foreach ( $rows as $label => $info ) : ?>
					<tr>
						<td><?php echo esc_html( $label ); ?></td>
						<td><code><?php echo esc_html( $info[0] ); ?></code></td>
						<td><?php echo $info[1] ? '<span class="fas-ok">正常</span>' : '<span class="fas-bad">缺失</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* =====================================================================
	 * 配置校验
	 * ===================================================================== */

	public function sanitize( $input ) {
		$defaults  = FAS_Options::defaults();
		$validated = $defaults;
		$input     = is_array( $input ) ? $input : array();

		$checkboxes = array( 'enabled', 'auto_on_publish', 'overwrite_existing', 'summary_enabled', 'write_excerpt', 'tags_enabled', 'tags_append', 'show_in_content', 'related_enabled', 'ilink_enabled', 'tag_slug_pinyin', 'title_include_category', 'title_include_plate' );
		$ints       = array( 'max_tokens', 'summary_chars', 'summary_sentences', 'tags_count', 'max_input_chars', 'min_input_chars', 'max_retries', 'retry_delay', 'timeout', 'title_max_length', 'related_count', 'related_plate_max', 'ilink_per_post', 'ilink_candidates', 'ilink_min_chars' );
		$selects    = array(
			'provider'        => array( 'openai', 'deepseek', 'anthropic', 'custom' ),
			'length_mode'     => array( 'chars', 'sentences' ),
			'summary_style'   => array( 'summary', 'bullets' ),
			'tags_lang'       => array( 'zh', 'bilingual' ),
			'related_balance' => array( 'off', 'random', 'fair' ),
		);
		$texts      = array( 'prefix', 'short_text', 'anthropic_version', 'custom_chat_path', 'title_brand', 'title_categories', 'seo_meta_key', 'seo_title_key', 'seo_keywords_key', 'seo_description_key', 'ilink_exclude_plates', 'tag_noindex_generic' );
		$keys       = array( 'openai', 'deepseek', 'anthropic', 'custom' );

		foreach ( $checkboxes as $k ) {
			$validated[ $k ] = ! empty( $input[ $k ] ) ? 1 : 0;
		}
		foreach ( $ints as $k ) {
			$validated[ $k ] = isset( $input[ $k ] ) ? (int) $input[ $k ] : $defaults[ $k ];
		}
		foreach ( $selects as $k => $allow ) {
			$validated[ $k ] = ( isset( $input[ $k ] ) && in_array( $input[ $k ], $allow, true ) ) ? $input[ $k ] : $defaults[ $k ];
		}
		foreach ( $texts as $k ) {
			$validated[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : $defaults[ $k ];
		}
		foreach ( $keys as $k ) {
			$validated[ $k . '_api_key' ]     = isset( $input[ $k . '_api_key' ] ) ? sanitize_text_field( $input[ $k . '_api_key' ] ) : $defaults[ $k . '_api_key' ];
			$validated[ $k . '_base_url' ]    = isset( $input[ $k . '_base_url' ] ) ? esc_url_raw( $input[ $k . '_base_url' ] ) : $defaults[ $k . '_base_url' ];
			$validated[ $k . '_model' ]       = isset( $input[ $k . '_model' ] ) ? sanitize_text_field( $input[ $k . '_model' ] ) : $defaults[ $k . '_model' ];
		}

		$validated['summary_chars']     = max( 20, min( 2000, $validated['summary_chars'] ) );
		$validated['summary_sentences'] = max( 1, min( 20, $validated['summary_sentences'] ) );
		$validated['tags_count']        = max( 1, min( 10, $validated['tags_count'] ) );
		$validated['max_input_chars']   = max( 200, min( 20000, $validated['max_input_chars'] ) );
		$validated['min_input_chars']   = max( 1, min( 500, $validated['min_input_chars'] ) );
		$validated['max_retries']       = max( 0, min( 10, $validated['max_retries'] ) );
		$validated['retry_delay']       = max( 1, min( 60, $validated['retry_delay'] ) );
		$validated['timeout']           = max( 5, min( 120, $validated['timeout'] ) );
		$validated['temperature']       = max( 0, min( 2, isset( $input['temperature'] ) ? (float) $input['temperature'] : $defaults['temperature'] ) );
		$validated['max_tokens']        = max( 50, min( 8000, $validated['max_tokens'] ) );
		$validated['title_max_length']  = max( 10, min( 200, $validated['title_max_length'] ) );
		$validated['related_count']     = max( 1, min( 20, $validated['related_count'] ) );
		$validated['related_plate_max'] = max( 0, min( 20, $validated['related_plate_max'] ) );
		$validated['ilink_per_post']    = max( 1, min( 5, $validated['ilink_per_post'] ) );
		$validated['ilink_candidates']  = max( 5, min( 40, $validated['ilink_candidates'] ) );
		$validated['ilink_min_chars']   = max( 20, min( 2000, $validated['ilink_min_chars'] ) );

		return $validated;
	}
}
