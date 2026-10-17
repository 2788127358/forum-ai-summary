<?php
/**
 * 多模型 API 客户端
 *
 * 统一封装 OpenAI / DeepSeek / Anthropic / 自定义(OpenAI 兼容) 的请求与响应解析，
 * 调用方通过 generate() 获得结构化结果，失败时抛出 FAS_Exception。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 自定义异常：附带是否可重试标记。
 */
class FAS_Exception extends Exception {
	/** @var bool 是否建议重试 */
	public $retryable = false;

	/**
	 * @param string $message   错误描述。
	 * @param bool   $retryable 是否可重试（限流/5xx/超时/网络 = true）。
	 * @param int    $code      HTTP 状态码或自定义码。
	 */
	public function __construct( $message = '', $retryable = false, $code = 0 ) {
		parent::__construct( $message, (int) $code );
		$this->retryable = $retryable;
	}
}

class FAS_Api {

	/**
	 * 生成摘要与标签。
	 *
	 * @param string $title   主题标题。
	 * @param string $content 已清洗的纯文本正文。
	 * @return array {summary: string, tags: array|string}（tags 为字符串时由 FAS_Tags::normalize 拆分）
	 * @throws FAS_Exception
	 */
	public function generate( $title, $content ) {
		$options = FAS_Options::get();
		$config  = FAS_Options::provider_config();

		if ( empty( $config['api_key'] ) ) {
			throw new FAS_Exception( '未配置 API 密钥', false );
		}
		if ( empty( $config['base_url'] ) || empty( $config['model'] ) ) {
			throw new FAS_Exception( 'API 地址或模型名称为空', false );
		}

		// 摘要 + 标签联合输出需要较多 token：临时抬高下限，避免 max_tokens 过低
		// 导致模型输出被截断、tags 数组被丢弃（表现为「摘要成功但标签为空」）。
		$options['max_tokens'] = max( (int) $options['max_tokens'], 600 );

		$messages = $this->build_messages( $title, $content, $options );

		if ( 'anthropic' === $config['provider'] ) {
			$result = $this->request_anthropic( $config, $messages, $options );
		} else {
			$result = $this->request_openai_compatible( $config, $messages, $options );
		}

		return $this->parse_result( $result );
	}

	/**
	 * 仅生成标签（不生成摘要）。
	 *
	 * 独立于 generate()：使用专用标签提示词、输出体量更小、更稳定，
	 * 专用于「单独补标签」场景，避免摘要+标签联合输出时因 max_tokens
	 * 截断或模型不稳定导致标签丢失。
	 *
	 * @param string $title   主题标题。
	 * @param string $content 已清洗的纯文本正文。
	 * @return array 归一化后的标签数组（字符串数组）。
	 * @throws FAS_Exception
	 */
	public function generate_tags( $title, $content ) {
		$options = FAS_Options::get();
		$config  = FAS_Options::provider_config();
		$this->assert_configured( $config );

		$count = max( 1, min( 10, (int) $options['tags_count'] ) );
		$lang  = FAS_Tags::lang_instruction( $options['tags_lang'] );

		$system = '你是一名中文论坛内容编辑助手。请阅读给定的论坛主题帖，'
			. '为它提炼 ' . $count . ' 个与主题高度相关的标签，兼顾 SEO 优化。'
			. "\n\n要求："
			. "\n- 每个标签 2~8 个字符（中文以 2~6 字为宜）；"
			. "\n- 使用具体关键词而非泛化词汇，避免「教程」「分享」「讨论」「经验」「心得」「求助」等泛化词；"
			. "\n- 覆盖帖子的核心名词、技术点或领域词，提升搜索引擎对主题的识别度；"
			. "\n- 避免与标题重复。" . $lang
			. "\n\n严格只输出一个 JSON 对象，不要任何解释、前后缀或 Markdown 代码块。格式："
			. "\n{\"tags\": [\"标签1\", \"标签2\", \"标签3\"]}"
			. "\n注意：tags 必须是非空字符串数组，请务必返回 " . $count . ' 个具体标签。';

		$user = '主题标题：' . $title . "\n\n主题正文：\n" . $content;

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		);

		// 结构化输出：开启 JSON 模式。
		$result = $this->request( $config, $messages, $options, true );

		$data = $this->extract_json( $result );
		if ( ! is_array( $data ) ) {
			throw new FAS_Exception( '无法解析标签生成结果', true );
		}

		$tags = array();
		if ( isset( $data['tags'] ) ) {
			$tags = $data['tags'];
		} elseif ( isset( $data['标签'] ) ) {
			$tags = $data['标签'];
		} elseif ( $this->is_list( $data ) ) {
			// 兜底：模型可能直接返回 ["标签1","标签2"] 而非 {"tags":[...]}。
			$tags = $data;
		}

		if ( is_string( $tags ) ) {
			$tags = preg_split( '/[,，、;；|\n]+/u', $tags );
		}
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		$normalized = FAS_Tags::normalize( $tags, $count );
		if ( empty( $normalized ) ) {
			throw new FAS_Exception( '模型未返回有效标签', true );
		}

		return $normalized;
	}

	/**
	 * 生成 SEO 三要素：标题 / 关键词 / 描述。
	 *
	 * @param string $title   帖子标题。
	 * @param string $content 已清洗的纯文本正文。
	 * @param array  $context 上下文信息 {brand, plate, category}。
	 * @return array {title: string, keywords: string, description: string}
	 * @throws FAS_Exception
	 */
	public function generate_seo( $title, $content, $context = array() ) {
		$options = FAS_Options::get();
		$config  = FAS_Options::provider_config();

		if ( empty( $config['api_key'] ) ) {
			throw new FAS_Exception( '未配置 API 密钥', false );
		}
		if ( empty( $config['base_url'] ) || empty( $config['model'] ) ) {
			throw new FAS_Exception( 'API 地址或模型名称为空', false );
		}

		$messages = $this->build_seo_messages( $title, $content, $context );

		if ( 'anthropic' === $config['provider'] ) {
			$result = $this->request_anthropic( $config, $messages, $options );
		} else {
			$result = $this->request_openai_compatible( $config, $messages, $options );
		}

		return $this->parse_seo( $result );
	}

	/**
	 * 生成标签归档页 SEO 描述（60~120 字）。
	 *
	 * @param string   $term_name 标签词。
	 * @param string[] $titles    该标签下部分帖子标题（作为「包含哪些」的归纳素材）。
	 * @return string
	 * @throws FAS_Exception
	 */
	public function generate_term_description( $term_name, $titles = array() ) {
		$options = FAS_Options::get();
		$config  = FAS_Options::provider_config();
		$this->assert_configured( $config );

		$system = '你是一名资深 SEO 优化专家，精通中文搜索引擎（百度、Google）的元数据优化。'
			. '请为论坛标签「' . $term_name . '」的归档页撰写一段 SEO 描述（description），'
			. '用于该标签归档页的 <meta name="description">。'
			. "\n\n结构要求（按顺序三个层次，一段连贯文字）："
			. "\n1. 是什么：一句话点明「" . $term_name . '」的含义或定位；'
			. "\n2. 包含哪些：说明该标签下收录/汇总了哪些内容（结合下方示例帖子标题归纳）；"
			. "\n3. 怎么选：说明读者可按什么维度选择、或能获得什么价值。"
			. "\n\n写作要求："
			. "\n- 60~120 个中文字符；"
			. "\n- 直接以标签词或核心名词开头，禁止「本帖介绍了」「本文介绍了」「本标签」等空泛开头；"
			. "\n- 自然融入 2~3 个关键词，不堆砌；通顺、有吸引力。"
			. "\n\n严格只输出一段纯文本，不要 JSON、不要任何解释、不要 Markdown 代码块。";

		$material = '';
		if ( ! empty( $titles ) ) {
			$material = "\n\n该标签下部分帖子标题（供归纳素材）：\n- " . implode( "\n- ", array_map( 'strval', $titles ) );
		}

		$user = '标签词：' . $term_name . $material;

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		);

		// 纯文本场景：关闭 JSON 模式，避免模型返回 {"type":"json_object","content":"..."} 包装。
		$result = $this->request( $config, $messages, $options, false );
		return $this->extract_plain_text( $result );
	}

	/**
	 * 生成板块页 SEO 描述（60~120 字）。
	 *
	 * @param string   $plate_name 板块名称。
	 * @param string[] $titles     板块下部分帖子标题。
	 * @param string   $summary    板块自身简介（post_content 纯文本）。
	 * @return string
	 * @throws FAS_Exception
	 */
	public function generate_plate_description( $plate_name, $titles = array(), $summary = '' ) {
		$options = FAS_Options::get();
		$config  = FAS_Options::provider_config();
		$this->assert_configured( $config );

		$system = '你是一名资深 SEO 优化专家，精通中文搜索引擎（百度、Google）的元数据优化。'
			. '请为论坛板块「' . $plate_name . '」的页面撰写一段 SEO 描述（description），'
			. '用于该板块页的 <meta name="description">。'
			. "\n\n结构要求（一段连贯文字，覆盖三个层次）："
			. "\n1. 是什么：一句话点明「" . $plate_name . '」板块的定位；'
			. "\n2. 包含哪些：说明该板块收录/汇总了哪些内容（结合下方示例帖子标题归纳）；"
			. "\n3. 怎么选/有什么用：说明读者能获得什么价值。"
			. "\n\n写作要求："
			. "\n- 60~120 个中文字符；"
			. "\n- 直接以板块名或核心名词开头，禁止「本帖介绍了」「本文介绍了」「欢迎来到」等空泛开头；"
			. "\n- 自然融入 2~3 个关键词，不堆砌；通顺、有吸引力。"
			. "\n\n严格只输出一段纯文本，不要 JSON、不要任何解释、不要 Markdown 代码块。";

		$user = '板块名称：' . $plate_name;
		if ( '' !== trim( (string) $summary ) ) {
			$user .= "\n板块简介：\n" . trim( (string) $summary );
		}
		if ( ! empty( $titles ) ) {
			$user .= "\n\n该板块下部分帖子标题（供归纳素材）：\n- " . implode( "\n- ", array_map( 'strval', $titles ) );
		}

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		);

		// 纯文本场景：关闭 JSON 模式，避免模型返回 {"type":"json_object","content":"..."} 包装。
		$result = $this->request( $config, $messages, $options, false );
		return $this->extract_plain_text( $result );
	}

	/**
	 * 批量生成多个标签归档页的 SEO 描述（一次 API 调用）。
	 *
	 * @param array $terms 标签列表，每项 array{name:string, titles:string[]}。
	 * @return array 以标签名为键、描述为值的映射 array{name: description}。
	 * @throws FAS_Exception
	 */
	public function generate_term_descriptions_batch( $terms ) {
		$options = FAS_Options::get();
		$config  = FAS_Options::provider_config();
		$this->assert_configured( $config );

		$count = count( $terms );

		$system = '你是一名资深 SEO 优化专家，精通中文搜索引擎（百度、Google）的元数据优化。'
			. '请为下面 ' . $count . ' 个论坛标签的归档页，分别撰写一段 SEO 描述（description），用于各自标签归档页的 <meta name="description">。'
			. "\n\n每个描述的结构要求（一段连贯文字，覆盖三个层次）："
			. "\n1. 是什么：一句话点明该标签的含义或定位；"
			. "\n2. 包含哪些：说明该标签下收录/汇总了哪些内容（结合下方各标签的示例帖子标题归纳）；"
			. "\n3. 怎么选：说明读者可按什么维度选择、或能获得什么价值。"
			. "\n\n写作要求："
			. "\n- 每段 60~120 个中文字符；"
			. "\n- 直接以标签词或核心名词开头，禁止「本帖介绍了」「本文介绍了」「本标签」等空泛开头；"
			. "\n- 自然融入 2~3 个关键词，不堆砌；通顺、有吸引力。"
			. "\n\n严格只输出一个 JSON 对象（键名为标签词、值为描述字符串），不要数组、不要任何解释、前后缀或 Markdown 代码块。格式："
			. "\n{\"标签词1\":\"描述1\",\"标签词2\":\"描述2\"}"
			. "\n键名必须与下方给出的标签词完全一致，不得遗漏、不得新增。";

		$user = '请为以下 ' . $count . " 个标签分别撰写描述：\n";
		foreach ( $terms as $i => $t ) {
			$user .= "\n" . ( $i + 1 ) . '. 标签「' . $t['name'] . '」';
			if ( ! empty( $t['titles'] ) ) {
				$user .= "\n   关联帖子标题（供归纳）：" . implode( '、', array_map( 'strval', array_slice( $t['titles'], 0, 8 ) ) );
			}
		}

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		);

		// 批量生成 token 与耗时更高，临时扩大 max_tokens 与 timeout。
		$options['max_tokens'] = max( (int) $options['max_tokens'], 2000 + $count * 400 );
		$options['timeout']    = max( (int) $options['timeout'], 60 );

		// 需要结构化输出：开启 JSON 模式。
		$result = $this->request( $config, $messages, $options, true );

		$data = $this->extract_json( $result );
		if ( ! is_array( $data ) ) {
			throw new FAS_Exception( '无法解析批量标签描述结果', true );
		}

		$out = array();

		// 兼容 {"descriptions":[...]} / {"items":[...]} 包装。
		if ( isset( $data['descriptions'] ) && is_array( $data['descriptions'] ) ) {
			foreach ( $data['descriptions'] as $item ) {
				$this->collect_batch_item( $out, $item );
			}
		} elseif ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
			foreach ( $data['items'] as $item ) {
				$this->collect_batch_item( $out, $item );
			}
		} elseif ( $this->is_list( $data ) ) {
			// 兼容 [{name, description}] 数组。
			foreach ( $data as $item ) {
				$this->collect_batch_item( $out, $item );
			}
		} else {
			// 标准对象 {标签词: 描述}。
			foreach ( $data as $k => $v ) {
				if ( is_string( $v ) && '' !== trim( $v ) ) {
					$out[ (string) $k ] = trim( $v );
				}
			}
		}

		if ( empty( $out ) ) {
			throw new FAS_Exception( '模型未返回任何标签描述', true );
		}

		return $out;
	}

	/**
	 * 从批量结果元素（{name, description}）收集描述。
	 *
	 * @param array $out  引用，累计映射。
	 * @param mixed $item 单个元素。
	 */
	private function collect_batch_item( &$out, $item ) {
		if ( ! is_array( $item ) ) {
			return;
		}
		$name = '';
		$desc = '';
		if ( isset( $item['name'] ) ) {
			$name = trim( (string) $item['name'] );
		} elseif ( isset( $item['term'] ) ) {
			$name = trim( (string) $item['term'] );
		}
		if ( isset( $item['description'] ) ) {
			$desc = trim( (string) $item['description'] );
		} elseif ( isset( $item['desc'] ) ) {
			$desc = trim( (string) $item['desc'] );
		}
		if ( '' !== $name && '' !== $desc ) {
			$out[ $name ] = $desc;
		}
	}

	/**
	 * 语义聚类：从一批标签名中识别「语义相同或高度相近、应当合并」的分组。
	 *
	 * 供「标签合并」功能调用：将论坛全部标签名一次性交给模型，让其输出应当合并的
	 * 标签组；返回结果会与输入列表做严格校验（只保留确实存在的标签、且互斥）。
	 *
	 * @param string[] $names 标签名列表。
	 * @return array 分组数组，每组为同义标签名数组（长度 ≥ 2）；无可合并项返回空数组。
	 * @throws FAS_Exception
	 */
	public function cluster_tags( $names ) {
		$names = array_values(
			array_filter(
				array_map( 'strval', (array) $names ),
				function ( $n ) {
					return '' !== trim( $n );
				}
			)
		);
		if ( count( $names ) < 2 ) {
			return array();
		}

		$options = FAS_Options::get();
		$config  = FAS_Options::provider_config();
		$this->assert_configured( $config );

		$system = '你是一名中文内容平台的标签治理专家。用户会给出一个论坛的全部标签名。'
			. '请找出其中【语义相同或高度相近、应当合并为同一个】的标签，按组输出。'
			. "\n\n满足其一即可视为同义："
			. "\n- 近义 / 同义：如「修图」与「P图」、「播放器」与「影音播放」；"
			. "\n- 同一事物的不同粒度写法：如「破解」与「破解版」、单独存在的「下载」与「下载器」；"
			. "\n- 书写差异：大小写、全半角、有无「版 / 类 / 型」等后缀、同一专有名词的不同写法。"
			. "\n\n严格约束："
			. "\n- 只输出【确实应当合并】的组，每组 2~8 个标签；没有可合并的就返回空数组；"
			. "\n- 泛化词（工具 / 下载 / 系统 / 软件 / 资源 / 教程等）与具体词只有确实指代同一主题时才合并；"
			. "\n- 每个标签最多出现在一个组里；"
			. "\n- 组内标签名必须与给定列表**完全一致**，不得改写、不得新增不存在的标签。"
			. "\n\n严格只输出一个 JSON 对象，不要解释、前后缀或 Markdown 代码块。格式："
			. "\n{\"groups\": [[\"标签A\",\"标签B\"], [\"标签C\",\"标签D\",\"标签E\"]]}"
			. "\n若无需合并：{\"groups\": []}";

		$user = '全部标签如下（共 ' . count( $names ) . " 个）：\n" . implode( '、', $names );

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		);

		// 聚类输出量随标签数增长，临时抬高 token 与超时。
		$options['max_tokens'] = max( (int) $options['max_tokens'], 1500 + count( $names ) * 12 );
		$options['timeout']    = max( (int) $options['timeout'], 60 );

		$result = $this->request( $config, $messages, $options, true );

		$data = $this->extract_json( $result );
		if ( ! is_array( $data ) ) {
			throw new FAS_Exception( '无法解析标签聚类结果', true );
		}

		$groups = array();
		if ( isset( $data['groups'] ) && is_array( $data['groups'] ) ) {
			$groups = $data['groups'];
		} elseif ( isset( $data['组'] ) && is_array( $data['组'] ) ) {
			$groups = $data['组'];
		} elseif ( $this->is_list( $data ) ) {
			$groups = $data; // 兜底：[["A","B"],["C","D"]]
		}

		// 建立「匹配键 => 原始名」索引，用于严格校验。
		$valid = array();
		foreach ( $names as $n ) {
			$valid[ FAS_Tags::match_key( $n ) ] = $n;
		}

		$out  = array();
		$used = array();
		foreach ( $groups as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$members = array();
			foreach ( $g as $name ) {
				$name = trim( (string) $name );
				if ( '' === $name ) {
					continue;
				}
				$key = FAS_Tags::match_key( $name );
				if ( isset( $used[ $key ] ) ) {
					continue;
				}
				if ( isset( $valid[ $key ] ) ) {
					$members[]    = $valid[ $key ];
					$used[ $key ] = true;
				}
			}
			$members = array_values( array_unique( $members ) );
			if ( count( $members ) >= 2 ) {
				$out[] = $members;
			}
		}

		return $out;
	}

	/**
	 * 为「正文自动内链」挑选目标帖与锚文本。
	 *
	 * 关键设计：**不让模型生成 HTML**（模型写链接极易破坏正文结构），而是让它在
	 * 「候选帖子编号列表」里挑目标，并给出**正文中原本就存在的连续文字片段**作为
	 * 锚文本候选。落链由插件用确定性算法在文本节点内完成，因此结果可控、可幂等、
	 * 可随时开关回退（渲染期插入，不改数据库原文）。
	 *
	 * @param string $title      当前帖标题。
	 * @param string $content    当前帖已清洗的纯文本正文。
	 * @param array  $candidates 候选帖子数组，每项 {id, title, tags[]}。
	 * @param int    $want       期望返回的内链条数（1~5）。
	 * @return array {links: array<array{target:int,anchors:string[],reason:string}>}
	 * @throws FAS_Exception
	 */
	public function pick_internal_link( $title, $content, $candidates, $want = 1 ) {
		$candidates = array_values( (array) $candidates );
		if ( empty( $candidates ) ) {
			return array( 'links' => array() );
		}

		$options = FAS_Options::get();
		$config  = FAS_Options::provider_config();
		$this->assert_configured( $config );

		$want = max( 1, min( 5, (int) $want ) );

		// 候选清单（编号从 1 开始，模型只允许引用这些编号）。
		$list = '';
		foreach ( $candidates as $i => $c ) {
			$line = ( $i + 1 ) . '. ' . ( isset( $c['title'] ) ? $c['title'] : '' );
			if ( ! empty( $c['tags'] ) && is_array( $c['tags'] ) ) {
				$line .= '（标签：' . implode( '、', array_slice( $c['tags'], 0, 5 ) ) . '）';
			}
			$list .= $line . "\n";
		}

		$system = '你是一名中文论坛的内容编辑，负责为帖子添加**正文内链**（把正文里的某个词，'
			. '链到站内另一篇相关帖子），帮助读者顺着兴趣继续浏览。'
			. "\n\n你的工作分两步："
			. "\n1. 通读「当前帖」，再从「候选帖子」中挑出**与当前帖主题最相关、最值得读者接着看**的帖子，取 " . $want . ' 篇（不足 ' . $want . ' 篇就按实际给，实在没有相关的就给空数组）；'
			. "\n2. 为每篇被选中的候选帖，给出 1~3 个**锚文本候选**。"
			. "\n\n【锚文本的硬性要求】"
			. "\n- 每个锚文本都必须是「当前帖正文」里**原样出现过的连续文字片段**（2~12 个字），不得改写、不得拼接、不得添加标点或换行；"
			. "\n- 锚文本要足够具体、能自然指向该候选帖的主题（例如候选帖讲「Clash 客户端」，就用正文中的「Clash」或「代理客户端」）；"
			. "\n- 优先选择专有名词、软件名、技术词、领域词；"
			. "\n- 严禁把「软件」「下载」「资源」「教程」「分享」「工具」「最新」「免费」这类泛化词单独作为锚文本；"
			. "\n- 同一篇帖子中，多个锚文本候选之间不得重复，也不要选同义词堆叠。"
			. "\n\n严格只输出一个 JSON 对象，不要任何解释、前后缀或 Markdown 代码块。格式："
			. "\n{\"links\": [{\"target\": 3, \"anchors\": [\"Clash\", \"代理客户端\"], \"reason\": \"一句话理由\"}]}"
			. "\n若没有任何值得内链的候选：{\"links\": []}"
			. "\n注意：target 必须是候选清单里的**编号数字**，不能填标题文字，也不能超出清单范围。";

		$user = '当前帖标题：' . $title
			. "\n\n当前帖正文：\n" . $content
			. "\n\n候选帖子（编号. 标题）：\n" . $list;

		$messages = array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		);

		// 选链输出量小，但需要读完正文：抬高超时下限，避免长文被掐断。
		$options['timeout'] = max( (int) $options['timeout'], 45 );

		$result = $this->request( $config, $messages, $options, true );

		$data = $this->extract_json( $result );
		if ( ! is_array( $data ) ) {
			throw new FAS_Exception( '无法解析内链选择结果', true );
		}

		// 兼容三种返回形态：{"links":[...]} / 单条 {target,anchors} / 顶层数组。
		$raw = array();
		if ( isset( $data['links'] ) && is_array( $data['links'] ) ) {
			$raw = $data['links'];
		} elseif ( isset( $data['内链'] ) && is_array( $data['内链'] ) ) {
			$raw = $data['内链'];
		} elseif ( isset( $data['target'] ) ) {
			$raw = array( $data );
		} elseif ( $this->is_list( $data ) ) {
			$raw = $data;
		}

		$count = count( $candidates );
		$out   = array();
		$used  = array();

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$idx = 0;
			if ( isset( $item['target'] ) ) {
				$idx = (int) $item['target'];
			} elseif ( isset( $item['目标'] ) ) {
				$idx = (int) $item['目标'];
			}
			// 越界 / 自引用 / 重复目标一律丢弃。
			if ( $idx < 1 || $idx > $count ) {
				continue;
			}
			$cand = $candidates[ $idx - 1 ];
			$tid  = isset( $cand['id'] ) ? (int) $cand['id'] : 0;
			if ( $tid <= 0 || isset( $used[ $tid ] ) ) {
				continue;
			}

			$anchors = array();
			$src     = array();
			if ( isset( $item['anchors'] ) ) {
				$src = $item['anchors'];
			} elseif ( isset( $item['锚文本'] ) ) {
				$src = $item['锚文本'];
			} elseif ( isset( $item['anchor'] ) ) {
				$src = array( $item['anchor'] );
			}
			if ( is_string( $src ) ) {
				$src = preg_split( '/[,，、;；|\n]+/u', $src );
			}
			foreach ( (array) $src as $a ) {
				$a = $this->clean_anchor( $a );
				if ( '' !== $a ) {
					$anchors[] = $a;
				}
			}
			$anchors = array_values( array_unique( $anchors ) );
			if ( empty( $anchors ) ) {
				continue;
			}
			$anchors = array_slice( $anchors, 0, 3 );

			$used[ $tid ] = true;
			$out[]        = array(
				'target'  => $tid,
				'anchors' => $anchors,
				'reason'  => isset( $item['reason'] ) ? trim( (string) $item['reason'] ) : '',
			);

			if ( count( $out ) >= $want ) {
				break;
			}
		}

		return array( 'links' => $out );
	}

	/**
	 * 清洗单个锚文本：去空白/控制字符、剔除含 HTML 或超长的项。
	 *
	 * @param mixed $a 原始锚文本。
	 * @return string 清洗后的锚文本，非法时返回空串。
	 */
	private function clean_anchor( $a ) {
		if ( ! is_string( $a ) ) {
			return '';
		}
		// 换行、制表符、连续空格一律折叠掉——锚文本必须是连续片段。
		$a = preg_replace( '/[\r\n\t\x{00A0}]+/u', '', $a );
		$a = trim( (string) $a );
		// 去掉包裹引号（模型有时会带）。
		$a = trim( $a, " \t\n\r\0\x0B\"'“”‘’「」『』" );

		if ( '' === $a ) {
			return '';
		}
		// 长度口径说明：提示词引导模型输出 2~12 字的锚文本（见 pick_internal_link），
		// 此处是**校验兜底**，放宽到 1~24 字 —— 锚文本最终还要在正文里原样落位
		// （insert_once 会再校验一次），宁可接受稍长的原文片段，也不因长度把可用锚词误杀。
		$len = mb_strlen( $a, 'UTF-8' );
		if ( $len < 1 || $len > 24 ) {
			return '';
		}
		// 含尖括号 / 引号 / 花括号的一律不要，避免污染 HTML。
		if ( preg_match( '/[<>{}"\'\\\\]/u', $a ) ) {
			return '';
		}
		return $a;
	}

	/**
	 * 判断数组是否为数字索引的列表（区别于关联数组）。
	 *
	 * @param array $arr 数组。
	 * @return bool
	 */
	private function is_list( $arr ) {
		if ( ! is_array( $arr ) ) {
			return false;
		}
		if ( empty( $arr ) ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * 从模型输出中提取纯文本：若模型误返回 JSON 包装（如
	 * {"type":"json_object","content":"..."} 或 {"description":"..."}），
	 * 自动剥离包装取出真正的文本内容；否则原样返回。
	 *
	 * @param string $text 模型原始输出。
	 * @param int    $depth 递归深度保护（内部用）。
	 * @return string
	 */
	public function extract_plain_text( $text, $depth = 0 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}

		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			// 常见纯文本字段，按优先级取第一个非空字符串。
			foreach ( array( 'content', 'description', 'text', 'answer', 'result', 'summary', 'desc' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== trim( $data[ $key ] ) ) {
					$inner = trim( $data[ $key ] );
					// 若提取出的值本身仍是 JSON（嵌套包装），递归剥一层。
					if ( $depth < 2 && '{' === substr( $inner, 0, 1 ) ) {
						return $this->extract_plain_text( $inner, $depth + 1 );
					}
					return $inner;
				}
			}
		}

		// 兜底：即便 json_decode 失败，也尝试剥离 {"type":"...","content":"..."} 这类包装前缀。
		$text = preg_replace( '/^\s*\{\s*"type"\s*:\s*"[^"]*"\s*,\s*"content"\s*:\s*"/i', '', $text );
		$text = preg_replace( '/"\s*\}\s*$/', '', $text );
		$text = str_replace( array( '\\n', '\\"', "\\'" ), array( "\n", '"', "'" ), $text );

		return trim( $text );
	}

	/**
	 * 归一化描述：去除空泛前缀、截断到指定长度、折叠空白。
	 *
	 * @param string $desc 原始描述。
	 * @param int    $max  最大字符数（默认 120）。
	 * @return string
	 */
	public function normalize_description( $desc, $max = 120 ) {
		$desc = trim( (string) $desc );

		// 去除 AI 可能带入的 Markdown 代码块围栏。
		$desc = preg_replace( '/^```(?:[a-zA-Z]*)?\s*/', '', $desc );
		$desc = preg_replace( '/\s*```$/', '', $desc );
		$desc = trim( $desc );

		// 去除空泛开头。
		$leads = array(
			'本帖介绍了', '本文介绍了', '本主题介绍了', '本标签介绍了', '本板块介绍了',
			'本帖主要内容', '本文主要内容', '本主题主要内容',
			'今天给大家', '大家好，今天', '大家好', '欢迎来到',
		);
		foreach ( $leads as $lead ) {
			if ( 0 === mb_strpos( $desc, $lead, 0, 'UTF-8' ) ) {
				$desc = mb_substr( $desc, mb_strlen( $lead, 'UTF-8' ), null, 'UTF-8' );
				break;
			}
		}
		// 去掉移除前缀后残留的冒号/逗号/顿号等。
		$desc = preg_replace( '/^[：:，,、。；;.\s]+/u', '', $desc );
		$desc = trim( $desc );

		// 折叠空白。
		$desc = preg_replace( '/\s+/u', ' ', $desc );

		// 截断。
		if ( mb_strlen( $desc, 'UTF-8' ) > $max ) {
			$desc = mb_substr( $desc, 0, $max, 'UTF-8' );
		}

		return trim( $desc );
	}

	/**
	 * 断言 API 已配置完整。
	 *
	 * @param array $config 供应商配置。
	 * @throws FAS_Exception
	 */
	private function assert_configured( $config ) {
		if ( empty( $config['api_key'] ) ) {
			throw new FAS_Exception( '未配置 API 密钥', false );
		}
		if ( empty( $config['base_url'] ) || empty( $config['model'] ) ) {
			throw new FAS_Exception( 'API 地址或模型名称为空', false );
		}
	}

	/**
	 * 统一请求分发（OpenAI 兼容 / Anthropic）。
	 *
	 * @param array $config    供应商配置。
	 * @param array $messages  消息。
	 * @param array $options   插件配置。
	 * @param bool  $json_mode 是否为 OpenAI 兼容请求开启 JSON 输出模式（纯文本场景应传 false）。
	 * @return string
	 * @throws FAS_Exception
	 */
	private function request( $config, $messages, $options, $json_mode = true ) {
		if ( 'anthropic' === $config['provider'] ) {
			return $this->request_anthropic( $config, $messages, $options );
		}
		return $this->request_openai_compatible( $config, $messages, $options, $json_mode );
	}

	/**
	 * 构建 SEO 三要素生成提示词。
	 */
	private function build_seo_messages( $title, $content, $context ) {
		$brand    = ( isset( $context['brand'] ) && '' !== trim( (string) $context['brand'] ) ) ? trim( (string) $context['brand'] ) : '';
		$plate    = isset( $context['plate'] ) ? trim( (string) $context['plate'] ) : '';
		$category = isset( $context['category'] ) ? trim( (string) $context['category'] ) : '';

		$system = '你是一名资深 SEO 优化专家，精通中文搜索引擎（百度、Google）的标题与元数据优化。'
			. '请根据论坛帖子内容，生成 SEO 三要素，并严格遵守以下规则：'
			. "\n\n【SEO 标题 title】"
			. "\n- 15~30 个字符，把最重要的核心关键词放在最前面；"
			. "\n- 不要使用【】、[] 等方括号，用「-」或空格分隔；"
			. "\n- 避免关键词堆砌，同一个词不要重复超过 2 次；"
			. "\n- 最后一个词放品牌词（不重要的词）；"
			. ( '' !== $brand ? "\n- 品牌词为「" . $brand . '」，如需要可放末尾一次，不要重复；' : '' )
			. "\n\n【SEO 关键词 keywords】"
			. "\n- 4~8 个关键词，用英文逗号「,」分隔；"
			. "\n- 覆盖帖子核心主题词、领域词，尽量与网站定位一致；"
			. "\n\n【SEO 描述 description】"
			. "\n- 50~150 个字符，写成一段通顺、有吸引力的话，精练概括帖子内容；"
			. "\n- 必须以帖子的核心名词开头（如软件名+版本号、资源名、话题词），直接切入内容，"
			. "\n  禁止以「本帖介绍了」「本文介绍了」「本主题介绍了」「今天给大家」「大家好」等空泛短语开头；"
			. "\n- 自然融入多个关键词，但不宜重复太多；"
			. "\n\n严格只输出一个 JSON 对象，不要任何解释、前后缀或 Markdown 代码块。格式："
			. "\n{\"title\": \"SEO标题\", \"keywords\": \"词1,词2,词3,词4\", \"description\": \"SEO描述\"}"
			. "\n注意：title 是字符串，keywords 是逗号分隔的字符串，description 是字符串。";

		$extra = '';
		if ( '' !== $plate || '' !== $category ) {
			$extra = "\n\n补充上下文：所属板块「" . $plate . '」，分类「' . $category . '」。';
		}

		$user = '帖子标题：' . $title . $extra . "\n\n帖子正文：\n" . $content;

		return array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		);
	}

	/**
	 * 从模型输出解析 SEO 三要素。
	 */
	private function parse_seo( $text ) {
		$data = $this->extract_json( $text );

		if ( ! is_array( $data ) ) {
			throw new FAS_Exception( '无法从模型输出解析 SEO 结果', true );
		}

		$seo_title = '';
		if ( isset( $data['title'] ) && is_string( $data['title'] ) ) {
			$seo_title = trim( $data['title'] );
		} elseif ( isset( $data['标题'] ) && is_string( $data['标题'] ) ) {
			$seo_title = trim( $data['标题'] );
		}

		$keywords = '';
		if ( isset( $data['keywords'] ) ) {
			$keywords = $this->normalize_seo_keywords( $data['keywords'] );
		} elseif ( isset( $data['关键词'] ) ) {
			$keywords = $this->normalize_seo_keywords( $data['关键词'] );
		}

		$description = '';
		if ( isset( $data['description'] ) && is_string( $data['description'] ) ) {
			$description = trim( $data['description'] );
		} elseif ( isset( $data['描述'] ) && is_string( $data['描述'] ) ) {
			$description = trim( $data['描述'] );
		}

		// 去空泛前缀（「本帖介绍了」等）、折叠空白、截断到 150 字，保证直接以核心名词开头。
		$description = $this->normalize_description( $description, 150 );

		if ( '' === $seo_title ) {
			throw new FAS_Exception( '模型未返回有效 SEO 标题', true );
		}

		return array(
			'title'       => $seo_title,
			'keywords'    => $keywords,
			'description' => $description,
		);
	}

	/**
	 * 归一化关键词：数组转逗号分隔字符串，字符串去空白。
	 *
	 * @param mixed $keywords 模型返回的关键词（数组或字符串）。
	 * @return string
	 */
	private function normalize_seo_keywords( $keywords ) {
		if ( is_array( $keywords ) ) {
			$arr = array();
			foreach ( $keywords as $k ) {
				$k = trim( (string) $k );
				if ( '' !== $k ) {
					$arr[] = $k;
				}
			}
			return implode( ',', $arr );
		}
		if ( is_string( $keywords ) ) {
			return trim( $keywords );
		}
		return '';
	}

	/**
	 * 构建提示词。
	 */
	private function build_messages( $title, $content, $options ) {
		$style_text  = $this->style_instruction( $options['summary_style'] );
		$length_text = $this->length_instruction( $options );
		$tags_enabled = ! empty( $options['tags_enabled'] ) && (int) $options['tags_count'] > 0;

		$system = '你是一名中文论坛内容编辑助手。请阅读给定的论坛主题帖，完成以下任务：'
			. "\n1. 生成内容摘要（" . $style_text . '，' . $length_text . '）'
			. ( $tags_enabled ? "\n2. " . $this->tags_instruction( $options ) : '' )
			. "\n\n严格只输出一个 JSON 对象，不要输出任何解释、前后缀或 Markdown 代码块标记。JSON 格式如下："
			. "\n{\"tags\": [\"标签1\", \"标签2\", \"标签3\"], \"summary\": \"摘要内容\"}"
			. ( $tags_enabled
				? "\n注意：必须先输出 tags 再输出 summary；tags 必须是非空字符串数组，不得省略、不得返回空数组。"
				: "\n若无需标签，tags 请输出空数组 []。" )
			. "\n\n注意：summary 为字符串，tags 为字符串数组。";

		$user = "主题标题：" . $title . "\n\n主题正文：\n" . $content;

		return array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		);
	}

	/**
	 * 标签生成指令：兼顾主题相关性与 SEO 优化。
	 *
	 * @param array $options 配置。
	 * @return string
	 */
	private function tags_instruction( $options ) {
		$count = max( 1, min( 10, (int) $options['tags_count'] ) );
		$lang  = FAS_Tags::lang_instruction( $options['tags_lang'] );

		return '生成 ' . $count . ' 个与主题高度相关的标签，并兼顾 SEO 优化：'
			. '每个标签 2~8 个字符（中文以 2~6 字为宜）、使用具体关键词而非泛化词汇；'
			. '避免与标题和摘要用词重复；避免「教程」「分享」「讨论」「经验」「心得」「求助」等泛化词；'
			. '应覆盖帖子的核心名词、技术点或领域词，以提升搜索引擎对帖子主题的识别度。'
			. $lang
			. '请务必返回 ' . $count . ' 个具体标签，tags 字段必须是非空字符串数组，不得省略、不得返回空数组；'
			. '若帖子信息有限，也请基于标题与正文提炼最相关的关键词作为标签。';
	}

	/**
	 * 摘要风格指令。
	 */
	private function style_instruction( $style ) {
		return 'bullets' === $style
			? '要点提炼型，用简洁的短句或分条列出核心要点'
			: '概括型，用一段流畅连贯的文字概括主题核心内容';
	}

	/**
	 * 摘要长度指令。
	 */
	private function length_instruction( $options ) {
		if ( 'sentences' === $options['length_mode'] ) {
			$n = max( 1, min( 20, (int) $options['summary_sentences'] ) );
			return '控制在 ' . $n . ' 句话以内';
		}
		$n = max( 20, min( 2000, (int) $options['summary_chars'] ) );
		return '控制在 ' . $n . ' 个中文字符以内';
	}

	/**
	 * OpenAI 兼容协议请求（OpenAI / DeepSeek / 自定义）。
	 *
	 * @param array $config    供应商配置。
	 * @param array $messages  消息。
	 * @param array $options   插件配置。
	 * @param bool  $json_mode 是否开启 JSON 输出模式（需要结构化输出的调用方传 true，纯文本传 false）。
	 */
	private function request_openai_compatible( $config, $messages, $options, $json_mode = true ) {
		$url = rtrim( $config['base_url'], '/' ) . $config['chat_path'];

		$body = array(
			'model'       => $config['model'],
			'messages'    => $messages,
			'temperature' => (float) $options['temperature'],
			'max_tokens'  => (int) $options['max_tokens'],
		);

		// OpenAI / DeepSeek 支持 JSON 模式，提高结构化输出稳定性（仅对需要 JSON 的场景开启）。
		if ( $json_mode && in_array( $config['provider'], array( 'openai', 'deepseek' ), true ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => (int) $options['timeout'],
				'headers' => array(
					'Authorization' => 'Bearer ' . $config['api_key'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$this->check_http_error( $response );

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( $status >= 200 && $status < 300 ) {
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				throw new FAS_Exception( '响应 JSON 解析失败', true );
			}
			$content = isset( $data['choices'][0]['message']['content'] )
				? $data['choices'][0]['message']['content']
				: '';
			if ( '' === trim( (string) $content ) ) {
				throw new FAS_Exception( '模型返回内容为空', true );
			}
			return (string) $content;
		}

		$this->throw_http_exception( $status, $raw );
	}

	/**
	 * Anthropic Messages API 请求。
	 */
	private function request_anthropic( $config, $messages, $options ) {
		$url = rtrim( $config['base_url'], '/' ) . $config['chat_path'];

		// 拆分 system / user 消息。
		$system = '';
		$user_msgs = array();
		foreach ( $messages as $m ) {
			if ( 'system' === $m['role'] ) {
				$system .= $m['content'] . "\n";
			} else {
				$user_msgs[] = $m;
			}
		}

		$body = array(
			'model'      => $config['model'],
			'max_tokens' => (int) $options['max_tokens'],
			'temperature'=> (float) $options['temperature'],
			'messages'   => $user_msgs,
		);
		if ( '' !== trim( $system ) ) {
			$body['system'] = trim( $system );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => (int) $options['timeout'],
				'headers' => array(
					'x-api-key'         => $config['api_key'],
					'anthropic-version' => $config['anthropic_version'],
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		$this->check_http_error( $response );

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( $status >= 200 && $status < 300 ) {
			$data = json_decode( $raw, true );
			if ( ! is_array( $data ) ) {
				throw new FAS_Exception( '响应 JSON 解析失败', true );
			}
			// content 为数组：[{type:text, text:...}]
			$text = '';
			if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
				foreach ( $data['content'] as $block ) {
					if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
						$text .= $block['text'];
					}
				}
			}
			if ( '' === trim( $text ) ) {
				throw new FAS_Exception( '模型返回内容为空', true );
			}
			return $text;
		}

		$this->throw_http_exception( $status, $raw );
	}

	/**
	 * 统一 HTTP 错误判断。
	 */
	private function check_http_error( $response ) {
		if ( is_wp_error( $response ) ) {
			$code     = $response->get_error_code();
			$msg      = $response->get_error_message();
			$haystack = strtolower( $code . ' ' . $msg );
			// cURL 超时错误码 28、以及包含 timeout/timed 字样均视为超时。
			if ( false !== strpos( $haystack, 'timed' )
				|| false !== strpos( $haystack, 'timeout' )
				|| false !== strpos( $haystack, 'error 28' ) ) {
				throw new FAS_Exception( '请求超时', true );
			}
			throw new FAS_Exception( '网络错误：' . $msg, true );
		}
	}

	/**
	 * 根据状态码抛出对应异常。
	 */
	private function throw_http_exception( $status, $raw ) {
		// 提取错误信息用于日志。
		$detail = $this->extract_error_detail( $raw );

		switch ( $status ) {
			case 401:
			case 403:
				throw new FAS_Exception( '鉴权失败（' . $status . '）：请检查 API 密钥' . $detail, false, $status );
			case 404:
				throw new FAS_Exception( '接口地址或路径不存在（404）' . $detail, false, $status );
			case 400:
			case 422:
				throw new FAS_Exception( '请求参数错误（' . $status . '）' . $detail, false, $status );
			case 429:
				throw new FAS_Exception( '触发限流（429），稍后重试' . $detail, true, $status );
			default:
				$retryable = $status >= 500;
				throw new FAS_Exception( 'API 返回错误状态码 ' . $status . $detail, $retryable, $status );
		}
	}

	/**
	 * 从原始响应中提取简要错误信息。
	 */
	private function extract_error_detail( $raw ) {
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$snippet = wp_strip_all_tags( (string) $raw );
			$snippet = trim( preg_replace( '/\s+/', ' ', $snippet ) );
			$snippet = mb_substr( $snippet, 0, 200, 'UTF-8' );
			return $snippet ? '：' . $snippet : '';
		}
		// 常见错误字段。
		foreach ( array( 'message', 'msg', 'error' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$val = is_array( $data[ $field ] ) && isset( $data[ $field ]['message'] )
					? $data[ $field ]['message']
					: $data[ $field ];
				if ( is_string( $val ) && '' !== trim( $val ) ) {
					return '：' . mb_substr( trim( $val ), 0, 200, 'UTF-8' );
				}
			}
		}
		return '';
	}

	/**
	 * 从模型输出中解析出 {summary, tags}。
	 *
	 * @param string $text 模型返回的原始文本。
	 * @return array
	 * @throws FAS_Exception
	 */
	private function parse_result( $text ) {
		$data = $this->extract_json( $text );

		if ( ! is_array( $data ) ) {
			throw new FAS_Exception( '无法从模型输出中解析出结构化结果', true );
		}

		$summary = '';
		if ( isset( $data['summary'] ) && is_string( $data['summary'] ) ) {
			$summary = trim( $data['summary'] );
		} elseif ( isset( $data['摘要'] ) && is_string( $data['摘要'] ) ) {
			$summary = trim( $data['摘要'] );
		}

		$tags = array();
		if ( isset( $data['tags'] ) ) {
			$tags = $data['tags'];
		} elseif ( isset( $data['标签'] ) ) {
			$tags = $data['标签'];
		}

		// 兼容：模型可能把标签返回为字符串（如 "标签1、标签2"），交由 FAS_Tags::normalize 拆分；
		// 仅保留数组或非空字符串，其余（数字、null 等）视为无标签。
		if ( ! is_array( $tags ) ) {
			$tags = is_string( $tags ) ? trim( $tags ) : array();
		}

		if ( '' === $summary ) {
			throw new FAS_Exception( '模型未返回有效摘要', true );
		}

		return array(
			'summary' => $summary,
			'tags'    => $tags,
		);
	}

	/**
	 * 稳健提取文本中的 JSON 对象：先整体解析，失败则扫描第一个平衡的 {...} 块。
	 *
	 * @param string $text 原始文本。
	 * @return array|null
	 */
	private function extract_json( $text ) {
		$text = trim( (string) $text );

		// 去除 Markdown 代码块围栏。
		$text = preg_replace( '/^```(?:json|JSON)?\s*/', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		$text = trim( $text );

		// 1) 直接解析。
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		// 2) 扫描第一个平衡的 JSON 对象。
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return null;
		}

		$depth   = 0;
		$in_str  = false;
		$escaped = false;
		$len     = strlen( $text );

		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $text[ $i ];
			if ( $in_str ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $ch ) {
					$escaped = true;
				} elseif ( '"' === $ch ) {
					$in_str = false;
				}
				continue;
			}
			if ( '"' === $ch ) {
				$in_str = true;
			} elseif ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				$depth--;
				if ( 0 === $depth ) {
					$candidate = substr( $text, $start, $i - $start + 1 );
					$parsed    = json_decode( $candidate, true );
					return is_array( $parsed ) ? $parsed : null;
				}
			}
		}

		return null;
	}
}
