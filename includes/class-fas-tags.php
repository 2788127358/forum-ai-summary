<?php
/**
 * 标签处理工具类
 *
 * 负责标签的拆分、清洗、去重、长度控制。
 *
 * @package Forum_AI_Summary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FAS_Tags {

	/**
	 * 将模型返回的标签（数组或字符串）清洗为规范数组。
	 *
	 * @param mixed $tags   模型返回的标签（数组或逗号分隔字符串）。
	 * @param int   $limit  标签数量上限。
	 * @return array
	 */
	public static function normalize( $tags, $limit = 3 ) {
		$limit = max( 1, min( 10, (int) $limit ) );

		// 字符串先按常见分隔符拆分。
		if ( is_string( $tags ) ) {
			$tags = preg_split( '/[,，、;；|\n]+/u', $tags );
		}

		if ( ! is_array( $tags ) ) {
			return array();
		}

		$clean = array();
		$seen  = array();

		foreach ( $tags as $tag ) {
			$tag = self::clean_single( $tag );
			if ( '' === $tag ) {
				continue;
			}

			// 去重：中英文不区分大小写。
			$key = mb_strtolower( $tag, 'UTF-8' );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$clean[] = $tag;
			if ( count( $clean ) >= $limit ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * 清洗单个标签：去首尾空白与标点、折叠内部多余空白。
	 *
	 * @param mixed $tag 原始标签。
	 * @return string
	 */
	public static function clean_single( $tag ) {
		if ( ! is_string( $tag ) ) {
			return '';
		}

		// 去除 HTML 与转义。
		$tag = wp_strip_all_tags( $tag );
		$tag = html_entity_decode( $tag, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 折叠内部空白。
		$tag = preg_replace( '/\s+/u', ' ', $tag );
		$tag = trim( $tag );

		// 去除首尾的标点、空格、引号等，但保留标签内部的合法标点（如连字符、斜杠）。
		$tag = preg_replace( '/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $tag );
		$tag = trim( $tag );

		// 首尾标点已由上一行的 \p{P}\p{S} 正则按【字符】修剪完毕（下方 charlist 中的
		// 引号/括号/书名号全部属于 Unicode 标点或符号，该正则完全覆盖）。
		// 此处原有的一行 trim( $tag, "'「」…【】" ) 是按【字节】修剪的：多字节
		// charlist 把汉字尾字节（如「版」的 0x88、「，」的 0x8C）误列为可修剪字节，
		// 使「高级版」这类标签被砍成非法 UTF-8 —— 进而让「复用已有标签」的匹配
		// 永远失败。v1.39.0 删除该行修复，test_v139 F 组锁定此行为。

		return $tag;
	}

	/**
	 * 生成标签「匹配键」：NFKC 兼容折叠 + 去大小写 + 去分隔符与常见标点。
	 *
	 * 用于宽松比较两个标签是否同义（如「Cos」与「cos」、「AI 摘要」与「ai摘要」、
	 * 「下载器」与「下载 器」），是相关性核验与近义聚类的基础。
	 *
	 * @param string $name 标签名。
	 * @return string
	 */
	public static function match_key( $name ) {
		$name = (string) $name;
		if ( function_exists( 'normalizer_normalize' ) ) {
			$n = normalizer_normalize( $name, Normalizer::FORM_KC );
			if ( false !== $n && null !== $n ) {
				$name = $n;
			}
		}
		$name = mb_strtolower( $name, 'UTF-8' );
		$name = preg_replace( '/[\s\-_·。，,、\/|\\\\~！!？?：:；;（）()【】\[\]「」『』"\'’“”]+/u', '', $name );
		return (string) $name;
	}

	/**
	 * 根据标签语言配置构建提示词片段。
	 *
	 * @param string $lang  zh | bilingual。
	 * @return string
	 */
	public static function lang_instruction( $lang ) {
		if ( 'bilingual' === $lang ) {
			return '每个标签采用「中文名/English Name」格式（用半角斜杠分隔），例如「AI摘要/AI Summary」。';
		}
		return '标签请使用中文。';
	}
}
