# 论坛 AI 摘要（Forum AI Summary）— 设计与配置说明

适用版本：**2.2.0**。本文件描述插件当前行为；实现细节以源码为准。

---

## 一、这个插件做什么

为子比（Zibll）主题的论坛帖子（`forum_post`）自动生成**内容摘要**与**相关标签**，并把 AI 能力延伸到 SEO：给帖子写「独立SEO」三要素、给标签/板块补搜索描述、在正文里做语义内链、在文末挂相关推荐。发布时自动跑，失败自动重试。

支持 OpenAI / DeepSeek / Anthropic / 任意 OpenAI 兼容接口。

**v2.0.0 精简原则**：只保留真正在跑、且主题不提供的能力。外链处理、默认分享图、标签相关性分析已移除（见 §八 变更说明）。

---

## 二、功能清单

| 功能 | 做什么 | 触发方式 |
| --- | --- | --- |
| AI 摘要 + 标签 | 生成摘要与 3 个（可配 1–10）相关标签；标签只复用站内已有 `forum_tag`，不新建 | 发布后 5 秒；或编辑页 Meta Box 手动 |
| AI SEO 三要素 | 生成 SEO 标题 / 关键词 / 描述，写入子比「独立SEO」字段（`zib_other_data`），不改帖子原标题 | 首次生成时一并跑 |
| 标题变更重整理 | 帖子标题改动后重跑 AI SEO（版本号 2.3.12 → 2.3.18 这类场景） | 保存后 10 秒，自动 |
| 搜索摘要补全 | 给标签归档页、板块页补 `meta description`（60–120 字），双写子比 term_seo 与原生 term description | 发帖 / 新建标签后 8 秒，分批 |
| 相关推荐 | 帖子正文下方（评论上方）横向卡片，同标签 → 关键词 → 同板块 → 兜底；支持曝光均衡 | 前台渲染，带缓存 |
| 正文自动内链 | AI 从站内选一篇最相关的帖，在正文里给一个**本来就有**的词加链接 | 摘要生成后 10 秒（默认关闭） |
| 泛词标签 noindex | 命中清单（默认 工具/娱乐/系统/下载）的标签页输出 `noindex,follow` | 前台 `wp_robots` |
| 新标签拼音别名 | 新建标签时把中文名转拼音 slug（电视直播 → `dian-shi-zhi-bo`） | 新建标签时，只影响新标签 |
| 存量补齐 | 一键给没有摘要的已发布帖子逐篇补摘要，带进度、可停止 | 设置页「内容」页签 |
| 旧标签 301 | 已被合并/删除的旧标签 URL 301 到主标签（读 `fas_tag_merge_map`） | 前台 `template_redirect` |

---

## 三、后台界面

单页、四个页签，右侧常驻数据栏：

| 区域 | 内容 |
| --- | --- |
| 顶栏（吸顶） | 插件名 + 版本、当前供应商与运行状态、保存按钮 |
| 标题区 | 「设置」+ 一句话说明 |
| 数据条 | 已发布帖子 / 已有摘要 / 缺标签 / 推荐覆盖 |
| 页签 | 基本 · 内容 · SEO · 高级 |
| 右栏 | 标签 Top 8（横向 meter）、每帖标签数分布（柱状 SVG）、相关推荐覆盖（进过推荐 / 从未进入 + 平均曝光） |

- 视觉：米白纸面 + 衬线大数字 + 细分隔线；顶栏使用 `backdrop-filter` 毛玻璃，滚动时内容从下方穿过。
- 动效：页签墨条滑动、开关按压回弹、进度条推进；`prefers-reduced-motion` 下全部关闭。
- 样式全部限定在 `.fas-admin` 作用域，不污染其它后台页面；不支持 `backdrop-filter` 的浏览器自动退回实色背景。
- 右栏图表数据由 `FAS_Batch::rail_stats()` 提供，结果缓存 5 分钟。

## 四、数据存储

**帖子（post meta）**

| 键 | 含义 |
| --- | --- |
| `_fas_summary` | 摘要正文 |
| `_fas_tags` | AI 标签数组（前端展示来源） |
| `_fas_status` / `_fas_error` / `_fas_retry_count` | 生成状态、错误、重试计数 |
| `_fas_content_hash` | 内容指纹，判断是否需要重跑 |
| `_fas_seo_title` / `_fas_seo_keywords` / `_fas_seo_description` / `_fas_seo_generated_at` | 插件自己的 SEO 记录 |
| `_fas_seo_source_title` | **生成 SEO 时依据的帖子标题**（判断标题是否已变） |
| `_fas_ilink` | 内链映射（目标帖 + 锚词候选） |
| `_fas_rel_imp` | 相关推荐曝光计数 |

**子比字段**：帖子 SEO 写 `zib_other_data`（数组内 `title` / `keywords` / `description`）；标签写 term meta `zib_other_data['term_seo']['description']` + 原生 term description。**绝不整键删除** `zib_other_data`。

**option**：`fas_options`（全部配置）、`fas_tag_merge_map`（301 映射，历史数据不可删）、`fas_related_ver`（相关推荐缓存版本）、`fas_db_version`。

**cron 事件**：`fas_generate_event`(+5s)、`fas_retry_event`(退避)、`fas_ilink_auto_event`(+10s)、`fas_desc_auto_event`(+8s)、`fas_seo_retitle_event`(+10s)。停用插件时五个全部清理。

---

## 五、钩子与优先级（改动前必读）

| 钩子 | 优先级 | 用途 |
| --- | --- | --- |
| `the_content` | 15 | 正文内链插入 |
| `the_content` | 20 | 摘要卡片 + 外链（外链已移除，现只余摘要） |
| `the_content` | 30 | 相关推荐 |
| `wp_robots` | 10 | 泛词标签 noindex |
| `template_redirect` | 10 | 旧标签 301 |
| `admin_menu` | 5 / 25 | 父菜单 / 「基本设置」入口 |
| `post_updated` | 10 | 标题变更 → 排队重整理 SEO |
| `transition_post_status` | 10 | 发布链路入口 |

**不可破坏的约束**

1. 父级菜单必须早于子菜单注册（5 < 25），否则子菜单 page hook 名算错 → 403/404。
2. `the_content` 优先级与主题（999 golink）的相对顺序不能随意改。
3. 自动链路只做「缺什么补什么」，不要改成「每次保存都重跑」（会反复烧 API）。
4. 延迟事件执行前必须二次校验（对象还在、状态/开关仍满足）。
5. 新增 `fas_options` 键必须同步三处：`FAS_Options::defaults()`、`FAS_Admin::sanitize()` 白名单、设置页字段。漏白名单 → 保存一次即被冲回默认值。

---

## 六、与子比主题的集成点

| 对象 | 值 |
| --- | --- |
| 论坛帖子 post type | `forum_post` |
| 板块 post type | `plate` |
| 标签分类法 | `forum_tag` |
| 话题分类法 | `forum-topic` |
| 标签归档 URL | `/forum_tag/<slug>`（中文 slug 为百分号编码） |
| 帖子 SEO 存储 | post meta `zib_other_data` |

以上名字全部走 `fas_*` 过滤器（`fas_forum_post_type` / `fas_forum_tag_taxonomy` / `fas_zibll_seo_meta_key` 等），主题版本差异优先用过滤器对齐，不要硬编码改源码。

---

## 七、安装

1. 上传 `forum-ai-summary` 目录到 `wp-content/plugins/`，或后台「插件 → 上传插件」上传 zip。
2. 激活插件，确认子比主题已开启「社区 & 论坛」。
3. 设置 → 选供应商、填 Key/地址/模型，保存。
4. 发一篇帖子验证：5 秒后摘要与标签生成，`<title>` 与 description 使用 AI 值。

---

## 八、变更说明

**移除**

- **外链处理**（原 1467 行）：nofollow / target / GOLINK 中转全部交还子比主题。后台不再有外链设置，`fas_link_options` 不再读取。
- **默认分享图**（原 393 行）：线上 `og:image` 一直是主题自己输出，这个功能实际只当相关推荐卡片的兜底封面。相关推荐现在没有封面时直接显示首字占位。
- **标签相关性分析内核**（原 981 行）与**薄标签 noindex**：后台页面在 v1.41.0 就删了，线上开关也一直是关闭状态，却仍在每个标签归档页做全站分析。标签侧只保留按名称匹配的**泛词 noindex**。

**保留**：摘要、标签、AI SEO、描述补全、相关推荐、正文内链、拼音别名、存量补齐、旧标签 301、系统检测。

**新增**：标题改动自动重整理 AI SEO（`post_updated` → `fas_seo_retitle_event`）。

**规模变化**：PHP 17 类 / 10,150 行 → 14 类 / 7,267 行；后台 CSS 1167 → 111 行；后台 JS 477 → 166 行；`wp_ajax_` 入口 3 → 1。

### v2.1.0

- 后台视觉改为 Editorial 风格：米白纸面、衬线大数字、细分隔线、吸顶毛玻璃顶栏、下划线页签。
- 新增右栏数据可视化：标签 Top 8（横向 meter）与每帖标签数分布（柱状 SVG），数据源 `FAS_Batch::rail_stats()`，缓存 5 分钟。
- 样式限定 `.fas-admin` 作用域；`prefers-reduced-motion` 与 `@supports not (backdrop-filter)` 均有降级。

### v2.2.0

- 数据条居中。
- 右栏新增「相关推荐覆盖」：已进推荐 / 从未进入篇数、覆盖率双色条、平均曝光与累计曝光。
- 插件描述精简至约 150 字；作者改为「开源极界」，插件主页与作者链接指向 https://yd.zlinlang.com/。
