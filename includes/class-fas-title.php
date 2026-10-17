<?php
/**
 * 帖子 SEO 标题生成（写入子比主题「独立SEO」字段）
 *
 * 子比主题自带「独立SEO」功能（每个帖子一个 meta box，字段 title/keywords/description），
 * 主题在输出 <title>/keywords/description 时优先读取这些独立字段，否则用默认自动生成。
 * 子比主题的 SEO 输出不走 WordPress 标准的 wp_get_document_title()，而是用全局变量
 * $new_title / $new_description / $new_keywords，因此本插件改为：
 *
 *   调用 AI 生成 SEO 三要素（标题 15~30 字、关键词 4~8 个、描述 50~150 字），
 *   直接写入子比主题的独立 SEO post meta 字段，由子比主题自动读取并输出正确的
 *   <title> / meta 标签，不修改帖子原始标题 post_title。
 *
 * 关键：子比主题把标题/关键词/描述序列化存储在「一个」post meta key `zib_other_data` 下
 * （值是一个数组，数组键名即 title/keywords/description），而非三个独立的 post meta key。
 * 这一结论来自子比主题源码：inc/dependent.php 的 zib_get_option_meta_keys('post_meta') 列表
 * 明确包含 title/keywords/description；inc/options/metabox-options.php 的 zib_meta_box_seo_meta()
 * 亦从 get_post_meta($post->ID, 'zib_other_data', true) 读取并回显。
 * 存储 key 通过 `fas_zibll_seo_meta_key` 过滤器 + 后台配置项可调整，数组内键名通过
 * `fas_zibll_seo_meta_keys` 过滤器调整（系统检测页可一键探测真实 key）。
 * 注意：zib_other_data 数组还承载子比主题其他字段（封面图、副标题、积分、付费配置等），
 * 因此回滚时只移除三个 SEO 键，绝不删除整个 key。
 *
 * v1.39.0 起「标题整理」后台页与批量 AJAX 已移除；引擎方法保留：
 * apply_title() 由发布自动链路调用，rollback_title() 可程序调用（仅移除
 * 三个 SEO 键 + 插件自身记录元数据），并自动清理旧版本误写的独立
 * title/keywords/description key。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Title {

	/** @var FAS_Title */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// v1.39.0：「标题整理」后台页与批量/单篇/回滚 AJAX 已移除。
		// AI SEO 引擎本身保留：发布自动链路首次生成摘要时会顺带产出
		// SEO 三要素（FAS_Hooks::maybe_generate_seo → apply_title），
		// 回滚仍可程序调用 rollback_title()。设置页「标题整理」配置
		// （品牌词 / 存储 key 等）继续生效。
	}

	/**
	 * 子比主题「独立SEO」序列化字段的 meta key。
	 *
	 * 子比主题把标题/关键词/描述序列化存储在「一个」post meta key `zib_other_data` 下
	 * （值是一个数组，数组键名即 title/keywords/description）。默认 zib_other_data，
	 * 可通过过滤器 `fas_zibll_seo_meta_key` 或后台设置项覆盖，以兼容不同子比版本差异。
	 *
	 * @return string
	 */
	public static function seo_meta_key() {
		$key = (string) FAS_Options::get_value( 'seo_meta_key', 'zib_other_data' );

		// 兜底纠正：v1.10.0 曾把默认值误设为 posts_seo（子比主题并不读取该 key）。
		// 即便用户已保存过设置、option 里仍是旧值，此处也强制纠正为正确的 zib_other_data。
		if ( '' === $key || 'posts_seo' === $key ) {
			$key = 'zib_other_data';
		}

		return apply_filters( 'fas_zibll_seo_meta_key', $key );
	}

	/**
	 * 序列化数组内三个字段的键名映射。
	 *
	 * 默认键名为 title / keywords / description（子比主题 CSF 字段 id）。
	 * 可通过过滤器 `fas_zibll_seo_meta_keys` 或后台设置项覆盖，以适配不同版本差异。
	 *
	 * @return array {title, keywords, description}
	 */
	public static function seo_field_keys() {
		$keys = array(
			'title'       => (string) FAS_Options::get_value( 'seo_title_key', 'title' ),
			'keywords'    => (string) FAS_Options::get_value( 'seo_keywords_key', 'keywords' ),
			'description' => (string) FAS_Options::get_value( 'seo_description_key', 'description' ),
		);
		return apply_filters( 'fas_zibll_seo_meta_keys', $keys );
	}

	/**
	 * 读取帖子当前的子比「独立SEO」序列化数组。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return array
	 */
	public static function get_seo_data( $post_id ) {
		$data = get_post_meta( $post_id, self::seo_meta_key(), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * 清理旧版本误写的 post meta key。
	 *
	 * - v1.9.0 曾把 SEO 三要素写成 title / keywords / description 三个独立 key，
	 *   子比主题并不读取它们（其 zib_get_post_meta 会把 title/keywords/description
	 *   路由到 zib_other_data 数组），且 title 键极易与其他插件冲突。此处仅当独立
	 *   key 的值与插件自身记录（_fas_seo_*）一致时才删除，避免误删其他数据。
	 * - v1.10.0 曾误写成 posts_seo key（序列化数组），子比主题同样不读取。
	 *
	 * @param int $post_id 帖子 ID。
	 */
	public static function cleanup_legacy_keys( $post_id ) {
		$map = array(
			'title'       => '_fas_seo_title',
			'keywords'    => '_fas_seo_keywords',
			'description' => '_fas_seo_description',
		);
		foreach ( $map as $legacy => $own_key ) {
			if ( $legacy === self::seo_meta_key() ) {
				continue;
			}
			$own = (string) get_post_meta( $post_id, $own_key, true );
			if ( '' !== $own && (string) get_post_meta( $post_id, $legacy, true ) === $own ) {
				delete_post_meta( $post_id, $legacy );
			}
		}

		// 清理 v1.10.0 误写的 posts_seo key（值为数组即视为插件误写）。
		if ( 'posts_seo' !== self::seo_meta_key() ) {
			$legacy_data = get_post_meta( $post_id, 'posts_seo', true );
			if ( is_array( $legacy_data ) ) {
				delete_post_meta( $post_id, 'posts_seo' );
			}
		}

		// 清理子比主题对 zib_other_data 的对象缓存，避免同一请求内读到旧值。
		wp_cache_delete( 'post_meta_' . (int) $post_id, 'zib_option_meta_data' );
	}

	/**
	 * 品牌词（去除重复的目标词；未配置时为空）。
	 *
	 * @return string
	 */
	public static function brand() {
		$brand = trim( (string) FAS_Options::get_value( 'title_brand', '' ) );
		return $brand;
	}

	/**
	 * 分类词列表（逗号分隔，用于匹配「软件类 / 资源类 / 社交类」等）。
	 *
	 * @return array
	 */
	public static function category_keywords() {
		$raw = trim( (string) FAS_Options::get_value( 'title_categories', '软件,资源,社交' ) );
		$arr = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return array_values( $arr );
	}

	/**
	 * 解析标题，拆解出核心标题 / 板块名 / 分类标签 / 品牌词。
	 *
	 * @param string $title 原始标题。
	 * @return array {core, plate, category, brand, brand_count}
	 */
	public static function parse( $title ) {
		$title = trim( (string) $title );
		$brand = self::brand();

		$result = array(
			'core'        => '',
			'plate'       => '',
			'category'    => '',
			'brand'       => $brand,
			'brand_count' => 0,
		);

		if ( '' === $title ) {
			return $result;
		}

		$working = $title;

		// 1) 品牌词：统计出现次数后全部移除（去重的关键）。
		if ( '' !== $brand ) {
			$result['brand_count'] = substr_count( $working, $brand );
			$working               = str_replace( $brand, ' ', $working );
		}

		// 2) 分类标签：优先匹配配置的分类词 +「类」，兜底匹配任意「xx类」。
		$category = self::extract_category( $working );
		if ( '' !== $category ) {
			$result['category'] = $category;
			$working            = str_replace( $category, ' ', $working );
		}

		// 3) 板块名：匹配「xx社区」。
		if ( preg_match( '/[\x{4e00}-\x{9fa5}A-Za-z0-9]{1,24}社区/u', $working, $m ) ) {
			$result['plate'] = $m[0];
			$working         = str_replace( $m[0], ' ', $working );
		}

		// 4) 剩余内容即为核心标题（清理残留分隔符与空白）。
		$result['core'] = self::clean_residue( $working );

		return $result;
	}

	/**
	 * 提取分类标签。
	 *
	 * @param string $working 移除品牌词后的标题。
	 * @return string
	 */
	private static function extract_category( $working ) {
		foreach ( self::category_keywords() as $kw ) {
			if ( '' === $kw ) {
				continue;
			}
			$pattern = '/' . preg_quote( $kw, '/' ) . '类/u';
			if ( preg_match( $pattern, $working, $m ) ) {
				return $m[0];
			}
		}
		// 兜底：任意「中文/字母/数字 + 类」。
		if ( preg_match( '/[\x{4e00}-\x{9fa5}A-Za-z0-9]{1,12}类/u', $working, $m ) ) {
			return $m[0];
		}
		return '';
	}

	/**
	 * 清理残留分隔符、括号与空白，折叠为单个空格。
	 * 保留：中英文、数字、英文句点（版本号如 v5.6.9 需要）。
	 *
	 * @param string $str 待清理字符串。
	 * @return string
	 */
	private static function clean_residue( $str ) {
		$str = preg_replace(
			'/[\x{002D}\x{2013}\x{2014}\x{2015}\x{FF0D}\x{007C}\x{FF5C}\x{002F}\x{005C}\x{FF0C}\x{3002}\x{3001}\x{00B7}\x{FF1A}\x{FF1B}\x{3010}\x{3011}\x{300A}\x{300B}\x{0028}\x{0029}\x{005B}\x{005D}\x{300C}\x{300D}\x{300E}\x{300F}\x{FF08}\x{FF09}\x{FF3B}\x{FF3D}\x{201C}\x{201D}\x{3000}\s]+/u',
			' ',
			$str
		);
		$str = preg_replace( '/\s{2,}/u', ' ', $str );
		return trim( $str );
	}

	/**
	 * 将标题规范化为精简的 SEO 标题。
	 *
	 * 格式：`核心标题 - 板块社区 - 分类 - 品牌词`（无方括号，品牌词后置且仅出现一次）。
	 *
	 * @param string $title 原始标题。
	 * @return string 规范化后的 SEO 标题；无法解析返回空字符串。
	 */
	public static function normalize( $title ) {
		$p = self::parse( $title );

		// 核心标题兜底：解析后为空时，用「清理品牌词后的原标题」兜底。
		$core = $p['core'];
		if ( '' === $core ) {
			$core = self::clean_residue( str_replace( $p['brand'], ' ', (string) $title ) );
		}
		if ( '' === $core ) {
			return '';
		}

		$parts = array( $core );

		// 板块名（xx社区）。
		if ( (int) FAS_Options::get_value( 'title_include_plate', 1 ) && '' !== $p['plate'] ) {
			$parts[] = $p['plate'];
		}

		// 分类标签（xx类）。
		if ( (int) FAS_Options::get_value( 'title_include_category', 1 ) && '' !== $p['category'] ) {
			$parts[] = $p['category'];
		}

		// 品牌词后置（仅保留一次）。
		if ( '' !== $p['brand'] ) {
			$parts[] = $p['brand'];
		}

		// 去重（防止核心标题与板块/分类/品牌意外重复）。
		$parts = array_values( array_unique( $parts ) );

		return implode( ' - ', $parts );
	}

	/**
	 * 判断标题是否过长。
	 *
	 * @param string   $title     标题。
	 * @param int|null $threshold 阈值，null 用配置。
	 * @return bool
	 */
	public static function is_overlong( $title, $threshold = null ) {
		if ( null === $threshold ) {
			$threshold = (int) FAS_Options::get_value( 'title_max_length', 40 );
		}
		return mb_strlen( (string) $title, 'UTF-8' ) > $threshold;
	}

	/**
	 * 为单篇帖子生成 SEO 三要素并写入子比主题「独立SEO」字段（不修改 post_title）。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return array {changed, code, message, ...}
	 */
	public static function apply_title( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! FAS_Forum::is_forum_post( $post->post_type ) ) {
			return array( 'changed' => false, 'code' => 'skip', 'message' => '非论坛帖子' );
		}

		$title   = FAS_Forum::get_title( $post_id );
		$content = FAS_Generator::instance()->clean_content( FAS_Forum::get_content( $post_id ) );

		// 内容过短：不足以提炼有效 SEO，跳过（避免生成垃圾关键词）。
		$min = (int) FAS_Options::get_value( 'min_input_chars', 20 );
		if ( mb_strlen( $content, 'UTF-8' ) < $min ) {
			return array( 'changed' => false, 'code' => 'short', 'message' => '内容过短（' . mb_strlen( $content, 'UTF-8' ) . ' 字），跳过 AI SEO 生成' );
		}

		// 超长截断。
		$max = (int) FAS_Options::get_value( 'max_input_chars', 3000 );
		if ( mb_strlen( $content, 'UTF-8' ) > $max ) {
			$content = mb_substr( $content, 0, $max, 'UTF-8' );
		}

		// 解析上下文（品牌词 / 板块名 / 分类标签）。
		$p       = self::parse( $title );
		$context = array(
			'brand'    => $p['brand'],
			'plate'    => $p['plate'],
			'category' => $p['category'],
		);

		// 调用 AI 生成 SEO 三要素。
		try {
			$api = new FAS_Api();
			$seo = $api->generate_seo( $title, $content, $context );
		} catch ( FAS_Exception $e ) {
			return array( 'changed' => false, 'code' => 'error', 'message' => $e->getMessage() );
		}

		// 写入子比主题「独立SEO」字段（序列化存储，保留已有其他字段）。
		$field = self::seo_field_keys();
		$data  = self::get_seo_data( $post_id );
		$data[ $field['title'] ] = $seo['title'];
		if ( '' !== $seo['keywords'] ) {
			$data[ $field['keywords'] ] = $seo['keywords'];
		}
		if ( '' !== $seo['description'] ) {
			$data[ $field['description'] ] = $seo['description'];
		}
		update_post_meta( $post_id, self::seo_meta_key(), $data );

		// 插件自身记录（供回滚与检测），与子比字段区分。
		update_post_meta( $post_id, '_fas_seo_title', $seo['title'] );
		update_post_meta( $post_id, '_fas_seo_keywords', $seo['keywords'] );
		update_post_meta( $post_id, '_fas_seo_description', $seo['description'] );
		update_post_meta( $post_id, '_fas_seo_generated_at', time() );
		// 记录本次生成所依据的帖子标题：标题被改动后据此判断 SEO 是否过期
		//（FAS_Hooks::on_post_updated → fas_seo_retitle_event 自动重整理）。
		update_post_meta( $post_id, '_fas_seo_source_title', (string) $title );

		// 清理旧版本误写的独立 title/keywords/description key。
		self::cleanup_legacy_keys( $post_id );

		return array(
			'changed'  => true,
			'code'     => 'ok',
			'message'  => 'AI SEO 已生成并写入',
			'title'    => $seo['title'],
			'original' => $title,
		);
	}

	/**
	 * 回滚单篇帖子：删除子比「独立SEO」字段 + 插件自身记录，恢复主题默认 SEO。
	 *
	 * 兼容早期版本：若曾误改过 post_title（存有 _fas_original_title 备份），一并恢复。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return array {rolled_back, code, message, ...}
	 */
	public static function rollback_title( $post_id ) {
		$had = false;

		// 1) 清理旧版本误写的独立 key（需在删除 _fas_seo_* 前执行，以便对比值）。
		self::cleanup_legacy_keys( $post_id );

		// 2) 从子比主题「独立SEO」序列化字段中仅移除 SEO 三要素。
		//    注意：zib_other_data 数组还承载子比主题其他字段（封面图、副标题、积分、
		//    付费配置等），绝不能删除整个 key，只能移除 title/keywords/description 三个键。
		$meta_key = self::seo_meta_key();
		$data     = self::get_seo_data( $post_id );
		$field    = self::seo_field_keys();
		$removed  = false;
		foreach ( $field as $k ) {
			if ( array_key_exists( $k, $data ) && '' !== (string) $data[ $k ] ) {
				unset( $data[ $k ] );
				$removed = true;
				$had     = true;
			}
		}
		if ( $removed ) {
			if ( empty( $data ) ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $data );
			}
		}
		wp_cache_delete( 'post_meta_' . (int) $post_id, 'zib_option_meta_data' );

		// 3) 删除插件自身记录。
		foreach ( array( '_fas_seo_title', '_fas_seo_keywords', '_fas_seo_description', '_fas_seo_generated_at' ) as $key ) {
			if ( '' !== (string) get_post_meta( $post_id, $key, true ) ) {
				$had = true;
				delete_post_meta( $post_id, $key );
			}
		}

		// 4) 迁移兼容：早期版本若修改过 post_title，恢复原标题。
		$orig = (string) get_post_meta( $post_id, '_fas_original_title', true );
		if ( '' !== $orig ) {
			FAS_Generator::$is_writing = true;
			try {
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $orig,
					),
					true
				);
			} finally {
				FAS_Generator::$is_writing = false;
			}
			delete_post_meta( $post_id, '_fas_original_title' );
			$had = true;
		}

		if ( ! $had ) {
			return array( 'rolled_back' => false, 'code' => 'none', 'message' => '无 SEO 记录' );
		}

		return array( 'rolled_back' => true, 'code' => 'ok', 'message' => '已回滚', 'title' => '' !== $orig ? $orig : '' );
	}

}
