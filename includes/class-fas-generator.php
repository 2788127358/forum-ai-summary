<?php
/**
 * 生成器核心
 *
 * 负责：内容清洗、长度约束、内容指纹去重、调用 API、失败重试与降级、
 * 将结果写回帖子（摘要存元数据 + 可选写回原生摘要字段，标签写回 forum_tag）。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Generator {

	/** @var FAS_Generator */
	private static $instance = null;

	/** @var bool 写回过程中置真，供监听器防递归。 */
	public static $is_writing = false;

	/**
	 * 单例。
	 *
	 * @return FAS_Generator
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 为指定帖子生成摘要与标签。
	 *
	 * @param int  $post_id 帖子 ID。
	 * @param bool $force   是否强制重新生成（忽略指纹与已存在检查）。
	 * @return array {code, message}
	 */
	public function generate_for_post( $post_id, $force = false ) {
		$options = FAS_Options::get();

		if ( ! $options['enabled'] || ! FAS_Options::is_configured() ) {
			return $this->result( 'error', '插件未启用或 API 未配置完整（密钥/地址/模型）' );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! FAS_Forum::is_forum_post( $post->post_type ) ) {
			return $this->result( 'skipped', '非子比论坛帖子，已忽略' );
		}

		$title   = FAS_Forum::get_title( $post_id );
		$content = FAS_Forum::get_content( $post_id );

		// 清洗为纯文本。
		$clean     = $this->clean_content( $content );
		$clean_len = mb_strlen( $clean, 'UTF-8' );

		// 内容为空或过短：降级处理，写入固定摘要，不调用 API。
		$min = (int) $options['min_input_chars'];
		if ( '' === trim( $clean ) || $clean_len < $min ) {
			$fixed = trim( (string) $options['short_text'] );
			if ( '' === $fixed ) {
				$fixed = '本主题内容过短，暂无摘要';
			}
			$meta = array(
				'_fas_status'      => 'skipped',
				'_fas_error'       => '内容过短（' . $clean_len . ' 字），未调用 API',
				'_fas_retry_count' => 0,
			);
			if ( $options['summary_enabled'] ) {
				$meta['_fas_summary'] = $fixed;
				if ( ! empty( $options['write_excerpt'] ) ) {
					$this->write_excerpt( $post_id, $fixed );
				}
			}
			$this->set_meta( $post_id, $meta );
			return $this->result( 'skipped', '内容过短，已写入固定摘要' );
		}

		// 超长截断。
		$max = (int) $options['max_input_chars'];
		if ( $clean_len > $max ) {
			$clean = mb_substr( $clean, 0, $max, 'UTF-8' );
		}

		// 内容指纹：内容未变且「摘要与标签」均已生成时跳过（除非强制）。
		$hash = md5( $title . "\n" . $content );
		if ( ! $force ) {
			$stored_hash = get_post_meta( $post_id, '_fas_content_hash', true );
			$has_summary = get_post_meta( $post_id, '_fas_summary', true );
			$has_tags    = get_post_meta( $post_id, '_fas_tags', true );

			// 标签功能开启时，标签也必须完整才算「已生成」，否则需重新生成补齐标签。
			$complete = ! empty( $has_summary ) && ( empty( $options['tags_enabled'] ) || ! empty( $has_tags ) );

			if ( $stored_hash === $hash && $complete ) {
				return $this->result( 'already', '内容未变化，摘要与标签已存在' );
			}
			if ( empty( $options['overwrite_existing'] ) && $complete ) {
				return $this->result( 'already', '已存在摘要与标签且未开启覆盖，已跳过' );
			}

			// 已有摘要、仅缺标签：只补标签，不再重复调用 AI 生成摘要，节省额度。
			if ( ! empty( $has_summary ) && empty( $has_tags ) && ! empty( $options['tags_enabled'] ) ) {
				return $this->generate_tags_for_post( $post_id, false );
			}
		}

		// 调用 API（带即时重试）。
		$data = $this->attempt_with_retries( $title, $clean );

		if ( is_wp_error( $data ) ) {
			$retryable  = (bool) $data->get_error_data();
			$retry_count = (int) get_post_meta( $post_id, '_fas_retry_count', true );
			$retry_count++;

			update_post_meta( $post_id, '_fas_retry_count', $retry_count );
			update_post_meta( $post_id, '_fas_error', $data->get_error_message() );
			update_post_meta( $post_id, '_fas_status', $retryable ? 'pending_retry' : 'failed' );

			// 可重试且未超过上限：安排延迟重试。
			if ( $retryable && $retry_count <= (int) $options['max_retries'] ) {
				$delay = (int) $options['retry_delay'] * $retry_count;
				wp_schedule_single_event( time() + $delay, 'fas_retry_event', array( $post_id ) );
				return $this->result( 'retrying', '生成失败（' . $data->get_error_message() . '），已安排第 ' . $retry_count . ' 次自动重试' );
			}

			return $this->result( 'failed', $data->get_error_message() );
		}

		$this->apply_result( $post_id, $data, $hash );

		return $this->result( 'success', '摘要与标签生成成功' );
	}

	/**
	 * 仅为指定帖子生成标签（不触碰摘要）。
	 *
	 * 用于「单独补标签」场景：已有摘要但缺标签的帖子只补标签、不重新生成摘要，
	 * 节省 API 额度，且标签独立提示词输出更小、更稳定。
	 *
	 * @param int  $post_id 帖子 ID。
	 * @param bool $force   是否强制重新生成（忽略已存在标签）。
	 * @return array {code, message}
	 */
	public function generate_tags_for_post( $post_id, $force = false ) {
		$options = FAS_Options::get();

		if ( ! $options['enabled'] || ! FAS_Options::is_configured() ) {
			return $this->result( 'error', '插件未启用或 API 未配置完整' );
		}
		if ( empty( $options['tags_enabled'] ) ) {
			return $this->result( 'skipped', '标签生成功能未开启' );
		}

		$post = get_post( $post_id );
		if ( ! $post || ! FAS_Forum::is_forum_post( $post->post_type ) ) {
			return $this->result( 'skipped', '非子比论坛帖子，已忽略' );
		}

		// 已存在标签且非强制：跳过。
		if ( ! $force ) {
			$existing = get_post_meta( $post_id, '_fas_tags', true );
			if ( ! empty( $existing ) ) {
				return $this->result( 'already', '已有标签，已跳过' );
			}
		}

		$title     = FAS_Forum::get_title( $post_id );
		$content   = FAS_Forum::get_content( $post_id );
		$clean     = $this->clean_content( $content );
		$clean_len = mb_strlen( $clean, 'UTF-8' );

		if ( '' === trim( $clean ) || $clean_len < (int) $options['min_input_chars'] ) {
			return $this->result( 'skipped', '内容过短，无法生成标签' );
		}

		$max = (int) $options['max_input_chars'];
		if ( $clean_len > $max ) {
			$clean = mb_substr( $clean, 0, $max, 'UTF-8' );
		}

		try {
			$api  = new FAS_Api();
			$tags = $api->generate_tags( $title, $clean );
		} catch ( FAS_Exception $e ) {
			update_post_meta( $post_id, '_fas_error', $e->getMessage() );
			return $this->result( 'failed', $e->getMessage() );
		}

		$tags = FAS_Tags::normalize( $tags, (int) $options['tags_count'] );

		// 写回论坛标签：固定「复用已有 forum_tag」（v1.39.0 起，「仅展示 / 创建」
		// 两种模式已移除）。只写入论坛已存在的标签，不新建 —— 形成站内聚合内链
		// 且避免标签膨胀；匹配不到已有 term 的标签仍只存 _fas_tags 供前端展示。
		if ( ! empty( $tags ) ) {
			FAS_Forum::set_existing_tags( $post_id, $tags, ! empty( $options['tags_append'] ) );
		}

		update_post_meta( $post_id, '_fas_tags', $tags );
		update_post_meta( $post_id, '_fas_error', '' );

		if ( empty( $tags ) ) {
			return $this->result( 'failed', '标签生成结果为空' );
		}

		return $this->result( 'success', '标签生成成功' );
	}

	/**
	 * 带即时重试的 API 调用。
	 *
	 * @param string $title   标题。
	 * @param string $content 清洗后的正文。
	 * @return array|WP_Error 成功返回 {summary, tags}，失败返回 WP_Error（data 为 retryable 布尔）。
	 */
	private function attempt_with_retries( $title, $content ) {
		$options    = FAS_Options::get();
		$max        = max( 1, (int) $options['max_retries'] );
		$last_error = null;

		for ( $i = 0; $i < $max; $i++ ) {
			try {
				$api = new FAS_Api();
				return $api->generate( $title, $content );
			} catch ( FAS_Exception $e ) {
				$last_error = $e;
				if ( ! $e->retryable ) {
					break; // 鉴权/参数类错误无需重试。
				}
				if ( $i < $max - 1 ) {
					$sleep = min( (int) $options['retry_delay'] * ( $i + 1 ), 30 );
					sleep( $sleep );
				}
			}
		}

		$err = new WP_Error(
			'fas_api_error',
			$last_error ? $last_error->getMessage() : '未知错误'
		);
		$err->add_data( $last_error ? $last_error->retryable : false );
		return $err;
	}

	/**
	 * 将生成结果写回摘要字段与标签分类，并更新元数据。
	 *
	 * @param int   $post_id 帖子 ID。
	 * @param array $data    {summary, tags}。
	 * @param string $hash   内容指纹。
	 */
	private function apply_result( $post_id, $data, $hash ) {
		$options = FAS_Options::get();
		$post    = get_post( $post_id );

		// 1) 摘要：始终写入元数据 _fas_summary（主存储），可选写回原生摘要字段 post_excerpt。
		$summary_final = '';
		if ( $options['summary_enabled'] && ! empty( $data['summary'] ) ) {
			$summary_final = $this->apply_prefix( $data['summary'] );
			if ( ! empty( $options['write_excerpt'] ) ) {
				$this->write_excerpt( $post_id, $summary_final );
			}
		}

		// 2) 标签写回（按「标签写入模式」分支，兼顾 SEO）。
		$normalized_tags = array();
		if ( $options['tags_enabled'] && $post && FAS_Forum::is_forum_post( $post->post_type ) ) {
			$normalized_tags = FAS_Tags::normalize( isset( $data['tags'] ) ? $data['tags'] : array(), (int) $options['tags_count'] );
			if ( ! empty( $normalized_tags ) ) {
				// 固定「复用论坛已有标签」（v1.39.0 起 create/display 模式已移除）：
				// 保留聚合内链价值且避免标签膨胀；匹配不到的标签仍只存 _fas_tags 展示。
				FAS_Forum::set_existing_tags( $post_id, $normalized_tags, ! empty( $options['tags_append'] ) );
			}
		}

		// 3) 更新元数据。
		$this->set_meta(
			$post_id,
			array(
				'_fas_summary'       => $summary_final,
				'_fas_tags'          => $normalized_tags,
				'_fas_content_hash'  => $hash,
				'_fas_status'        => 'success',
				'_fas_error'         => '',
				'_fas_retry_count'   => 0,
				'_fas_generated_at'  => time(),
			)
		);

		// 4) v1.39.1：模型偶发「只返回摘要、tags 为空数组」（表现为前端标签区空白）。
		// 立即用「仅标签」专用提示词补生成一次：它输出体量小、不重复生成摘要，稳定性更高；
		// generate_tags_for_post() 内部按 _fas_tags 判空（刚写入的空数组视为「无标签」），
		// 不会与正常路径重复调用；补生成再失败也只是保持无标签，不会循环。
		if ( ! empty( $options['tags_enabled'] ) && empty( $normalized_tags ) ) {
			update_post_meta( $post_id, '_fas_error', '模型未返回标签，已自动补生成' );
			$this->generate_tags_for_post( $post_id, false );
		}
	}

	/**
	 * 应用摘要前缀。
	 */
	private function apply_prefix( $summary ) {
		$prefix = trim( (string) FAS_Options::get_value( 'prefix', '' ) );
		if ( '' === $prefix || '' === $summary ) {
			return $summary;
		}
		// 避免重复前缀。
		if ( 0 === mb_strpos( $summary, $prefix, 0, 'UTF-8' ) ) {
			return $summary;
		}
		return $prefix . $summary;
	}

	/**
	 * 写回原生摘要字段（post_excerpt），并加防递归保护。
	 */
	private function write_excerpt( $post_id, $summary ) {
		self::$is_writing = true;
		try {
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => wp_kses_post( $summary ),
				),
				true
			);
		} finally {
			self::$is_writing = false;
		}

		if ( is_wp_error( $result ) ) {
			$this->log( '写回摘要失败', array( 'post_id' => $post_id, 'error' => $result->get_error_message() ) );
		}
	}

	/**
	 * 批量更新元数据。
	 */
	private function set_meta( $post_id, $meta ) {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * 清理正文为纯文本（借鉴参考插件，移除代码块/短代码/HTML）。
	 *
	 * @param string $content 原始内容。
	 * @return string
	 */
	public function clean_content( $content ) {
		if ( '' === $content || null === $content ) {
			return '';
		}

		// 移除 Gutenberg 代码类块与 <pre>。
		$content = $this->strip_code_regions( $content );

		// HTML 实体解码。
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 移除块注释与短代码。
		$content = preg_replace( '/<!--(.*?)-->/s', '', $content );
		$content = strip_shortcodes( $content );

		// 保留段落/换行分隔后去除全部标签。
		$content = str_replace(
			array( '</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '<br>', '<br/>', '<br />', '</li>', '</blockquote>' ),
			"\n",
			$content
		);
		$content = wp_strip_all_tags( $content, true );

		// 折叠空白。
		$content = preg_replace( '/[ \t]+/u', ' ', $content );
		$content = preg_replace( '/\n{3,}/u', "\n\n", $content );
		$content = trim( $content );

		return $content;
	}

	/**
	 * 移除代码区块整段内容。
	 */
	private function strip_code_regions( $content ) {
		$pairs = array(
			array( 'wp:code', '/wp:code' ),
			array( 'wp:preformatted', '/wp:preformatted' ),
		);
		foreach ( $pairs as $pair ) {
			$open  = preg_quote( $pair[0], '/' );
			$close = preg_quote( $pair[1], '/' );
			$content = preg_replace( '/<!--\s*' . $open . '(?:\s[^>]*)?\s*-->.*?<!--\s*' . $close . '\s*-->/s', '', $content );
		}
		$content = preg_replace( '/<pre\b[^>]*>.*?<\/pre>/is', '', $content );
		$content = preg_replace( '/```[^`\n]*\n.*?```/s', '', $content );
		$content = preg_replace( '/\[code(?:\s[^\]]*)?\].*?\[\/code\]/is', '', $content );
		return $content;
	}

	/**
	 * 统一结果结构。
	 */
	private function result( $code, $message ) {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * 简易日志（仅 WP_DEBUG 时）。
	 */
	private function log( $message, $data = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$extra = $data ? ' - ' . wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) : '';
			error_log( '[ForumAI] ' . $message . $extra ); // phpcs:ignore
		}
	}
}
