<?php
/**
 * 新建标签的拼音别名（slug）
 *
 * 为什么只做「新标签」：中文标签的 slug 是百分号编码（下载 → %e4%b8%8b%e8%bd%bd），
 * Google 官方《URL structure》明确建议「用受众语言」并对非 ASCII 字符做百分号编码，
 * 因此换成英文/拼音**不会带来排名收益**，收益只在可读性（分享链接不再是一串 %xx）。
 * 老标签改 slug = 换 URL，旧链接会 404、需要逐个 301、索引要重算，代价明显大于收益，
 * 所以本类只在 `created_term` 时给**新标签**生成拼音别名，存量标签一律不动。
 *
 * 触发条件（全部满足才改）：
 *   1. 分类法是论坛标签分类法（FAS_Forum::tag_taxonomy()）；
 *   2. 开关 `tag_slug_pinyin` 开启（默认开）；
 *   3. 当前 slug 仍是 WordPress 自动生成的（等于 sanitize_title(name)），即用户没有手动设过别名；
 *   4. 当前 slug 不是纯 ASCII（已经是英文别名的跳过）；
 *   5. 名称能完整转成拼音（含生僻字则整体放弃，保留原 slug，绝不产出半截别名）。
 *
 * 生成结果示例：电视直播 → dian-shi-zhi-bo；APK下载 → apk-xia-zai；绿色版 → lv-se-ban。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Slug {

	/** 拼音别名最长长度（slug 上限，超出截断）。 */
	const MAX_LENGTH = 190;

	/** @var FAS_Slug */
	private static $instance = null;

	/** @var array|null 字 → 拼音 映射（懒加载）。 */
	private static $map = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 优先级 20：排在 FAS_Seo_Description（10）之后，避免与描述调度互相干扰。
		add_action( 'created_term', array( $this, 'maybe_set_slug' ), 20, 3 );
	}

	/**
	 * 新建标签时把中文名转成拼音别名。
	 *
	 * @param int    $term_id  标签 ID。
	 * @param int    $tt_id    term_taxonomy_id（未使用）。
	 * @param string $taxonomy 分类法。
	 */
	public function maybe_set_slug( $term_id, $tt_id, $taxonomy ) {
		if ( FAS_Forum::tag_taxonomy() !== $taxonomy ) {
			return;
		}

		$options = FAS_Options::get();
		if ( ! isset( $options['tag_slug_pinyin'] ) || empty( $options['tag_slug_pinyin'] ) ) {
			return;
		}

		$term = get_term( (int) $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) || empty( $term->name ) ) {
			return;
		}

		$slug = (string) $term->slug;

		// 用户手动设过别名：不动。同名标签被 WP 加后缀（xxx-2）仍算自动生成，一并放行。
		$auto = sanitize_title( $term->name );
		if ( $slug !== $auto && ! preg_match( '/^' . preg_quote( $auto, '/' ) . '-\d+$/', $slug ) ) {
			return;
		}

		// 已经是 ASCII 别名（英文标签）：不动。
		if ( preg_match( '/^[a-z0-9\-]+$/', $slug ) ) {
			return;
		}

		$pinyin = self::transliterate( $term->name );
		if ( '' === $pinyin || $pinyin === $slug ) {
			return;
		}

		if ( function_exists( 'wp_unique_term_slug' ) ) {
			$pinyin = wp_unique_term_slug( $pinyin, $term );
		}

		wp_update_term( (int) $term_id, $taxonomy, array( 'slug' => $pinyin ) );
	}

	/**
	 * 把标签名转成拼音 slug（纯函数，便于测试）。
	 *
	 * 规则：ASCII 字母数字按词聚拢并小写；汉字逐字取拼音、以 `-` 连接；
	 * 空格/标点/符号当分隔符；出现映射表里没有的汉字则返回空串（调用方据此保留原 slug）。
	 *
	 * @param string $name 标签名。
	 * @return string 拼音 slug，失败返回 ''。
	 */
	public static function transliterate( $name ) {
		$name = (string) $name;
		if ( '' === trim( $name ) ) {
			return '';
		}

		$map = self::map();
		$parts = array();
		$buf   = '';

		foreach ( preg_split( '//u', $name, -1, PREG_SPLIT_NO_EMPTY ) as $ch ) {
			if ( preg_match( '/^[A-Za-z0-9]$/', $ch ) ) {
				$buf .= strtolower( $ch );
				continue;
			}

			if ( preg_match( '/^[\x{4e00}-\x{9fff}]$/u', $ch ) ) {
				if ( ! isset( $map[ $ch ] ) ) {
					return ''; // 生僻字：整体放弃，避免产出半截别名。
				}
				if ( '' !== $buf ) {
					$parts[] = $buf;
					$buf     = '';
				}
				$parts[] = $map[ $ch ];
				continue;
			}

			// 其它字符（空格、标点、符号、全角字符）一律当分隔符。
			if ( '' !== $buf ) {
				$parts[] = $buf;
				$buf     = '';
			}
		}

		if ( '' !== $buf ) {
			$parts[] = $buf;
		}
		if ( empty( $parts ) ) {
			return '';
		}

		$slug = implode( '-', $parts );
		$slug = preg_replace( '/-+/', '-', $slug );
		$slug = trim( (string) $slug, '-' );

		if ( '' === $slug ) {
			return '';
		}

		return substr( $slug, 0, self::MAX_LENGTH );
	}

	/**
	 * 读取并解析拼音映射表（只读一次，静态缓存）。
	 *
	 * @return array 字 => 拼音。
	 */
	public static function map() {
		if ( null !== self::$map ) {
			return self::$map;
		}

		$file = FAS_PLUGIN_DIR . 'includes/data/pinyin-map.php';
		$raw  = is_readable( $file ) ? require $file : '';
		$map  = array();

		if ( is_string( $raw ) && '' !== $raw ) {
			foreach ( explode( '|', $raw ) as $pair ) {
				$pos = strpos( $pair, ':' );
				if ( false === $pos ) {
					continue;
				}
				$map[ substr( $pair, 0, $pos ) ] = substr( $pair, $pos + 1 );
			}
		}

		self::$map = $map;
		return self::$map;
	}
}
