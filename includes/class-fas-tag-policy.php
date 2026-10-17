<?php
/**
 * 标签策略：旧标签 301 跳转 + 泛词标签 noindex
 *
 * v2.0.0 精简：原「标签相关性分析内核」（class-fas-tag-inspector.php，981 行）已整体移除。
 * 原因：该内核的后台页面在 v1.41.0 就删了，只剩「薄标签 noindex」在用；而线上
 * 「薄标签 noindex」开关一直是关闭状态，泛词判定又只比标签名、根本不需要帖数——
 * 结果每个标签归档页都在为一个关闭的功能做全站分析。现在只保留两条真正在跑的策略：
 *
 *   1. **旧标签 URL 301 跳转**（`template_redirect`）：读合并映射 `fas_tag_merge_map`
 *      （历史数据保留，不可删），把已被合并 / 已删除的旧标签归档页 301 到主标签，
 *      保住外链权重。含防环（沿链跟随，深度上限 5）、slug 编码兼容
 *      （rawurlencode / rawurldecode 双向尝试 + 统一小写比较）与 404 兜底解析。
 *   2. **泛词标签 noindex**（`wp_robots`）：名称命中 `tag_noindex_generic` 清单
 *      （默认「工具,娱乐,系统,下载」）的标签保留关联与归档页，但输出 `noindex,follow`。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Tag_Policy {

	/** 合并映射记录 option 名（保留：301 跳转依赖它，数据不删）。 */
	const MAP_OPTION = 'fas_tag_merge_map';

	/** 沿链跟随的最大深度（防环）。 */
	const MAX_CHAIN_DEPTH = 5;

	/** @var FAS_Tag_Policy */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 前台：旧标签 URL 301 重定向 + 薄标签 noindex。
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
	}

	/* =====================================================================
	 * 映射记录（保留读取，后台不再提供写入入口）
	 * ===================================================================== */

	/**
	 * 读取合并映射记录（源 slug => 记录）。
	 *
	 * 记录由历史版本的「标签合并」页写入；该页已移除，此处只读，用于 301 跳转。
	 *
	 * @return array
	 */
	public function get_map() {
		$map = get_option( self::MAP_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/* =====================================================================
	 * 旧标签 URL 301 重定向
	 * ===================================================================== */

	/**
	 * 旧标签 URL（已被合并）301 重定向到主标签，保住外链权重。
	 *
	 * @return void
	 */
	public function maybe_redirect() {
		if ( is_admin() ) {
			return;
		}
		$map = $this->get_map();
		if ( empty( $map ) ) {
			return;
		}

		$tax  = FAS_Forum::tag_taxonomy();
		$slug = '';

		if ( function_exists( 'is_tax' ) && is_tax( $tax ) ) {
			$term = get_queried_object();
			if ( $term && ! empty( $term->slug ) ) {
				$slug = (string) $term->slug;
			}
		} elseif ( is_404() ) {
			// 标签已删除时归档页会 404，此处从请求路径解析 slug 兜底。
			$req  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
			$path = (string) parse_url( $req, PHP_URL_PATH );
			$path = trim( $path, '/' );
			if ( '' !== $path ) {
				$parts = explode( '/', $path );
				$slug  = rawurldecode( (string) end( $parts ) );
			}
		}

		if ( '' === $slug ) {
			return;
		}

		// slug 可能是编码形式（中文 slug 为 %xx 编码），也可能被解码；
		// 且 rawurlencode 输出大写十六进制、库中存小写，故统一小写比较。
		$map_lc = array();
		foreach ( $map as $k => $v ) {
			$map_lc[ strtolower( (string) $k ) ] = $v;
		}

		$hit = null;
		foreach ( array( $slug, rawurlencode( $slug ), rawurldecode( $slug ) ) as $cand ) {
			$lc = strtolower( $cand );
			if ( isset( $map_lc[ $lc ] ) ) {
				$hit = $map_lc[ $lc ];
				break;
			}
		}
		if ( ! $hit ) {
			return;
		}

		$url = $this->resolve_target_url( $hit, 0 );
		if ( $url ) {
			wp_safe_redirect( $url, 301 );
			exit;
		}
	}

	/**
	 * 解析合并记录的目标 URL（目标若也被合并则沿链跟随，防环）。
	 *
	 * @param array $rec   记录。
	 * @param int   $depth 递归深度。
	 * @return string
	 */
	private function resolve_target_url( $rec, $depth ) {
		if ( $depth > self::MAX_CHAIN_DEPTH ) {
			return '';
		}
		$tax = FAS_Forum::tag_taxonomy();
		$tid = isset( $rec['target_term_id'] ) ? (int) $rec['target_term_id'] : 0;
		if ( $tid ) {
			$link = get_term_link( $tid, $tax );
			if ( ! is_wp_error( $link ) && '' !== $link ) {
				return $link;
			}
		}
		// 目标不存在（可能又被合并）：跟随映射。
		$tslug = isset( $rec['target_slug'] ) ? (string) $rec['target_slug'] : '';
		$map   = $this->get_map();
		if ( '' !== $tslug && isset( $map[ $tslug ] ) ) {
			return $this->resolve_target_url( $map[ $tslug ], $depth + 1 );
		}
		return '';
	}

	/* =====================================================================
	 * 泛词标签 noindex
	 * ===================================================================== */

	/**
	 * 标签归档页命中泛词清单时输出 noindex,follow。
	 *
	 * @param array $robots robots 指令。
	 * @return array
	 */
	public function filter_robots( $robots ) {
		if ( ! function_exists( 'is_tax' ) || ! is_tax( FAS_Forum::tag_taxonomy() ) ) {
			return $robots;
		}
		$term = get_queried_object();
		if ( ! $term || is_wp_error( $term ) ) {
			return $robots;
		}

		if ( '' !== self::noindex_reason( (string) $term->name ) ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/**
	 * 判断标签名是否命中泛词清单。
	 *
	 * 清单为逗号 / 中文逗号 / 顿号分隔；比较时 trim + 小写归一。
	 *
	 * @param string     $term_name 标签名称。
	 * @param array|null $o         配置（null 时自行读取）。
	 * @return string 'generic' | ''
	 */
	public static function noindex_reason( $term_name, $o = null ) {
		if ( null === $o ) {
			$o = FAS_Options::get();
		}

		$list = isset( $o['tag_noindex_generic'] ) ? (string) $o['tag_noindex_generic'] : '';
		if ( '' === trim( $list ) ) {
			return '';
		}

		$needle = mb_strtolower( trim( (string) $term_name ), 'UTF-8' );
		foreach ( preg_split( '/[,，、]/u', $list ) as $w ) {
			$w = mb_strtolower( trim( $w ), 'UTF-8' );
			if ( '' !== $w && $w === $needle ) {
				return 'generic';
			}
		}
		return '';
	}
}
