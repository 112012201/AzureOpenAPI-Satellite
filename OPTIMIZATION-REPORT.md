# 优化报告：satellite.api.authskin.xyz → v2.2

> 对象：`satellite.api.authskin.xyz`（CelesTrak / AMSAT / SatNOGS 卫星数据代理）
> 目标：对标新一代 Web API 站点，重构 API 接口层、UI 结构与运维方式，同时**保持旧 URL 可用**。
> 交付：完整可部署站点源码 + OpenAPI 3.1 + 测试套件 + 本报告。

---

## 0. 结论摘要

| 维度 | 优化前 | 优化后 |
| --- | --- | --- |
| API 版本化 | 无（路径即文件名语义） | `/v1/*` 稳定版本 + 旧路由冻结并标注 `Deprecation`/`Link` |
| 缓存协商 | 无 `ETag`/`Last-Modified`，无 `Cache-Control` | `ETag`/`Last-Modified`/`304` + `s-maxage` + `stale-while-revalidate` |
| 过期缓存行为 | 过期即**同步阻塞**回源（最坏 30s 才响应） | 立即返回旧数据，`fastcgi_finish_request()` 后异步刷新（SWR） |
| 错误格式 | 各端点各自为政（JSON/纯文本/中文混排） | RFC 9457 `application/problem+json`，9 个稳定 `code` |
| 大数据端点 | 每次返回全量（发射机 3.67 MB / 5017 条） | 默认分页 + 字段投影（100 条 ≈ 25 KB，**-99.3%**） |
| 合并 TLE 数据质量 | 1396/1733 条名称行带 3LE `0 ` 前缀；头部中文乱码 | NORAD 归一化去重、名称清洗、纯 ASCII 头部 |
| 安全 | `SSL_VERIFYPEER=false`、`exec()` 拼命令、硬编码 AES 密钥 | TLS 校验默认开启、无 shell 调用、密钥必须显式配置 |
| UI | Swagger UI 4 + MDUI/Google Fonts CDN + 主题单调 | 自包含落地页 + Swagger UI 5.32 + 暗/亮主题 + 自包含状态面板（0 外部请求） |
| 可观测性 | 无（只有 `error_log` 文本） | JSON Lines 日志 + 请求指标 + 上游指标 + `X-Request-Id` |
| 测试 | 无 | 163 条单元断言 + 99 条端到端 HTTP 断言 + 43 个 PHP 文件语法校验 + 31 条 OpenAPI 校验 |

---

## 1. 优化前审计（逐条问题 → 影响 → 修复）

### A. 安全

| # | 问题（证据） | 影响 | 修复 |
| --- | --- | --- | --- |
| A1 | `tle_merge.php:86`、`amsat_status_proxy.php:63` 使用 `CURLOPT_SSL_VERIFYPEER => false` | 上游 HTTPS 可被中间人替换，投毒 TLE 数据 | `Http/Client.php` 默认 `verify_tls=true`（`VERIFYHOST=2`），仅允许显式 `SATAPI_VERIFY_TLS=0` 关闭 |
| A2 | `satnogs_*_proxy.php` 用 `exec("/usr/bin/php {$scriptPath} refresh &")` 触发后台刷新 | 路径未转义；PHP 路径硬编码；`disable_functions` 环境直接失效 | 彻底移除 shell 调用，改为 `fastcgi_finish_request()` 后同进程刷新（无 `exec`、无 `&`、无路径依赖） |
| A3 | `common.php:9` 硬编码 AES 回退密钥 `your-32-byte-aes-key-here!1234567890` | 源码泄露即票据可伪造 | `verify_ticket()` 必须显式配置 `AES_KEY`/`AES_IV`，否则**失败关闭**（返回 false） |
| A4 | `.satnogs_satellites_refresh.lock` 时间戳锁在卫星代理中从不删除（仅发射机代理删除） | 锁残留 5 分钟，刷新被静默抑制 | `Cache/Lock.php` 使用 `flock`，进程退出自动释放；`blpop` 式等待有上限 |
| A5 | 缓存文件、`logs/warmup.log`、旧 tar.gz 均在 Web 根目录下可直接下载 | 数据/日志泄露、CDN 缓存污染 | 数据移入 `data/`（`conf/nginx.conf.example` 中 `deny all`）+ `.htaccess` 兜底 + 扩展名封禁 |
| A6 | 无安全响应头、无 CORS 策略、无 `nosniff` | 浏览器侧嗅探/引用风险 | `Response` 统一输出 `X-Content-Type-Options`、`Referrer-Policy`、可配置 CORS、`OPTIONS` 204 |
| A7 | `/health?ui=1` 公开 `phpversion()`、时区、文件大小 | 版本指纹泄露 | 面板改为聚合诊断（去版本指纹），JSON 中不再输出 PHP 版本/时区 |
| A8 | 上游响应无大小上限、无协议白名单 | 恶意/异常上游可耗尽内存 | `Client` 限制 `max_bytes`（32 MB）、仅允许 HTTP/HTTPS、重定向 ≤ 3 |

### B. API 设计与规范性（对标新一代 Web API）

| # | 问题 | 修复 |
| --- | --- | --- |
| B1 | 无版本化：`/tle`、`/health` 直接暴露实现细节；OpenAPI 未描述维护接口 | 新增 `/v1/*`（12 条路由）；旧路由保留并加 `Deprecation: true` + `Link: rel="successor-version"` |
| B2 | 错误体不统一：`{"error":"maintenance"}`、`{"error":{"code":1001,...}}`、纯文本混用 | 统一 RFC 9457：`type/title/status/detail/code/instance/request_id`；TLE 客户端按内容协商仍得纯文本 |
| B3 | 无缓存验证器：客户端只能反复全量下载 | `ETag`（内容+缓存戳派生，**不受请求级时间戳影响**）+ `Last-Modified` + `304` |
| B4 | 无 CDN 缓存指令：EdgeOne 无法判断可缓存性 | `Cache-Control: public, max-age=…, s-maxage=…, stale-while-revalidate=…, stale-if-error=604800` |
| B5 | 无 `HEAD`/`OPTIONS`，无 CORS | 全端点支持 `HEAD`、`OPTIONS` 204、`Allow` 头，405 返回 problem+json |
| B6 | 大列表端点无分页/过滤/投影 | `/v1/satellites`、`/v1/transmitters`、`/v1/amsat/status` 支持 `page/per_page/all/fields/sort/order/search/since` 等 |
| B7 | 只支持一种表示（TLE 纯文本 或 JSON 各自固定） | 内容协商：`Accept` 或 `?format=tle|json`，同一端点两种表示 |
| B8 | 无请求追踪 | 生成/透传 `X-Request-Id`，日志与 problem 文档均带 `request_id` |
| B9 | 无速率限制说明与实现 | `RateLimit`（Redis INCR / 文件计数）+ `X-RateLimit-*` + 429 problem，默认关闭可由环境变量启用 |
| B10 | OpenAPI 3.1.2789-beta，且**与实际响应不符**（`norad_cat_id` 整数/`object_name`/`line1` 等字段名错误），缺 `operationId`、示例、错误响应 | 重写为 OpenAPI 3.1，129 个 `$ref` 全部可解析，**逐字段对齐真实响应样本**，含 19 个 operation、全部错误码与示例 |

### C. 缓存与性能

| # | 问题（证据） | 影响 | 修复 |
| --- | --- | --- | --- |
| C1 | 缓存过期即同步回源（`tle_weather.php:20` 起，全部 4 个 TLE 端点 + 2 个目录端点） | 缓存到期后的第一个请求要等上游 20–30s，且多个并发请求同时回源（惊群） | `Cache\Store::remember()`：新鲜即命中；过期**立即返回旧值**并在响应 flush 后刷新；`Lock` 单飞保证只有一个 worker 回源 |
| C2 | 4 个 TLE 端点各自实现"取数+缓存+解析"，逻辑重复约 4 份 | 修复要改 4 处，行为不一致 | 统一到 `Data\Repository` + `Config::sources()`；端点只负责呈现 |
| C3 | 每次请求重新解析上游文本（合并端点每次解码 255 KB / 1733 条） | CPU 浪费 | 缓存**解析后的结构**（`serialize`），命中即用；文本渲染保持确定性 |
| C4 | 发射机端点每次返回 3.67 MB（5017 条） | 移动端/边缘回源开销大，CDN 命中成本高 | 默认分页 100 条 + 默认 11 字段投影：**25 KB/页**（-99.3%），全量仍可 `?all=1&fields=all` |
| C5 | 无 gzip | 4 MB JSON 明文传输 | PHP gzip（>1 KiB）+ nginx `gzip_types` 双层 |
| C6 | 无文件写入原子性（`file_put_contents` 直接覆盖） | 并发读可能拿到半截文件 | 临时文件 + `rename()` 原子替换 |
| C7 | 合并端点头部含每请求变化的时间戳 | `ETag` 永远变化 → 无法 304、CDN 无法复用 | "Generated" 取自缓存写入时间；JSON 的 `ETag` 由数据+缓存戳派生 |

实测（基于旧站真实缓存数据，见 `tools/metrics.js`）：

```
SatNOGS transmitters : 5017 条, 全量 3.67 MB → 投影 1.21 MB (-67.1%) → 默认 100 条/页 25 KB (-99.3%)
SatNOGS satellites   : 2775 条, 全量 1.45 MB → 投影 0.59 MB (-59.4%) → 默认 100 条/页 22 KB (-99.3%)
Merged TLE           : 1733 组, 258.4 KB (gzip 100.1 KB)，其中 1396 条名称行带 "0 " 前缀（已修复）
AMSAT status         : 932 条, 116.7 KB (gzip 6.9 KB)
```

### D. 正确性缺陷（本次修复的真实 Bug）

| # | 问题 | 证据 | 修复 |
| --- | --- | --- | --- |
| D1 | **去重键不归一化**：CelesTrak 用 `substr($line1,2,5)`（`00965`），SatNOGS 用 `(string)$item['norad_cat_id']`（`965`） | 同一个卫星在合并流中出现两次 | `Parser::normalizeNorad()` 统一补零为 5 位；新增单元测试覆盖 |
| D2 | **3LE 前缀泄漏进名称行** | 旧 `tle_cache_merged.txt` 中 1396/1733 条以 `0 ` 开头（如 `0 ISS (ZARYA)`），2LE/3LE 混排 | `Parser::cleanName()` 去除 `0 ` 前缀，输出统一 2LE |
| D3 | **合并头部中文乱码**（GBK/UTF-8 双重编码） | 旧文件第 6-7 行为 `# 鍚堝苟鍗槦鏁? 1733` | 新头部全 ASCII；`tests/run.php` 断言头部格式 |
| D4 | **`health.php` 的 `critical` 分支永不触发**（`$overall === 'ok'` 与 `redis != connected` 互斥） | 逻辑上死代码 | 重写判定：无任何缓存且 Redis 不可用 → `critical`（503），否则 `degraded`/`ok` |
| D5 | `age_human` 用 `gmdate('H:i:s', $age)`，超 24h 静默回绕 | 陈旧缓存显示为"00:12:30" | 改为 `1d 3h` / `12m 30s` 人类可读格式（含天数） |
| D6 | AMSAT 静态 NORAD 表数据错误：`AO-91 → 43770`（正确 43017）、`UO-11 → 07530`（正确 14781）、`AO-7` 与 `UO-11` 同值 | 状态报告关联到错误卫星 | 修正映射表；优先使用实时 TLE 推导，静态表仅作兜底 |
| D7 | `amsat_status_proxy.php` 通过 **公网 HTTPS 回调自身** `/tle` 获取 NORAD 表 | 自引用 HTTP 调用，环路/超时风险 | 改为只读本地缓存构建映射（`Repository::buildNoradMap()`），绝不发起自请求 |
| D8 | 解析失败静默降级：旧代码 `parse_*` 失败后使用旧缓存但头部仍可能写"remote ok" | 监控误判 | 每个源独立记录 `remote/cache/failed/parse_failed`，写入 `X-Cache-Status` 与源状态数组 |
| D9 | `Tle::textHeader` 曾把"当前时间"写进响应体 | 端到端测试发现的 304 不命中根因 | 改为缓存戳；新增 HTTP 断言"两次相同请求响应体逐字节一致 + If-None-Match → 304" |
| D10 | `Metrics` 修改的是数组副本（`$data = self::snapshot();`） | 指标永远为 0 | 改为引用语义 `&self::data()`，并加单元测试 |
| D11 | 基类 `Base::meta()` 与路由处理器 `System::meta()` 签名冲突 | PHP 致命错误 | 重命名基类方法为 `envelopeMeta()` |
| D12 | `Collection::project(array $rows, array $fields)` 收到 `null`（`fields=all`）时抛 TypeError | `fields=all` 直接 500 | 放开类型约束并处理 `null`，补测试 |
| D13 | 上游不可用且无缓存时，`Store::remember()` 抛出的异常直接逸出（`Repository::dataset()` / `sourceRecords()` / `amsatReports()` 未捕获） | 冷缓存 + 上游故障时不是 503 而是**未捕获异常**（500/致命） | 统一捕获并降级为空数据集，端点据此返回 503 `upstream_unavailable`（端到端测试已覆盖） |
| D14 | `Response::json()` 无条件丢弃调用方传入的 `Content-Type` | 错误响应实际是 `application/json` 而非 RFC 9457 要求的 `application/problem+json` | 尊重显式媒体类型；新增响应头级断言（`Content-Type`/`Retry-After`/`Allow` 等） |

> D9–D14 都是在本环境中**实际运行**测试后才暴露的，说明测试套件确实在起作用。

### E. UI / 前端

| # | 问题 | 修复 |
| --- | --- | --- |
| E1 | `index.html` 只有 19 行，标题拼写错误 `SatlliteOpenAPI`，无落地页、无搜索入口、无示例 | 全新落地页：Hero + 实时健康徽标 + 端点表 + 4 语言快速上手（cURL/JS/Python/PHP）+ 响应示例 + 数据来源署名 |
| E2 | `swagger-initializer.js` 硬编码 `https://satellite.authskin.xyz/openapi.yaml`（与 OpenAPI 中推荐域名不一致，`.xyz` 直连域名失效即白屏） | 改为相对路径 `./openapi.yaml`，并通过 `/v1/meta` 动态读取版本 |
| E3 | 状态面板依赖 `cdn.jsdelivr.net` + `fonts.googleapis.com` | 全部自包含（内联 CSS/SVG），CDN 不可达时仍完整可用 |
| E4 | 无暗色模式、无品牌化，`index.css` 仅隐藏 topbar | 设计令牌驱动的亮/暗主题（跟随系统 + 手动切换 + `localStorage` 记忆）；Swagger UI 主题同步 |
| E5 | 无 `404`/`robots.txt`/`sitemap.xml`/`security.txt`/`webmanifest`；`404.html` 是 nginx 默认页 | 全部补齐，404 页面向 API 导航 |
| E6 | 维护页混合中英文、含 CDN 内联样式 | 双语/英文维护页，自包含，含 `Retry-After` 与状态页链接 |
| E7 | 无移动端适配 | 全站响应式；端点表横向滚动；对比度 ≥ 4.5:1；`prefers-reduced-motion` |
| E8 | 若未启用 JS，文档页空白 | 全静态内容 + `<noscript>` 回退与 YAML 直链 |

### F. 运维与可观测性

| # | 问题 | 修复 |
| --- | --- | --- |
| F1 | `warmup.sh` 硬编码 `/www/wwwroot/...`、`/usr/bin/php`，四个脚本串行，无锁，无日志轮转，退出码恒为 0 | 环境变量配置、单一入口 `php index.php refresh`、`flock` 防重入、日志按大小轮转、上游失败返回非 0 |
| F2 | 无 CLI | `php index.php refresh|health|prune|maintenance`（`--json`、`--target=`） |
| F3 | 日志为无结构 `error_log` 文本，混中英文 | JSON Lines（`ts/level/msg/req_id/ctx`）+ 级别阈值 + 轮转 |
| F4 | 无指标 | `data/metrics.json`：请求数/错误数/平均延迟/缓存命中、上游成功率与最近错误，`/v1/status` 可视化 |
| F5 | 健康检查仅"能连 Redis 吗" | 存活/就绪分离（`/v1/health/live`、`/v1/health/ready`）+ 每个数据集新鲜度 + 维护开关总览 |
| F6 | 无 `.gitignore`，缓存/日志/tar.gz 会入库 | 已补 `.gitignore` |

---

## 2. 新 API 契约

### 2.1 路由

```
GET  /v1/tle                    合并去重 TLE（group/norad/limit/format/meta/pretty）
GET  /v1/tle/{source}           weather | amateur | amsat | satnogs
GET  /v1/satellites             分页 + 投影 + 过滤（SatNOGS）
GET  /v1/transmitters           分页 + 投影 + 过滤（SatNOGS）
GET  /v1/amsat/status           过滤 + 分页（AMSAT 实时报告）
GET  /v1/health                 聚合健康（?ui=1 → 面板）
GET  /v1/health/live            存活
GET  /v1/health/ready           就绪
GET  /v1/status                 HTML 面板
GET  /v1/meta                   服务元数据 + 端点索引 + 错误目录
GET  /v1/maintenance            维护状态列表
POST /v1/maintenance            切换维护（Bearer Token）
```

旧路由（仍可用，标记弃用）：`/tle`、`/tle/{source}`、`/satnogs/satellites`、`/satnogs/transmitters`、
`/amsat/status`、`/health`、`/status`，以及历史文件名 `/tle_merge.php`、`/tle_weather.php`、`/tle_amateur.php`、
`/tle_amsat.php`、`/tle_satnogs.php`、`/satnogs_satellites_proxy.php`、`/satnogs_transmitters_proxy.php`、
`/amsat_status_proxy.php`、`/health.php`（各自 3 行外壳，转发到统一实现）。

### 2.2 响应示例

```json
{
  "meta": {
    "service": "AzureOpenAPI for Satellite", "version": "3.1.2789-beta", "api_version": "v1",
    "generated": "2026-09-20T05:52:38Z", "request_id": "req_b9c39d706f6bddbf",
    "elapsed_ms": 3, "count": 3,
    "sources": [{ "id": "weather", "label": "CelesTrak Weather", "count": 3, "ok": true,
                  "cache": "HIT", "age_seconds": 12, "error": null }],
    "cache": { "status": "HIT", "age_seconds": 12, "ttl": 10800 }
  },
  "data": [{
    "norad_id": "25544", "name": "ISS (ZARYA)",
    "epoch": "2026-09-27T12:00:00Z", "epoch_unix": 1790510400,
    "source": "satnogs", "checksum_ok": true,
    "tle": ["1 25544U 98067A   26270.50000000  .00006292  00000-0  12172-3 0  9997",
            "2 25544  51.6310 211.2092 0004923 144.3133 215.8185 15.49128922585812"]
  }]
}
```

```json
{
  "type": "https://satellite.api.authskin.xyz/errors/upstream-unavailable",
  "title": "Service Unavailable", "status": 503,
  "detail": "Upstream \"weather\" is unavailable and no cached copy exists.",
  "code": "upstream_unavailable", "instance": "/v1/tle/weather",
  "request_id": "req_36e0e5b6…", "sources": [...], "hint": "…"
}
```

### 2.3 缓存与协商矩阵

| 端点 | `s-maxage` 基准 | `stale-while-revalidate` | 关键响应头 |
| --- | --- | --- | --- |
| `/v1/tle`、`/v1/tle/{source}` | 源 TTL（3h/12h）减已用时长，最低 30s | 2×TTL | `ETag`、`Last-Modified`、`X-Cache-Status/Age/TTL/Backend` |
| `/v1/satellites`、`/v1/transmitters` | 6h | 2×TTL | 同上 |
| `/v1/amsat/status` | 1h | 2×TTL | 同上 |
| `/v1/meta` | 600s | — | `ETag` |
| `/v1/health*`、`/v1/status` | `no-store` | — | `X-Health` |

---

## 3. 验证方法与结果

受环境限制（本机无原生 PHP），验证在一个 **PHP 8.4 WebAssembly 运行时**中真实执行，而非仅静态检查。

| 验证项 | 工具 | 结果 |
| --- | --- | --- |
| PHP 语法 | `php-parser`（AST 解析） | **43 个文件，0 错误** |
| 单元/集成断言 | `php tests/run.php`（WASM PHP 8.4） | **163 条断言全部通过** |
| 端到端 HTTP | `tests/request.php` + 请求派发器（真实路由/内容协商/错误/维护/304/响应头） | **99 条断言全部通过**（1 条按能力跳过：本运行时无 zlib） |
| OpenAPI | `js-yaml` 解析 + 路由一致性 + `$ref` 完整性 + **schema 与真实响应样本逐字段比对** | **31 条断言全部通过**（134 个 `$ref` 全解析） |
| 运行环境自检 | `php tests/probe.php --json` | 输出 PHP 版本/扩展/目录可写性/有效配置，缺失必需扩展时返回 1 |
| 前端行为 | jsdom 真实 DOM（由前端实现方执行） | **41 条断言全部通过**（主题、标签页键盘导航、复制、离线降级） |

测试覆盖的关键行为（部分）：

- TLE：3LE/2LE/扁平 AMSAT/SatNOGS JSON 解析、校验和、历元换算（含 57/2000 年界）、坏数据拒绝、名称清洗、归一化去重、同历元取名策略。
- 缓存：HIT/MISS/STALE 判定、SWR 延迟刷新真的写回、上游异常时回退旧数据、原子写、`flock` 单飞。
- HTTP：`?norad=`/`?limit=`/`?group=`/`?fields=all`/`?per_page=` 分页、`format` 非法 → 400、未知源 → 404、未知路由 → 404、`DELETE` → 405、`If-None-Match` → 304 且响应体为空、`HEAD` 无响应体、冷缓存无网络 → 503 `upstream_unavailable`。
- 维护：文件开关生效、TLE 客户端得到 `# 503` 纯文本、JSON 客户端得到 problem 文档、令牌未配置时写接口 503。
- 限流：额度用尽返回 429 且带 `Retry-After`。
- 健康：旧字段（`status/overall/checks.cache_files.tle_merge/service/timestamp`）保持兼容。

> 未能在本环境执行的验证：真实网络下的上游抓取（WASM 无 cURL 扩展，`Client` 已按"缺少扩展"优雅失败并计入上游指标）、
> `gzencode` 压缩分支（无 zlib 扩展，已按能力跳过）、CLI 退出码（WASM 嵌入式 SAPI 不向上传递 `exit(N)`，真实 CLI 正常）、
> 真实 nginx/EdgeOne 的缓存行为、Redis 分支（已用文件后端覆盖同等逻辑路径）。这些需要在上线前做一次冒烟。

---

## 4. 兼容性矩阵

| 旧接口 | 行为变化 | 说明 |
| --- | --- | --- |
| `GET /tle` | 头部注释行改为英文 ASCII；名称行统一为 2LE | 头部仍是 `#` 注释，解析器会跳过；1396 条带 `0 ` 前缀的名称行被修正 |
| `GET /tle/{weather,amateur,amsat,satnogs}` | 完全一致（内容为归一化后的 TLE），新增 `X-Cache-Status` | 过期时新增一行 `#` 提示 |
| `GET /satnogs/satellites|transmitters` | 仍是完整上游数组（未分页） | 仅 JSON 重新序列化，字段与顺序不变 |
| `GET /amsat/status` | 仍是 `{status,generated,total_reports,data[]}`，`timestamp` 仍为 `Y-m-d H:i:s` | 字段名与顺序一致 |
| `GET /health` | 保留 `status/overall/checks.redis/checks.cache_files.<旧名>/service/timestamp`，新增字段 | 旧监控脚本无需修改 |
| `GET /status` | 面板视觉重做（去掉 CDN 依赖） | 展示信息更全 |
| Redis 维护键 | 仍为 `maintenance:<endpoint>`（DB8，无前缀） | 旧运维脚本可继续 set/del |
| `common.php` 中的函数 | `check_maintenance()`、`get_maintenance_status()`、`set_maintenance_status()`、`verify_ticket()` 等仍可用 | 语义不变（`verify_ticket` 改为必须配置密钥） |
| 缓存文件 | 位置由站点根目录改为 `data/cache/`（新格式） | 首次请求会重新拉取并重建缓存 |

---

## 5. 部署与回滚

```bash
# 备份
cp -a /www/wwwroot/satellite.api.authskin.xyz /www/backup/satapi-$(date +%F)

# 部署（保留旧目录名以便 nginx 无需改动）
tar -xzf satellite.api.authskin.xyz_optimized.tar.gz -C /www/wwwroot/
cd /www/wwwroot/satellite.api.authskin.xyz
mkdir -p data/cache data/ratelimit logs && chown -R www:www data logs
cp conf/config.example.php conf/config.php      # 按需修改
export SATAPI_ADMIN_TOKEN='<随机串>'

# 校验
php tests/run.php
php index.php refresh --target=tle,satellites,transmitters,amsat-status
php index.php health --json | head -40
curl -sS -D- -o /dev/null https://satellite.api.authskin.xyz/v1/tle/weather   # 期望 200 + ETag + X-Cache-Status
curl -sS -D- -o /dev/null https://satellite.api.authskin.xyz/health          # 旧监控路径
```

**回滚**：目录整体替换回备份即可；由于 Redis 维护键与旧路径语义保持兼容，无需数据迁移。
旧版写入的 `tle_*_cache.txt` / `*_cache.json` 不再被读取（新实现使用 `data/cache/`），首次请求会回源重建。

---

## 6. 上线前待办

1. **实测一次上游抓取**：确认服务器 cURL/CA 正常（新代码开启 TLS 校验，若系统 CA 过旧需配置 `SATAPI_CA_BUNDLE`）。
2. **确认 nginx 规则**：按 `conf/nginx.conf.example` 封禁 `lib|data|logs|conf|bin|tests` 与 `*.tar.gz`，并启用 gzip。
3. **EdgeOne 缓存策略**：对 `/v1/tle*`、`/v1/satellites`、`/v1/transmitters`、`/v1/amsat/status` 尊重源站 `Cache-Control`（含 `stale-while-revalidate`），`/v1/health*` 与 `/v1/status` 不缓存。
4. **设置 `SATAPI_ADMIN_TOKEN`**（否则 `POST /v1/maintenance` 返回 503，属预期安全默认）。
5. **`SATAPI_TRUST_PROXY=1`**：若开启限流且位于 CDN 之后，需信任 `X-Forwarded-For`。
6. 观察 `data/metrics.json` 与 `logs/app.log` 一周后，再决定是否开启 `SATAPI_RATELIMIT_ENABLED`。

## 7. 后续可选增强

- 用真实 HTTP 服务器（PHP-FPM + nginx）跑一遍 `tests/` 的端到端用例并纳入 CI。
- 为 Redis 分支增加集成测试（当前 CI 环境无 `redis` 扩展）。
- 引入 `/v1/tle?celestrak_group=`（开放 CelesTrak 其他分组）与 GP 数据（`gp.php?FORMAT=json`）以提供更丰富的字段。
- 若上游支持，增加 `If-Modified-Since` 透传到上游的条件请求，进一步减少流量。
- 用 `redoc`/`scalar` 生成静态文档站（当前 Swagger UI 已内置、零外链，优先保证可用性）。

---

## 附：本次交付文件

```
README.md                     部署与运维手册
DEPLOY.md                     完整部署流程（含宝塔伪静态、验证清单、排障、回滚）
OPTIMIZATION-REPORT.md        本报告
openapi.yaml                  OpenAPI 3.1（19 operation / 18 path / 46 schema / 30 example / 134 $ref，且与真实响应逐字段一致）
index.html                    落地页（自包含）
docs.html                     API 参考门户（内置 Swagger UI 5.32 + 主题）
assets/app.css|app.js         设计系统与交互
assets/swagger-theme.css      Swagger 暗/亮主题
index.php, bootstrap.php, lib/**                 应用实现（全站共 43 个 PHP 文件）
legacy 外壳（tle_merge.php、health.php 等）      旧 URL 兼容（各 3 行）
conf/nginx.conf.example       生产 nginx 配置参考
conf/bt-panel-rewrite.conf    宝塔面板「伪静态」块（仅 location/server 级指令，覆盖主配置行为）
conf/config.example.php       配置模板
bin/warmup.sh                 预热脚本
tests/run.php, tests/request.php, tests/probe.php, tests/fixtures/**   测试
404.html, robots.txt, sitemap.xml, site.webmanifest, .well-known/security.txt
.htaccess, .user.ini, .gitignore
```
