=== 论坛 AI 摘要（Forum AI Summary） ===
Contributors: kaiyuanjijie
Tags: zibll, forum, ai, summary, tags, seo, openai, deepseek, anthropic, internal-link
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

为子比论坛帖子自动生成 AI 摘要与相关标签，并把 SEO 一次做完。

== Description ==

为子比（Zibll）主题内置论坛的帖子自动生成 AI 摘要与相关标签，并把 SEO 一次做完：写入子比「独立SEO」三要素、为标签与板块补搜索描述、正文语义内链、文末相关推荐。

* 发帖自动生成，失败自动重试；帖子标题改动后自动重整理 SEO。
* 标签只复用站内已有 `forum_tag`，不新建，避免产生大量低质归档页。
* 支持 OpenAI、DeepSeek、Anthropic 与任意兼容 OpenAI 协议的接口。
* 只作用于论坛帖子，不影响普通文章与页面。

== Installation ==

1. 上传插件目录到 `wp-content/plugins/`，或在后台「安装插件 → 上传插件」选择 zip。
2. 启用「论坛 AI 摘要」。
3. 进入「论坛AI摘要 → 设置」，选择模型供应商并填写密钥、接口地址与模型。

== Frequently Asked Questions ==

= 需要安装 bbPress 吗？ =

不需要。插件直接对接子比主题自带的论坛系统（`forum_post`）。

= 会改动帖子原标题吗？ =

不会。AI SEO 结果写入子比「独立SEO」字段，`post_title` 保持原样。

= 会不会产生大量新标签？ =

不会。AI 给出的标签只与站内已有 `forum_tag` 匹配，命中才写入关联，未命中即丢弃。

= 生成的摘要在哪里？ =

post meta `_fas_summary`；可选同时写入 `post_excerpt`。

== Changelog ==

= 2.2.0 =
* 后台数据条居中。
* 右栏新增「相关推荐覆盖」：已进推荐 / 从未进入篇数、覆盖率、平均曝光。
* 插件描述精简；作者信息与主页更新。

= 2.1.0 =
* 后台视觉重做：米白纸面、衬线大数字、细分隔线、吸顶毛玻璃顶栏、下划线页签。
* 右栏新增数据可视化：标签 Top 8、每帖标签数分布。
* 样式限定在插件页面作用域内，不影响其它后台页面。

= 2.0.0 =
* 移除外链处理模块、默认分享图、标签相关性分析内核，只保留泛词标签 noindex。
* 后台精简为 4 个页签，说明文字压缩。
* 新增：帖子标题改动后自动重新整理 AI SEO。

= 1.39.0 – 1.43.0 =
* 标签写入固定为「复用已有标签」，并输出标签聚合内链。
* 修复部分帖子前端标签区空白、内链锚词无法落位的问题。
* 标签治理：泛词 noindex、拼音别名（仅新建标签）。

= 早期版本 =
* 多模型接入、发布自动生成、失败重试、相关推荐与内链均衡、AI SEO 三要素。

== Upgrade Notice ==

= 2.2.0 =
仅界面与元信息调整，无数据迁移。
