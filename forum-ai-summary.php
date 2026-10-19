<?php
/**
 * Plugin Name:       论坛 AI 摘要（Forum AI Summary）
 * Plugin URI:        https://yd.zlinlang.com/
 * Description:       为子比论坛帖子自动生成 AI 摘要与标签，并把 SEO 一次做完：写入子比「独立SEO」三要素、给标签与板块补搜索描述、正文语义内链、文末相关推荐。发帖自动生成、失败自动重试，标题改动后自动重整理 SEO。支持 OpenAI、DeepSeek、Anthropic 与任意兼容接口。
 * Version:           2.2.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            开源极界
 * Author URI:        https://yd.zlinlang.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       forum-ai-summary
 */

// 确保直接访问文件时退出
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FAS_VERSION', '2.2.1' );
define( 'FAS_PLUGIN_FILE', __FILE__ );
define( 'FAS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FAS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once FAS_PLUGIN_DIR . 'includes/class-fas-forum.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-options.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-tags.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-api.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-generator.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-hooks.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-admin.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-batch.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-title.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-seo-description.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-related.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-tag-policy.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-auto-link.php';
require_once FAS_PLUGIN_DIR . 'includes/class-fas-slug.php';

/**
 * 插件主引导：仅在子比主题论坛已启用时才初始化全部功能。
 *
 * 使用 wp_loaded 检测，确保子比主题已完成 forum_post 等 post type 注册。
 */
function fas_bootstrap() {

	// 子比论坛未启用时，仅注册一条后台提示，不加载其余模块，避免误伤普通文章。
	if ( ! FAS_Forum::is_active() ) {
		add_action( 'admin_notices', 'fas_forum_missing_notice' );
		return;
	}

	FAS_Generator::instance();
	FAS_Hooks::instance();
	FAS_Admin::instance();
	FAS_Batch::instance();
	FAS_Title::instance();
	FAS_Seo_Description::instance();
	FAS_Related::instance();
	FAS_Tag_Policy::instance();
	FAS_Auto_Link::instance();
	FAS_Slug::instance();
}
add_action( 'wp_loaded', 'fas_bootstrap', 20 );

/**
 * 子比论坛未启用时的后台提示。
 */
function fas_forum_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>论坛 AI 摘要：</strong>未检测到子比（Zibll）主题的论坛功能（post type <code>forum_post</code> 不存在），插件功能已挂起。请确认已启用子比主题，并在主题设置中开启「社区 &amp; 论坛」功能。</p></div>';
}

/**
 * 激活钩子：写入默认配置。
 */
function fas_activate() {
	if ( false === get_option( 'fas_options', false ) ) {
		add_option( 'fas_options', FAS_Options::defaults() );
	}
}
register_activation_hook( __FILE__, 'fas_activate' );

/**
 * 停用钩子：清理定时任务。
 */
function fas_deactivate() {
	wp_clear_scheduled_hook( 'fas_generate_event' );
	wp_clear_scheduled_hook( 'fas_retry_event' );
	wp_clear_scheduled_hook( 'fas_ilink_auto_event' );
	wp_clear_scheduled_hook( 'fas_desc_auto_event' );
	wp_clear_scheduled_hook( 'fas_seo_retitle_event' );
}
register_deactivation_hook( __FILE__, 'fas_deactivate' );

/**
 * 升级迁移：把已保存配置中「独立SEO存储 key」的错误旧值 posts_seo 纠正为 zib_other_data。
 *
 * v1.10.0 曾把 seo_meta_key 的默认值误设为 posts_seo；用户已保存的 fas_options 里可能仍
 * 残留该错误值（wp_parse_args 不会用新默认值覆盖已存在的旧值），故在此显式迁移一次。
 */
function fas_maybe_migrate_options() {
	$version = (string) get_option( 'fas_db_version', '0' );
	if ( version_compare( $version, '1.11.0', '>=' ) ) {
		return;
	}

	$options = get_option( 'fas_options', array() );
	if ( is_array( $options ) ) {
		$dirty = false;
		if ( empty( $options['seo_meta_key'] ) || 'posts_seo' === $options['seo_meta_key'] ) {
			$options['seo_meta_key'] = 'zib_other_data';
			$dirty                   = true;
		}
		if ( $dirty ) {
			update_option( 'fas_options', $options );
		}
	}

	update_option( 'fas_db_version', '1.11.0' );
}
add_action( 'admin_init', 'fas_maybe_migrate_options' );
