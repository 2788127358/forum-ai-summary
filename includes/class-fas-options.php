<?php
/**
 * 配置项管理类
 *
 * 所有配置扁平化存储于单个 option `fas_options` 中，
 * 通过 wp_parse_args 与默认值合并，避免嵌套数组合并问题。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Options {

	/**
	 * 默认配置。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// 基本
			'enabled'            => 1,                 // 总开关
			'provider'           => 'openai',          // openai|deepseek|anthropic|custom
			'auto_on_publish'    => 1,                 // 发布时自动生成
			'overwrite_existing' => 0,                 // 是否覆盖已存在的摘要/标签

			// OpenAI
			'openai_api_key'     => '',
			'openai_base_url'    => 'https://api.openai.com/v1',
			'openai_model'       => 'gpt-4o-mini',

			// DeepSeek
			'deepseek_api_key'   => '',
			'deepseek_base_url'  => 'https://api.deepseek.com',
			'deepseek_model'     => 'deepseek-chat',

			// Anthropic
			'anthropic_api_key'  => '',
			'anthropic_base_url' => 'https://api.anthropic.com',
			'anthropic_model'    => 'claude-3-5-haiku-latest',
			'anthropic_version'  => '2023-06-01',

			// 自定义（OpenAI 兼容）
			'custom_api_key'     => '',
			'custom_base_url'    => '',
			'custom_model'       => '',
			'custom_chat_path'   => '/v1/chat/completions',

			// 模型参数
			'temperature'        => 0.3,
			'max_tokens'         => 500,

			// 摘要
			'summary_enabled'    => 1,                 // 是否生成摘要
			'write_excerpt'      => 1,                 // 是否写回帖子原生摘要字段 post_excerpt（子比论坛帖子无独立摘要字段）
			'length_mode'        => 'chars',           // chars 字数 | sentences 句子数
			'summary_chars'      => 120,               // 目标字数
			'summary_sentences'  => 3,                 // 目标句子数
			'summary_style'      => 'summary',         // summary 概括型 | bullets 要点提炼型
			'prefix'             => '',                // 摘要前缀，例如「本主题介绍了」

			// 标签
			'tags_enabled'       => 1,                 // 是否生成标签
			'tags_count'         => 3,                 // 标签数量（上限）
			'tags_lang'          => 'zh',              // zh 中文 | bilingual 中英双语
			'tags_append'        => 1,                 // 追加到已有标签还是替换
			'tag_slug_pinyin'    => 1,                 // 新建标签自动生成拼音别名（只影响新标签，存量不动）
	
			// 内容约束
			'max_input_chars'    => 3000,              // 提交给模型的最大字符数（超出截断）
			'min_input_chars'    => 20,                // 低于此长度跳过 AI 调用
			'short_text'         => '本主题内容过短，暂无摘要', // 内容过短时的降级文案

			// 重试
			'max_retries'        => 3,                 // 失败最大重试次数
			'retry_delay'        => 5,                 // 退避基数（秒），第 n 次等待 n*retry_delay

			// 请求
			'timeout'            => 30,                // 单次请求超时（秒）

			// 展示
			'show_in_content'    => 0,                 // 是否在前端帖子顶部自动展示摘要框
			'avatar_url'         => '',                // 摘要模块顶部圆形头像 URL（留空使用内置 AI 头像）

			// 相关推荐（帖子正文末尾）
			'related_enabled'    => 1,                 // 是否在帖子正文末尾展示「相关推荐」横向滚动模块
			'related_count'      => 8,                 // 推荐帖子数量（随机取 8 篇）
			'related_balance'    => 'fair',            // 内链均衡模式：off 关闭 | random 随机采样 | fair 随机+曝光反向加权
			'related_plate_max'  => 0,                 // 单次推荐中同一板块数量上限（0 = 不限）

			// 标题整理（AI SEO：生成三要素并写入子比「独立SEO」字段）
			'title_brand'         => '',                // 品牌词（SEO 标题末尾追加，留空则不追加）
			'title_categories'    => '软件,资源,社交',  // 分类词列表（逗号分隔，用于识别分类标签上下文）
			'title_include_category' => 1,             // 保留（AI 生成时作为上下文提示）
			'title_include_plate'    => 1,             // 保留（AI 生成时作为上下文提示）
			'title_max_length'       => 40,            // 标题「过长」判定阈值（字符数）

			// 子比主题「独立SEO」字段的真实存储：标题/关键词/描述三个字段
			// 统一存储在 post meta key `zib_other_data` 里（值是一个序列化数组，
			// 数组键名即 title / keywords / description），而不是三个独立的 post meta key。
			// 这是从子比主题源码 inc/dependent.php 的 zib_get_option_meta_keys('post_meta')
			// 与 inc/options/metabox-options.php 的 zib_meta_box_seo_meta() 中确认的。
			// 注意：zib_other_data 数组里还包含子比主题其他几十个字段（封面图、副标题、
			// 积分、付费配置等），所以回滚时只能移除这三个 SEO 键，绝不能删除整个 key。
			'seo_meta_key'        => 'zib_other_data', // 独立SEO 所在的 post meta key（序列化数组）
			'seo_title_key'       => 'title',          // 序列化数组内的「标题」键名
			'seo_keywords_key'    => 'keywords',       // 序列化数组内的「关键词」键名
			'seo_description_key' => 'description',    // 序列化数组内的「描述」键名

			// 标签治理：薄标签归档页 noindex（对低质标签归档页输出 noindex,follow）
			'tag_noindex_generic' => '工具,娱乐,系统,下载',              // 泛词标签：归档页输出 noindex,follow

			// 正文自动内链（AI 语义内链）
			// 注意：默认关闭。开启后需要先到「论坛AI摘要 → 自动内链」页批量生成映射，
			// 生成的是「帖子ID → 目标帖 + 锚文本候选」记录，真正的 <a> 在页面输出时
			// 才由 the_content 临时插入，不改动数据库原文、关掉即全部消失。
			'ilink_enabled'        => 0,               // 是否启用正文自动内链
			'ilink_per_post'       => 1,               // 每帖正文内链数量（1–5）——默认 1 条
			'ilink_candidates'     => 20,              // 交给 AI 挑选的候选帖数量（5–40）
			'ilink_min_chars'      => 120,             // 正文低于此字数不生成内链
			'ilink_exclude_plates' => '',                // 排除板块 ID（逗号分隔，留空则不排除）
		);
	}

	/**
	 * 获取合并后的配置。
	 *
	 * @return array
	 */
	public static function get() {
		static $options = null;
		if ( null === $options ) {
			$options = wp_parse_args( (array) get_option( 'fas_options', array() ), self::defaults() );
		}
		return $options;
	}

	/**
	 * 读取单个配置项。
	 *
	 * @param string $key     配置键名。
	 * @param mixed  $default 默认值。
	 * @return mixed
	 */
	public static function get_value( $key, $default = null ) {
		$options = self::get();
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * 获取当前供应商的配置片段。
	 *
	 * @return array {provider, api_key, base_url, model, chat_path, anthropic_version}
	 */
	public static function provider_config() {
		$options  = self::get();
		$provider = $options['provider'];

		$config = array(
			'provider'          => $provider,
			'api_key'           => '',
			'base_url'          => '',
			'model'             => '',
			'chat_path'         => '/v1/chat/completions',
			'anthropic_version' => '2023-06-01',
		);

		switch ( $provider ) {
			case 'openai':
				$config['api_key']  = $options['openai_api_key'];
				$config['base_url'] = $options['openai_base_url'];
				$config['model']    = $options['openai_model'];
				break;
			case 'deepseek':
				$config['api_key']  = $options['deepseek_api_key'];
				$config['base_url'] = $options['deepseek_base_url'];
				$config['model']    = $options['deepseek_model'];
				break;
			case 'anthropic':
				$config['api_key']           = $options['anthropic_api_key'];
				$config['base_url']          = $options['anthropic_base_url'];
				$config['model']             = $options['anthropic_model'];
				$config['chat_path']         = '/v1/messages';
				$config['anthropic_version'] = $options['anthropic_version'];
				break;
			default: // custom
				$config['api_key']   = $options['custom_api_key'];
				$config['base_url']  = $options['custom_base_url'];
				$config['model']     = $options['custom_model'];
				$config['chat_path'] = $options['custom_chat_path'];
				break;
		}

		return $config;
	}

	/**
	 * 判断当前配置是否可用（已填写密钥、地址与模型）。
	 *
	 * @return bool
	 */
	public static function is_configured() {
		if ( ! self::get_value( 'enabled', 0 ) ) {
			return false;
		}
		$c = self::provider_config();
		return ! empty( $c['api_key'] ) && ! empty( $c['base_url'] ) && ! empty( $c['model'] );
	}
}
