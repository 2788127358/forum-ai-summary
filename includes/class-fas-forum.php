<?php
/**
 * Zibll（子比）主题内置论坛适配层
 *
 * 统一封装子比主题内置论坛系统的底层数据结构，屏蔽主题版本差异，
 * 使插件其余模块无需关心具体 post type / 分类法名称。
 *
 * 本适配层与子比主题论坛源码结构一一对应（主题目录 /inc/functions/bbs/）：
 *  - inc/posts.php      -> 帖子 post type：forum_post
 *  - inc/plate.php      -> 版块 post type：plate（帖子通过 meta `plate_id` 关联版块 post）
 *  - inc/term.php       -> 帖子标签分类法 forum_tag、话题分类法 forum_topic
 *  - inc/plate-cat.php  -> 版块分类分类法 plate_cat（用于版块自身分类，与帖子无关）
 *  - inc/functions.php  -> 主题工具函数（zib_bbs_get_plate_id 等）
 *  - inc/single.php     -> 帖子详情页模板（前端摘要展示的落点）
 *  - action/action.php  -> 发帖/编辑动作钩子（均最终走 wp_insert_post）
 *
 * 所有关键名称均支持 apply_filters 覆盖，便于在子比不同版本或二次定制场景下
 * 不改动插件源码即可对齐实际注册结构。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Forum {

	/**
	 * 论坛帖子 post type。
	 *
	 * @return string
	 */
	public static function post_type() {
		return apply_filters( 'fas_forum_post_type', 'forum_post' );
	}

	/**
	 * 版块 post type。
	 *
	 * @return string
	 */
	public static function plate_post_type() {
		return apply_filters( 'fas_plate_post_type', 'plate' );
	}

	/**
	 * 帖子标签分类法。
	 *
	 * @return string
	 */
	public static function tag_taxonomy() {
		return apply_filters( 'fas_forum_tag_taxonomy', 'forum_tag' );
	}

	/**
	 * 帖子话题分类法。
	 *
	 * @return string
	 */
	public static function topic_taxonomy() {
		return apply_filters( 'fas_forum_topic_taxonomy', 'forum_topic' );
	}

	/**
	 * 版块分类分类法（仅用于版块自身分类，插件不写入，此处供检测/展示）。
	 *
	 * @return string
	 */
	public static function plate_cat_taxonomy() {
		return apply_filters( 'fas_plate_cat_taxonomy', 'plate_cat' );
	}

	/**
	 * 帖子关联版块的 meta 键名。
	 *
	 * @return string
	 */
	public static function plate_meta_key() {
		return apply_filters( 'fas_plate_meta_key', 'plate_id' );
	}

	/**
	 * 判断当前主题是否为子比（Zibll）主题。
	 * 依据：主题目录存在论坛入口 bbs.php，或已加载 zib_bbs_* 工具函数。
	 *
	 * @return bool
	 */
	public static function is_zibll_theme() {
		static $is = null;
		if ( null !== $is ) {
			return $is;
		}

		$is = false;
		$theme = wp_get_theme();
		$bbs_entry = trailingslashit( get_template_directory() ) . 'inc/functions/bbs/bbs.php';

		if ( file_exists( $bbs_entry ) ) {
			$is = true;
		} elseif ( function_exists( 'zib_bbs_get_plate_id' ) ) {
			$is = true;
		} elseif ( false !== stripos( (string) $theme->get( 'Name' ), 'zibll' ) ) {
			$is = true;
		}

		return $is;
	}

	/**
	 * 判断子比主题论坛是否已启用（论坛帖子类型已注册）。
	 *
	 * @return bool
	 */
	public static function is_active() {
		return post_type_exists( self::post_type() );
	}

	/**
	 * 判断给定 post type 是否为论坛帖子。
	 *
	 * @param string $post_type 文章类型。
	 * @return bool
	 */
	public static function is_forum_post( $post_type ) {
		return self::post_type() === $post_type;
	}

	/**
	 * 判断给定 post type 是否为论坛版块。
	 *
	 * @param string $post_type 文章类型。
	 * @return bool
	 */
	public static function is_plate( $post_type ) {
		return self::plate_post_type() === $post_type;
	}

	/**
	 * 获取帖子所在版块 ID。
	 * 优先使用主题函数，回退到 meta plate_id。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return int|string 版块 post ID，不存在返回 0/空。
	 */
	public static function get_plate_id( $post_id ) {
		if ( function_exists( 'zib_bbs_get_plate_id' ) ) {
			$plate_id = zib_bbs_get_plate_id( $post_id );
			if ( $plate_id ) {
				return $plate_id;
			}
		}
		return get_post_meta( $post_id, self::plate_meta_key(), true );
	}

	/**
	 * 获取帖子所在版块名称。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return string
	 */
	public static function get_plate_name( $post_id ) {
		$plate_id = self::get_plate_id( $post_id );
		return $plate_id ? get_the_title( $plate_id ) : '';
	}

	/**
	 * 获取帖子用于 AI 的正文内容。
	 * 默认取原生 post_content，可通过 fas_post_content 过滤器在子比
	 * 二次定制（如正文存于 meta）时替换取数来源。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return string
	 */
	public static function get_content( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		return apply_filters( 'fas_post_content', $post->post_content, $post );
	}

	/**
	 * 获取帖子用于 AI 的标题。
	 * 默认取原生 post_title，可通过 fas_post_title 过滤器替换。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return string
	 */
	public static function get_title( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		return apply_filters( 'fas_post_title', $post->post_title, $post );
	}

	/**
	 * 帖子标签分类法是否存在。
	 *
	 * @return bool
	 */
	public static function tag_taxonomy_exists() {
		return taxonomy_exists( self::tag_taxonomy() );
	}

	/**
	 * 读取帖子当前标签名称数组。
	 *
	 * @param int $post_id 帖子 ID。
	 * @return array
	 */
	public static function get_tags( $post_id ) {
		if ( ! self::tag_taxonomy_exists() ) {
			return array();
		}
		$terms = wp_get_object_terms( $post_id, self::tag_taxonomy(), array( 'fields' => 'names' ) );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * 将标签写回帖子（追加或替换）。
	 *
	 * @param int   $post_id 帖子 ID。
	 * @param array $tags    标签数组。
	 * @param bool  $append  是否追加。
	 * @return bool
	 */
	public static function set_tags( $post_id, $tags, $append = true ) {
		if ( ! self::tag_taxonomy_exists() || empty( $tags ) ) {
			return false;
		}
		$result = wp_set_object_terms( $post_id, $tags, self::tag_taxonomy(), $append );
		return ! is_wp_error( $result );
	}

	/**
	 * 获取论坛中已存在的标签名称数组（用于「复用已有标签」模式）。
	 *
	 * @return array
	 */
	public static function get_existing_tag_names() {
		if ( ! self::tag_taxonomy_exists() ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => self::tag_taxonomy(),
				'hide_empty' => false,
				'fields'     => 'names',
			)
		);
		return is_wp_error( $terms ) ? array() : (array) $terms;
	}

	/**
	 * 仅将「论坛中已存在」的标签写回帖子（复用已有 term，不新建）。
	 * 用于避免 AI 生成大量一次性标签、造成标签归档页膨胀（对 SEO 友好）。
	 *
	 * @param int   $post_id 帖子 ID。
	 * @param array $tags    标签数组。
	 * @param bool  $append  是否追加。
	 * @return bool
	 */
	public static function set_existing_tags( $post_id, $tags, $append = true ) {
		if ( ! self::tag_taxonomy_exists() || empty( $tags ) ) {
			return false;
		}

		$existing = self::get_existing_tag_names();
		if ( empty( $existing ) ) {
			return false;
		}

		// 大小写不敏感映射，保留原 term 名称。
		$map = array();
		foreach ( $existing as $name ) {
			$map[ mb_strtolower( (string) $name, 'UTF-8' ) ] = $name;
		}

		$matched = array();
		foreach ( $tags as $tag ) {
			$key = mb_strtolower( (string) $tag, 'UTF-8' );
			if ( isset( $map[ $key ] ) ) {
				$matched[] = $map[ $key ];
			}
		}

		if ( empty( $matched ) ) {
			return false;
		}

		$result = wp_set_object_terms( $post_id, $matched, self::tag_taxonomy(), $append );
		return ! is_wp_error( $result );
	}

	/**
	 * 获取全部版块列表（供批量处理页筛选等使用）。
	 *
	 * @return WP_Post[]
	 */
	public static function get_all_plates() {
		if ( ! post_type_exists( self::plate_post_type() ) ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'      => self::plate_post_type(),
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * 论坛帖子总数（已发布）。
	 *
	 * @return int
	 */
	public static function post_count() {
		if ( ! self::is_active() ) {
			return 0;
		}
		$counts = wp_count_posts( self::post_type() );
		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * 系统检测：返回各集成点的存活状态，供后台「系统检测」页展示。
	 * 用于在子比版本差异时快速定位结构不一致。
	 *
	 * @return array
	 */
	public static function detect() {
		$theme = wp_get_theme();

		return array(
			'theme'          => array(
				'name'      => $theme->get( 'Name' ),
				'version'   => $theme->get( 'Version' ),
				'is_zibll'  => self::is_zibll_theme(),
			),
			'post_type'      => array(
				'name'       => self::post_type(),
				'registered' => post_type_exists( self::post_type() ),
				'count'      => self::post_count(),
			),
			'plate_post_type' => array(
				'name'       => self::plate_post_type(),
				'registered' => post_type_exists( self::plate_post_type() ),
			),
			'tag_taxonomy'   => array(
				'name'       => self::tag_taxonomy(),
				'registered' => taxonomy_exists( self::tag_taxonomy() ),
			),
			'topic_taxonomy' => array(
				'name'       => self::topic_taxonomy(),
				'registered' => taxonomy_exists( self::topic_taxonomy() ),
			),
			'plate_cat_taxonomy' => array(
				'name'       => self::plate_cat_taxonomy(),
				'registered' => taxonomy_exists( self::plate_cat_taxonomy() ),
			),
			'helpers'        => array(
				'zib_bbs_get_plate_id' => function_exists( 'zib_bbs_get_plate_id' ),
				'plate_meta_key'       => self::plate_meta_key(),
			),
			'plugin'         => array(
				'is_active' => self::is_active(),
				'configured' => FAS_Options::is_configured(),
			),
		);
	}
}
