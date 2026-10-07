# _plan.md — Satellite Data API v2.2 → v3.0 标准化与 UI 优化

- **session-id**: 20261007-1500
- **项目根**: `D:\DSH\projects\satapi`（本地权威副本，git 基线 `db4e155`）
- **线上站点根**: `/www/wwwroot/satellite.api.authskin.xyz`（PHP-FPM 8.4 / nginx 1.30.4 / 宝塔）
- **备份目录**: `/www/wwwroot/satbak`（用户指定，`cp` 生成 `.bak`）
- **档位**: 大项目（35 文件 / 6643 行 + 新增模块 + 前端），全套门禁

---

## 0. 用户已确认的决策（Step 1.5 出口，不可自行更改）

| # | 决策项 | 用户选择 |
|---|---|---|
| D1 | 现代化深度 | **A. 目标 PHP 8.4**：全量 `strict_types` + 类型声明 + `enum` + `readonly` + 构造器提升 + `match` |
| D2 | 前端范围 | **A. 定向优化 + 修缺陷**：修首页 404 / favicon / 响应头重复；统一 design token、移动端、对比度(a11y)、空/加载/错误态、docs 与落地页视觉一致性 |
| D3 | 空置资源 | **B. 持久化使用统计/审计**：Redis DB9 存请求计数与端点耗时，MySQL 存历史趋势，供 `/v1/status` 展示 |
| D4 | 上线方式 | 本地生成 → 推送服务器；改动前 `cp` 原文件到 `/www/wwwroot/satbak` 生成 `.bak` 备份 |
| D5 | 版本控制 | **A. 本地 git 基线**（已完成，`db4e155`） |

### 🔴 D6（Wave 0 实测发现，需你知情）：**API 实际运行在 PHP 8.2.31，不是 8.4.21**

| 证据 | 内容 |
|---|---|
| `nginx -T` → rewrite conf L50 | `fastcgi_pass unix:/tmp/php-cgi-82.sock;`（伪静态段，`location ~ ^/(v1\|tle\|…)` 优先级高于 vhost 的 `include enable-php-84.conf`） |
| vhost | `include enable-php-84.conf;`（→ `php-cgi-84.sock`）——**只对非 rewrite 前缀的 `.php` 文件生效** |
| I/O 计数实验 | 50 次 `/v1/meta` ⇒ **php82 池 `write_bytes` +204800，php84 池 +0** |
| 探针 `/_probe/p.php` | 返回 `8.4.21` ⇒ 非 rewrite 前缀的 PHP 走 84 |
| 两池扩展 | php82 与 php84 均为 7/7（`redis`/`pdo_mysql`/`curl`/`json`/`openssl`/`yaml`/`mbstring`）⇒ **功能等价** |

**影响与处理**：`enum`/`readonly`/构造器提升/`match`/命名参数在 8.2 **全部可用**，故 D1 的 A 方案不受阻。
但存在两个必须一并解决的问题：① 版本分裂（同一站点两套 PHP 版本 + 两套 `php.ini`，排障与安全隐患）；
② 若代码使用 8.3/8.4 独有特性会在生产致命报错。**处理方案见 W1.8**（把伪静态段统一到 84 并移除硬编码 socket），**约束：代码不得使用 8.3+ 独有语法**。

### 假定项（中级口径：先提案再纠，不阻塞；如不符合请纠正）

| 假定 | 值 | 证伪条件 |
|---|---|---|
| A-1 版本号 | 服务版本 `2.2.0 → 3.0.0`；`api_version` 仍为 `v1`（对外契约不变，仅增量） | 若你要求保持 2.x → 改常量与文档即可 |
| A-2 MySQL 表名 | `api_hourly_stats` / `upstream_hourly_stats` | 若库中已有同名表 → 改名 |
| A-3 保留期 | Redis 分钟桶 48h；MySQL 小时聚合 90 天 | 可配置 |
| A-4 统计写入路径 | **MySQL 只由 CLI 汇总任务写**（`metrics rollup`），请求热路径**只写 Redis** | 若要求请求内直写 MySQL → 需评估性能 |
| A-5 运维修复范围 | 仅修与本站直接相关的服务器问题（`data/` 属主、`.user.ini`、php-fpm 模块告警），不动宝塔面板其他站点 | 越界则退回 |

---

## 1. 审计结论（Step 3 审计阶段，只读取证已完成）

### 1.1 硬缺陷

| ID | 问题 | 取证 | 根因分类 | 影响 |
|---|---|---|---|---|
| X1 | 首页 `/` → 404 | `curl -H Host:satellite.api.authskin.xyz http://127.0.0.1/` → `404`；`/index.html` → `200` | nginx 配置（`index index.php index.html`；标准宝塔块为 `index index.php index.html`，无 `location / try_files` 兜底） | 落地页对爬虫与直接访问不可达；`sitemap.xml` 指向 `/` 与实际冲突 |
| X2 | 文件缓存层不可写 | `/v1/health` 自报 `"cache_dir_writable":false`；`ls -la data` → `cache`/`ratelimit` 属主 `root:root 750`；PHP-FPM `user = www` | 权限（CLI warmup 以 root 跑 `mkdir` 所致） | 两级缓存文件兜底与限流落盘静默失效；Redis 故障时无降级 |
| X3 | 安全响应头重复 | 线上响应头 `x-content-type-options` ×2、`referrer-policy` ×2；`vhost SECURITY-HEADERS` + `Response::withDefaults() L227-228` 各发一次 | 配置重复（两处单一职责缺失） | 冗余头；安全扫描器报配置冲突 |
| X4 | `favicon.ico` 缺失 | nginx error log 多次 `open() favicon.ico failed (2: No such file or directory)`；`curl /favicon.ico` → 404 | 资源缺失 | 持续 404 噪声 |
| X5 | 上游 weather/amateur 高频失败 | `logs/app.log`：`upstream_failed {"source":"weather","error":"HTTP 0"}` 反复；`warmup.log` 显示两源各 13.2s 超时 | 上游可用性 + 客户端超时策略 | 预热 23s；TLE 靠 30 天陈旧兜底 |
| X6 | PHP-FPM 模块冲突 | `php -v` → `Module "mbstring" is already loaded`；`warmup.log` → `Cannot load module "Swow" because conflicting module "swoole" is already loaded`（php82 `php.ini` L2002 `swoole.so` + L2009 `swow.so`） | 服务器配置（非本站代码） | 预热日志污染、stderr 噪声 |
| X7 | `.user.ini` open_basedir 缺尾换行 | `od -c` → 结尾 `t m p /` 无 `\n` | 文件格式 | 低风险，顺手修 |
| **X8** | **`docs.html` / `openapi.yaml` 完全缺失安全响应头** | 完整响应头 dump：`/docs.html` 只有 `Cache-Control: public, max-age=3600`；`/openapi.yaml` 只有 `Cache-Control` + `Access-Control-Allow-Origin`。对照 `/index.html` 有 2× `X-Content-Type-Options`、`Referrer-Policy`、`X-Frame-Options`、`Permissions-Policy` | nginx `add_header` 继承规则：**同一层级一旦出现 `add_header`，父级整组丢弃**。`rewrite conf` 的 `location = /docs.html { add_header Cache-Control … }` 与 `location = /openapi.yaml { add_header … }` 各带自己的 `add_header`，于是服务器级 4 个安全头被整组丢弃 | 参考文档与 OpenAPI 描述**无 `nosniff` / `Referrer-Policy` / `X-Frame-Options`**（点击劫持、MIME 嗅探面）；安全扫描会判不通过 |
| **X9** | **`/v1/satellites` 当前 503**（非代码缺陷，是**真实生效的运维状态**） | 20/20 采样 `code=503 size=340B`；body：`{"code":"maintenance","detail":"计划修复与重置，预计 3 天","maintenance":{"endpoint":"satnogs_satellites","retry_after":200403,"until":"2026-10-09T14:40:50Z"}}`；`data/.maintenance_satnogs_satellites` mtime `2026-10-06 22:40:50`，Redis `maintenance:satnogs_satellites` TTL 200403 | 运维状态（`Maintenance::set()` 写入，`Maintenance::enforce()` 拦截，实码 L96-131） | **不是缺陷**。已登记为既有维护窗口，改造不得清除；`Retry-After` 计算经实码核算正确（`until - time()`） |
| **X10** | **同站点运行两套 PHP（分割）** | 见 D6 | 配置（伪静态段硬编码 `php-cgi-82.sock` 压过 vhost 的 `enable-php-84.conf`） | 版本分裂；`.user.ini`、`php.ini`、cron、FPM 调优全部需要维护两份；代码若用 8.3+ 语法会在生产致命报错 |
| **X11** | **`site.webmanifest` 与 `robots.txt`/`404.html` 未列 `favicon.ico`** | `robots.txt` `Allow` 列表无 `favicon.ico`；manifest `icons[]` 无 `any`/`maskable` 的 `.ico` | 资源与策略遗漏 | 爬虫抓取 favicon 时被隐式约束；PWA 图标不完整 |
| **X12** | **README 宣称"163 条断言"，实际 160 条** | `tests/run.php` → `assertions: 160  passed: 160  failed: 0`；`README.md` L156 写 "163 条断言" | 文档漂移 | 验收口径不可信 |

### 1.2 资源空置（D3 触发）

- Redis `DB8`：8 个 `satapi:dataset:*` 缓存键（正常使用）；`DB9`：`dbsize=0`（**完全空置**）
- MySQL `satapi`：`show tables` **空**（**完全空置**）
- `redis-cli config get maxmemory` → `0`（无上限）；`maxmemory-policy` → `noeviction`
- `data/metrics.json`：**并发丢失**——每个 PHP-FPM worker 读-改-写整份 JSON（`Metrics::flush()` L149-168），多 worker 并发时计数互相覆盖

### 1.3 前端现状

- 已有自定义设计系统：`assets/app.css`(24KB) + `assets/app.js`(20KB) + `assets/swagger-theme.css`(6.6KB)
- 落地页 `index.html`(35KB) 自包含：skip-link、`aria-*`、`data-theme` 暗色切换、`prefers-color-scheme`、内联防闪烁主题脚本
- `docs.html`(10.7KB)：Swagger UI 5.32 自托管 + 内联 fallback chrome
- 状态面板：`StatusPage.php` 服务端渲染
- 缺口：无 `favicon.ico`；三处页面（落地页 / docs / 状态面板）的 token 与组件边界不统一

### 1.4 已足够好（**不改**，避免过度工程化）

`/v1` 版本化 + legacy 兼容与 `Deprecation`/`Link` 头、RFC 9457 `problem+json`、ETag/304 + `stale-while-revalidate`、SWR + `flock` 单飞、JSON Lines 日志 + `X-Request-Id`、CORS/压缩/限流、163 条断言测试套件、PWA manifest、`security.txt`、`robots.txt`。

---

## 2. 规划门禁（PLAN-GATE）

### A 池：无条件核心项

- ☑ **A1 需求→步骤映射**：D1→W2/W3/W4；D2→W1/W6；D3→W5/W7；D4→W8；D5→已完成。下方第 6 节逐 Wave 标注覆盖的决策号。
- ☑ **A2 出口校验**：每 Wave 列「出口」为可运行判定（`php -l` 全绿 / `tests/run.php` 退出码 0 / `curl` 状态码与字段断言 / 截图）。
- ☑ **A3 依赖已标注**：`ext-redis`、`ext-pdo_mysql`（php84 已加载 `pdo_mysql`、`redis`，见 `php -m`）、MySQL 8.4.8、Redis 6379、nginx 1.30.4；无新增第三方库（**B2 = N/A**）。
- ☑ **A4 风险与缓解**：见第 5 节。
- ☑ **A5 验证方式**：`php -l` + `tests/run.php` + `tests/request.php` 真实派发 + `curl` 真实 HTTP（含 `Host` 头） + MySQL/Redis 实际查询回读。
- ☑ **A6 回滚**：每个改动文件推送前 `cp` 至 `/www/wwwroot/satbak/<相对路径>.bak`；git 基线 `db4e155` 可整体还原；新增文件删除即回滚；**MySQL DDL 只 `CREATE TABLE IF NOT EXISTS`，无破坏性变更**，回滚=删表（写回滚 SQL）。
- ☑ **A7 批量范围**：`lib/**` 27 个 PHP 文件全量改造 + 新增 3~4 个类 + 根文件 5 个 + `assets/*` 3 个 + `tests/run.php` 扩展；预计改动 ≥ 35 文件、新增 ≥ 900 行 → **C 池启用**。
- ☑ **A8 安全触发面**：外部输入（`Request` 已有 `int()/bool()/header()` 白名单式读取）、凭证（MySQL 密码 → **仅经 `SATAPI_MYSQL_*` 环境变量或 `conf/config.php`，禁入库禁入日志**）、数据库写入（仅聚合任务，参数化 PDO）、后台操作（`POST /v1/maintenance` 已有 `hash_equals` Bearer 校验）。
- ☑ **A9 人工干预文件**：已核。线上与归档**逐字节一致**（0 差异），无未记录的手工改动；`conf/config.php` **不存在**（已核），故新增配置文件属新建而非覆盖。
- ☑ **A10 中断点与恢复**：每 Wave 末写 `.ai-memory/handoff.md`；失败回退至本 Wave 起点 + `.bak` 恢复。

### B 池：条件触发项

- **B1 ☑ 数据库迁移回滚**：`CREATE TABLE IF NOT EXISTS`（幂等）；回滚 SQL `DROP TABLE api_hourly_stats, upstream_hourly_stats;` 已写入 `DEPLOY.md`。
- **B2 ☑ N/A（无新依赖）**：仅用已在 `php -m` 中确认存在的 `redis`、`pdo_mysql` 扩展；不引入 Composer 包。
- **B3 ☑ 并发/幂等**：Redis 用 `HINCRBY`（原子）替代读-改-写；MySQL 汇总用 `INSERT ... ON DUPLICATE KEY UPDATE`（幂等可重放）；cron 用 `flock -xn` 防重入。
- **B4 ☑ 性能敏感路径**：请求热路径新增 Redis 写入 → 必须给「改前/改后」`time_total` 对比基线，禁止无基线声称提升。
- **B5 ☑ 外部系统兼容**：`/v1` 契约**只增不改**；`/v1/health`、`/v1/status`、`/v1/meta` 新增键为可选新增；旧 URL 全部保留。
- **B6 ☑ 测试**：扩展现有 163 条断言套件，新增覆盖 Metrics 驱动 / 枚举 / 聚合 SQL 构造 / 新头逻辑；`tests/request.php` 做真实入口驱动。
- **B7 ☑ 批处理**：批量改文件按 Wave 分批，每 5 文件 CHECKPOINT（C1）。
- **B8 ☑ 不可逆操作**：无。全部为可逆文件写入 + 幂等 DDL；已在 A6 定义 `.bak` 与 git 双回滚。
- **B9 ☑ 多文件影响面**：无本地 PHP 图谱工具可用 → 降级为 **grep 复核**。已 grep 的关键引用：`metrics.file`（Config L104、Metrics L32、tests/run L122、tests/request L58）；`Metrics::` 调用点；`Store::` 调用点；`Response::withDefaults` 头清单。
- **B10 ☑ 命令行/脚本**：`lib/Cli.php` 新增 `metrics` 子命令，参数化（`--json` / `--rollup` / `--prune-days=N`），**无硬编码路径**（走 `Config::path()`）。
- **B11 ☑ 方案回读**：本文件写后回读确认无乱码/截断（见第 9 节）。

### C 池：规模触发项

- **C1 ☑**：改动 ≥ 5 文件 → 每 5 文件落 `[CHECKPOINT]`（进度 + 已验证证据）。
- **C2 ☑**：跨 8 个 Wave → 每 Wave 末落 `.ai-memory/handoff.md`。

### 规划自审

1. **占位符扫描**：本文件无 `TODO` / `待补充` / `{未定义}`；唯一开放项为第 9 节「未知清单」，已具名并给闭合方式。
2. **签名一致性**：下文引用的 `Metrics::record()`(Metrics.php L91)、`Metrics::upstream()`(L128)、`Metrics::summary()`(L175)、`Metrics::flush()`(L149)、`Store::remember()`(Store.php L40)、`Response::withDefaults()`(Response.php L214)、`System::collect()`(L249)、`Catalog::MAX_PER_PAGE`(Catalog.php L34)、`Repository::cacheKeys()`(被 System.php L258 调用) 均已按实码逐行确认存在且签名相符。
3. **需求→步骤映射**：每个 Wave 在标题后标注 `[D1][D2][D3]` 等覆盖号；无需求的纯防御性步骤仅 1 处（X6 模块告警，标注「防御性/运维」，必要性=预热日志与 stderr 噪声影响排障）。
4. **引用点实码 trace**：见 B9，全部经 grep/read 确认，未凭记忆引用。

### 方案成形自检

**一、方案五段**

- **① 输入/输出/约束**
  - 输入域：HTTP 请求（方法 / 路径 / 头 / query / JSON body）、上游 HTTP 响应（TLE 文本 / JSON / HTML，最大 32 MiB）、CLI 参数。
  - 输出契约：`{meta, data}` JSON 信封；错误 `application/problem+json`（RFC 9457）；TLE 文本按 `Accept` 协商。**新增键一律可选**。
  - 硬约束：PHP 8.4 语法目标；对外 `/v1` 契约不变；无新第三方依赖；`data/`、`logs/`、`lib/`、`conf/`、`bin/`、`tests/` 拒绝 Web 访问；凭证不入仓不入日志。
- **② 方案与取舍**（≥2 候选）

  | 议题 | 候选 A | 候选 B | 选定 + 理由 | 反选理由 | 撤销条件 |
  |---|---|---|---|---|---|
  | 现代化粒度 | 全量重写为类型化类 | 保留 `array()` 只加类型声明 | **A**（D1 明示要求） | B 不满足 D1 | 若 `php -l` 或测试大面积失败且 2 轮无法收敛 → 退 B |
  | 统计存储 | Redis 原子计数 + CLI 汇总入 MySQL | 请求内直接写 MySQL | **A** | B 给每个请求加 2~5ms DB 写、放大故障面 | 若你要求实时性 < 1min → 转 B |
  | 响应头归口 | 单一来源（`security_headers_owner` 配置） | nginx 与应用都发（现状） | **A** | 现状即 X3 缺陷 | 无 |
  | 首页路由 | nginx `location = /` 内部重写到 `/index.html` | PHP 增加 `/` 路由 | **A** | B 让静态首页多走一次 PHP，违反"静态直出"既有设计 | 若你希望 `/` 未来动态化 → 转 B |
  | Metrics 兼容 | 保留 `metrics.file` 驱动作为默认，Redis/MySQL 为可选 | 直接替换为 Redis | **A** | 直接替换会破坏 `tests/*` 与无 Redis 环境的降级 | 无 |

- **③ 边界与失败模式**（穷举维度 + 每档处置）

  | 失败模式 | 处置档 | 具体行为 |
  |---|---|---|
  | 空值（无 endpoint / 空 header） | 放行+标记 | `Metrics::record('unknown', ...)`，桶名归一 |
  | 非法值（`per_page=0` / `ttl=999999`） | **拒绝** | 已有 `Request::int()` 夹取 + `Problem::send(400)`（System.php L197-199） |
  | 超长（> 32 MiB 上游） | **拒绝** | `Http\Client` 的 `max_bytes` 截断并抛错（既有） |
  | 超时（上游 20s） | 放行+标记 | 服务陈旧副本 + `cache_producer_failed_serving_stale` 日志（既有，W4 调整超时策略） |
  | 依赖不可用（Redis 挂） | 放行+标记 | Store 降级至文件；**W1 先修文件可写**，否则降级链断裂 |
  | 依赖不可用（MySQL 挂） | 放行+标记 | 汇总任务失败退非 0 并记日志，**请求路径不受影响**（MySQL 不在热路径） |
  | 并发冲突（多 worker 计数） | 放行+标记 | Redis `HINCRBY` 原子；MySQL `ON DUPLICATE KEY UPDATE` |
  | 重放（汇总任务重跑同小时） | 放行+标记 | 幂等 upsert，重复执行结果一致 |
  | 部分成功（7 源中 2 源失败） | 汇总上报 | 逐源记录 `ok/fail`，`overall` 降 `degraded`，走 `/v1/health` 上报 |
  | 配置缺失（无 MySQL 凭证） | 放行+标记 | 统计功能自动禁用并在 `/v1/status` 标注 `metrics_store: disabled`，**不报错** |

  **验收**：E2E 必须同时覆盖「拒绝」（非法 `per_page`）与「放行+标记」（无 MySQL 凭证时降级）两态。

- **④ 权衡结论**：**可读性/现代化 vs 回归风险** → 选「保留同构架构 + 类型化 + 枚举」，**不引入 DI 容器 / 框架 / Composer**（架构一致性铁律：范式唯一）。代价：静态类 + 静态属性得以保留，不是"最纯正"的现代 PHP，但回归面可控。**性能 vs 实时性** → 选「Redis 实时（秒级可见）+ MySQL 分钟级汇总」，代价是 MySQL 趋势最多滞后一个汇总周期（默认 300s）。

- **⑤ 产出与局限**
  - 交付物：改造后的完整站点源码、`lib/Metrics/*` 新模块、`lib/Support/Schema.php`（DDL）、`schema/migrations` SQL、扩展后的测试套件、更新后的 `openapi.yaml` / `README.md` / `DEPLOY.md`、`/www/wwwroot/satbak` 备份、回滚脚本。
  - 未覆盖：不做 API Key / 配额体系（D3 未选 C）；不改宝塔面板其他站点配置；不做 CDN（EdgeOne）侧规则变更。

**二、六类必过动作**：① 多路径取舍已列 5 组；② 目标/约束/成功判据已具名，歧义见第 9 节；③ 失败模式 10 类已分档；④ 产出按可独立验收的 Wave 组织；⑤ 局限已列；⑥ 每条结论锚定实码行号（见 1.1、B9）。

**三、失败模式处置分档**：见 ③，10 类全部定档，「拒绝」与「放行+标记」两态齐备。

**四、三清单**

- **二阶效应**
  1. 改 `data/` 属主 → 触发链：`chown www:www` ⇒ CLI(cron, root) 仍可写（root 无视权限）且 FPM 可写 ⇒ 观察指标 `cache_dir_writable:true`；兜底：若 cron 写入产生 root 属主新文件，`warmup.sh` 末尾补 `chown`。
  2. 改 `data/metrics.json` 并发模型 ⇒ Redis 计数与 JSON 计数**双写期数字不一致** ⇒ 观察指标：`/v1/status` 两栏对照；兜底：以 Redis 为权威，JSON 降级为兜底快照。
  3. 新增 MySQL 小时表 ⇒ 磁盘增长 ⇒ 观察指标 `information_schema.TABLES.DATA_LENGTH`；兜底：90 天 `prune` + 上限告警。
  4. 响应头归口改到单一来源 ⇒ **静态**文件（`docs.html` / `openapi.yaml`）若 nginx 头被移除将失去 `nosniff` ⇒ 兜底：配置项默认 `nginx`（nginx 发、应用跳过），并在 `docs.html` 加 `<meta http-equiv>` 双保险。
  5. 改 `index` 指令顺序 ⇒ 影响同 vhost 的 `satellite.authskin.xyz`（同 root）⇒ 该域首页同样变为 200（**预期改善**，非副作用）；兜底：`/v1/meta` 与 API 路径不受影响（`location` 优先级高于 `index`）。

- **歧义清单**（每条定「澄清」或「声明假定」）

  | 歧义 | 处置 |
  |---|---|
  | 版本号是否 jump 3.0 | **声明假定 A-1**（3.0.0；证伪条件：你要求留 2.x） |
  | MySQL 表名 | **声明假定 A-2** |
  | 保留期 | **声明假定 A-3** |
  | 统计是否需要实时（<1min） | **声明假定 A-4**（热路径仅 Redis，MySQL 分钟级汇总；证伪条件见 ②） |
  | 是否可用 MySQL 建表（DDL） | **声明假定**：D3 的 B 选项语义已含"MySQL 存历史趋势"，故建表在授权范围内；DDL 仅 `CREATE TABLE IF NOT EXISTS`，可逆（`DROP TABLE`） |
  | 运维修复边界 | **声明假定 A-5**（仅本站相关） |

- **未知清单**（具名 + 闭合方式 + 影响）

  | 未知 | 闭合方式 | 对决策的影响 | 状态 |
  |---|---|---|---|
  | php84 FPM 是否已加载 `pdo_mysql` | `php84 -m \| grep pdo_mysql` | 未加载则 PDO 方案不可行，需退 `mysqli` | **已闭合：`php -m` 输出含 `pdo_mysql`** |
  | `conf/config.php` 是否已存在（避免覆盖） | `ls conf/` | 不存在 → 新建安全 | **已闭合：仅 3 个 `.example`/`.conf` 文件** |
  | 线上与归档是否有手工改动 | 逐文件 sha256 比对 | 有则需先合并 | **已闭合：0 差异** |
  | `tests/run.php` 在 php84 CLI 下是否全绿（改动前基线） | 服务器跑 `php84 tests/run.php` | 不绿则先修基线再改 | **W0 闭合** |
  | 当前 `/v1/tle` 等端点的 `time_total` 基线 | 服务器 `curl -w` 多轮采样 | 无基线则 B4 不可声称提升 | **W0 闭合** |
  | Redis DB9 是否被其他应用占用 | `redis-cli -n 9 keys '*'` | 已占用则需换 DB | **已闭合：`dbsize=0`** |
  | MySQL `satapi` 用户是否有 `CREATE` 权限 | `SHOW GRANTS` | 无权限则 DDL 需 root 代执行 | **W0 闭合** |

**五、判据间冲突裁决次序**：安全·不可逆 ＞ 证据门槛 ＞ 范围纪律 ＞ 止损 ＞ 原样验收 ＞ 可读合并。本方案无越界"顺手修"（X6 已显式标注为防御性并限于本站相关）。

---

## 3. 目标架构（Delta）

### 3.1 API 约定（**只增不改**）

| 变更 | 详情 |
|---|---|
| 新增 `GET /v1/metrics` | 运行统计视图（`?window=hour\|day\|week`、`?limit=`）；`Cache-Control: public, max-age=60` |
| `/v1/status` 新增区块 | `metrics_store`（file/redis/mysql 状态）+ `trends`（近 24h 按小时）+ `latency`（p50/p95/p99） |
| `/v1/health` 新增键 | `checks.metrics_store`、`checks.storage.cache_dir_writable`（已存在，改为真实值） |
| `/v1/meta` 新增键 | `metrics`（驱动与保留期声明）、`build` 增强 |
| 响应头 | 新增 `X-Metrics-Store`；按 `security_headers_owner` 决定是否下发 `nosniff` / `Referrer-Policy` |
| CLI | 新增 `php index.php metrics [--json] [--rollup] [--prune-days=N]` |

### 3.2 新增/改造类

```
lib/Support/Metrics.php            → 门面：委托给驱动（保留 record/upstream/summary/flush 签名）
lib/Metrics/Driver.php             → interface: record(), upstream(), snapshot(), flush(), name(), healthy()
lib/Metrics/FileDriver.php         → 现有 JSON 行为（默认，向后兼容）
lib/Metrics/RedisDriver.php        → DB9 HINCRBY 原子计数 + 分钟桶 TTL
lib/Metrics/CompositeDriver.php    → 并行 fan-out：file + redis；读取时以 redis 为权威
lib/Metrics/Rollup.php             → Redis 分钟桶 → MySQL 小时 upsert（幂等）
lib/Support/MySQL.php              → 惰性 PDO 单例（DSN/凭证来自 Config；不可用返回 null）
lib/Support/Schema.php             → CREATE TABLE IF NOT EXISTS DDL + 版本化 ensure()
lib/Support/Enum/*.php             → CacheStatus / HealthStatus / MetricDriver / SourceId
lib/Http/Headers.php               → 响应头单一来源策略（security_headers_owner）
```

### 3.3 Redis DB9 键设计（前缀 `satapi:m:`，全部带 TTL）

| 键 | 类型 | 字段/成员 | TTL |
|---|---|---|---|
| `satapi:m:b:<YYYYMMDDHHMM>` | HASH | `r`=请求数, `e`=错误数, `b`=字节, `ms`=耗时和, `h`=HIT, `m`=MISS, `s`=STALE, `o`=OTHER | 48h |
| `satapi:m:e:<endpoint>:<YYYYMMDDHHMM>` | HASH | 同上 | 48h |
| `satapi:m:u:<source>:<YYYYMMDDHHMM>` | HASH | `ok`, `fail`, `ms`, `msmax`, `lerr`=最后错误 | 48h |
| `satapi:m:lat:<endpoint>:<YYYYMMDDHHMM>` | LIST | 每次请求耗时（ms），`LTRIM 0 999` 保留 1000 个 | 48h |
| `satapi:m:buckets` | ZSET | member=`<YYYYMMDDHHMM>`, score=`time()` | 48h |
| `satapi:m:rollup:cursor` | STRING | 最近成功汇总的分钟桶 | 无 |

### 3.4 MySQL Schema（`satapi` 库，UTF8MB4）

```sql
CREATE TABLE IF NOT EXISTS api_hourly_stats (
  endpoint      VARCHAR(64)  NOT NULL,
  bucket        DATETIME     NOT NULL,          -- 小时边界，UTC
  requests      INT UNSIGNED NOT NULL DEFAULT 0,
  errors        INT UNSIGNED NOT NULL DEFAULT 0,
  bytes         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ms_sum        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  cache_hit     INT UNSIGNED NOT NULL DEFAULT 0,
  cache_miss    INT UNSIGNED NOT NULL DEFAULT 0,
  cache_stale   INT UNSIGNED NOT NULL DEFAULT 0,
  lat_p50       INT UNSIGNED NULL,
  lat_p95       INT UNSIGNED NULL,
  lat_p99       INT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (endpoint, bucket),
  KEY idx_bucket (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS upstream_hourly_stats (
  source     VARCHAR(64) NOT NULL,
  bucket     DATETIME    NOT NULL,
  ok_count   INT UNSIGNED NOT NULL DEFAULT 0,
  fail_count INT UNSIGNED NOT NULL DEFAULT 0,
  ms_sum     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ms_max     INT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (source, bucket),
  KEY idx_bucket (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**回滚 SQL**：`DROP TABLE IF EXISTS api_hourly_stats, upstream_hourly_stats;`
**写入**：`INSERT ... ON DUPLICATE KEY UPDATE`（幂等）。**保留**：`DELETE FROM ... WHERE bucket < UTC_TIMESTAMP() - INTERVAL 90 DAY`，仅在 `prune` 中执行。
**安全**：全部 PDO 预处理；凭证仅来自 `Config::get('metrics.mysql.*')` ← `SATAPI_MYSQL_*` 环境变量 / `conf/config.php`；`Logger` 禁止打印 DSN 或密码。

---

## 4. 实测证据（Step 3 审计阶段已归档）

| 编号 | 命令 | 输出摘要 | 结论 |
|---|---|---|---|
| E1 | `ssh ... 'ls -la /www/wwwroot/satellite.api.authskin.xyz'` | 与归档一致，含 `satellite.api.authskin.xyz_optimized.tar.gz`(667405) | 部署=归档 |
| E2 | `scp` 拉取 + sha256 逐文件比对 | **0 差异 / 25 未拉取文件（非差异）** | 无手工改动 |
| E3 | `curl -H 'Host: satellite.api.authskin.xyz' http://127.0.0.1/` | `404 0.0238s 2150B` | **X1 确认**（2150B = `404.html` 大小） |
| E4 | `curl ... /v1/health \| grep cache_dir_writable` | `"cache_dir_writable":false` | **X2 确认** |
| E5 | `ls -la data` | `drwxr-x--- root root cache,ratelimit` | **X2 根因确认** |
| E6 | `curl -D - https://.../v1/meta` | `x-content-type-options` ×2、`referrer-policy` ×2 | **X3 确认** |
| E7 | `tail /www/wwwlogs/satellite.authskin.xyz.error.log` | 多条 `open() .../favicon.ico failed` | **X4 确认** |
| E8 | `tail logs/app.log` | `upstream_failed {"source":"weather","error":"HTTP 0"}` 反复 | **X5 确认** |
| E9 | `php -v` / `warmup.log` | `Module "mbstring" is already loaded` / `Swow ... conflicting ... swoole` | **X6 确认** |
| E10 | `redis-cli -n 9 dbsize` / `-n 8 dbsize` | `0` / `8` | **DB9 空置确认** |
| E11 | `mysql -usatapi satapi -e 'show tables'` | 空 | **MySQL 空置确认** |
| E12 | `redis-cli config get maxmemory / databases` | `0` / `16` | Redis 无内存上限 |
| E13 | `grep -nE '^(user\|group\|listen)' php84/etc/php-fpm.conf` | `user = www`, `listen = /tmp/php-cgi-84.sock` | FPM 身份确认（X2 关联） |
| E14 | `php84 -m` | 含 `redis`, `pdo_mysql`, `json`, `curl`, `openssl` | 方案依赖满足 |
| E15 | `ls conf/` | 仅 `config.example.php` / `bt-panel-rewrite.conf` / `nginx.conf.example` | 无 `config.php` → 新建安全 |
| E16 | `cat vhost/satellite.authskin.xyz.conf` | `server_name satellite.authskin.xyz satellite.api.authskin.xyz;` `index index.php index.html` | **X1 根因确认**（无 `/` 静态兜底） |
| E17 | `git log --oneline` (本地) | `db4e155` baseline + `.gitattributes` | D5 完成 |
| E18 | `php84 tests/run.php` (staging, Redis 关闭) | `assertions: 160  passed: 160  failed: 0`，`exit 0` | **回归基线 = 160/160 全绿**；README 的 163 是漂移（X12） |
| E19 | `php84 -l` 全量 35 个 PHP 文件 (staging) | `syntax failures: 0` | 语法基线干净 |
| E20 | 完整 `curl -D -` 头 dump（`/docs.html` `/openapi.yaml` `/robots.txt` `/index.html` `/v1/meta` `/404.html`） | `/docs.html` 与 `/openapi.yaml` 无任何安全头；`/index.html` 2× `X-Content-Type-Options` | **X8 确认**（先前被输出截断误判为已修复） |
| E21 | `nginx -T` 抽取全部 `add_header`（站点作用域） | vhost L39 `add_header X-Content-Type-Options` + rewrite L129 同名列 = **2 个 nginx 源**；PHP `Response::withDefaults()` L227 = 第 3 个 | **X3 根因确认（3 个来源）**；`nginx -V` 无 headers-more 模块 |
| E22 | I/O 计数实验：50× `/v1/meta` 前后 `/proc/<pid>/io` `write_bytes` | php82 池 `+204800`，php84 池 `+0` | **X10/D6 确认：API 运行在 PHP 8.2.31** |
| E23 | `/proc` master cmdline + `enable-php-*.conf` | 两个 master：`php/82`(pid 1513)、`php/84`(pid 2626964)；vhost `include enable-php-84.conf` | 版本分裂机制确认 |
| E24 | `redis-cli info server` / `config get maxmemory` | `redis_version:8.0.5`、`maxmemory=0`、`policy=noeviction` | Redis 无内存上限（R10） |
| E25 | 性能基线（各 20 次，`/v1/*` 经 php82） | 见下表 | **B4 基线已建立** |
| E26 | `GET /v1/maintenance` + 503 body | `satnogs_satellites` `enabled:true` `source:redis`，`until 2026-10-09T14:40:50Z` | **X9 = 真实维护窗口**，非缺陷 |
| E27 | `MYSQL_PWD=… mysql -usatapi -e "SHOW GRANTS"` | `GRANT ALL PRIVILEGES ON satapi.* TO satapi@localhost` | **W0.5 闭合：有 CREATE 权限**，DDL 可自建 |

### 4.1 性能基线（Wave 0 采样，各 20 次，经 php82 + Redis 命中）

| 端点 | 状态码 | 大小 | p50 | p95 | p99 | max |
|---|---|---|---|---|---|---|
| `/v1/tle` (merged) | 200 | 235 KB | 0.1037 | 0.1464 | 0.1464 | 0.1615 |
| `/v1/tle/weather` | 200 | 11 KB | 0.0622 | 0.0848 | 0.0848 | 0.0945 |
| `/v1/tle/amateur` | 200 | 15 KB | 0.0616 | 0.0694 | 0.0694 | 0.0710 |
| `/v1/transmitters?per_page=100` | 200 | 25 KB | 0.2470 | 0.3026 | 0.3026 | 0.3098 |
| `/v1/satellites?per_page=100` | **503** | 340 B | 0.0543 | 0.0693 | 0.0693 | 0.0697 |
| `/v1/amsat/status?per_page=50` | 200 | 8 KB | 0.1522 | 0.3689 | 0.3689 | 0.4026 |
| `/v1/health` | 200 | 5 KB | 0.1354 | 0.1680 | 0.1680 | 0.1712 |
| `/v1/meta` | 200 | 4.5 KB | 0.0644 | 0.0800 | 0.0800 | 0.0859 |
| `/v1/status` | 200 | 13 KB | 0.1432 | 0.3076 | 0.3076 | 0.4374 |
| `/index.html`（静态直出） | 200 | 35 KB | **0.0013** | 0.0042 | 0.0042 | 0.0083 |
| `/docs.html`（静态直出） | 200 | 10 KB | **0.0016** | 0.0122 | 0.0122 | 0.0149 |

> `/index.html` p50=1.3ms 确认它是 nginx 静态直出（不走 PHP），**这是修 `/` 404 的安全路径**（内部重写到静态文件，不引入 PHP 开销）。
>
> 缓存与协商验证：`X-Cache-Status: HIT`、`X-Cache-Backend: redis`、`If-None-Match` → **304** ✅

---

## 5. 风险登记

| ID | 风险 | 概率 | 影响 | 缓解 |
|---|---|---|---|---|
| R1 | 全量类型化改写引入语法错误 → 站点 500 | 中 | 高 | 每文件推送前 `php84 -l`；先在 staging 目录整体验证；分批推送；`.bak` + git 双回滚 |
| R2 | `strict_types` 暴露既有的隐式类型转换 bug（如 `Request::int()` 返回 float、`Config::get()` 返回 mixed 直接参与算术） | 高 | 中 | strict_types 下 PHP 仍会做 int↔float 隐式转换；真正的风险在 `string` 传 `int` 形参 → 靠 `tests/run.php` 163 条 + 新增断言捕获；逐文件改逐文件测 |
| R3 | MySQL 建表权限不足 | 中 | 低 | W0 用 `SHOW GRANTS` 闭合；不足则 DDL 交你代执行，代码仍能在无表时降级 |
| R4 | 请求热路径新增 Redis 写入使 P95 变差 | 中 | 中 | 用 pipeline 合并为 1 RTT；W0 采基线 + W5 后对比；超标则改为 `fastcgi_finish_request()` 后写 |
| R5 | `security_headers_owner` 改错 → 静态文件缺 `nosniff` | 低 | 中 | 默认 `nginx`；仅移除应用侧重复，nginx 侧保留全部；E2E 断言静态与动态各 1 个 `nosniff` |
| R6 | /www/wwwroot/satbak 因 `cp` 备份占用磁盘膨胀 | 低 | 低 | 服务器余 32G；备份仅文本文件（< 3MB）；推送脚本输出总大小 |
| R7 | 改动 `data/` 属主影响其他站点 | 低 | 低 | 仅 chown 本站 `data/`；不动父目录与其他站点 |
| R8 | EdgeOne CDN 缓存旧响应头/旧 HTML | 中 | 低 | 资源加 `?v=3.0.0` 查询串；HTML `Cache-Control: no-cache` 短 TTL；在 DEPLOY.md 写清 CDN 刷新步骤 |
| R9 | 版本号 jump 3.0 与你的预期不符 | 低 | 低 | 假定 A-1 已标注；改 1 个常量 + 文档即可 |
| **R10** | **Redis `maxmemory=0` + `noeviction`**：新增 DB9 统计键若失控增长会挤占全部内存 | 低 | **高** | DB9 每键**强制 TTL 48h** + 分钟桶数量天然有界（≤ 2880 桶 × 每端点）+ W3 加 `maxmemory` 观测；**不擅自改服务器 redis.conf**（会影响其他站点），只在本站键上设 TTL 并给运维建议 |
| **R11** | 统一 PHP 版本（W1.8）会改变 API 的运行环境（82 → 84） | 中 | 高 | 两池扩展等价（7/7）、`php84 -l` 全绿、`tests/run.php` 在 84 下已 160/160 全绿（E18 即用 php84 跑的）⇒ 风险可控；切换前 `/tmp/php-cgi-84.sock` 存活确认；切换后立即跑端到端验收；**回滚 = 把 socket 改回 82 + `nginx -t && reload`** |
| **R12** | 代码误用 PHP 8.3/8.4 独有语法，在 82 下致命报错（若 W1.8 未落地） | 中 | 高 | **硬约束：代码限定 PHP 8.2 语法集**（`enum`/`readonly`/构造器提升/`match`/命名参数/`never`/`?->`/`??=`/first-class callable 均 ≤8.1，可用）。新增断言：`php82 -l` 与 `php84 -l` **双版本全绿** |
| **R13** | CDN（EdgeOne）缓存旧 HTML/旧响应头 | 中 | 低 | `assets/*` 与 HTML 引用加 `?v=3.0.0`；HTML 短 TTL；DEPLOY.md 写明刷新步骤 |

---

## 6. Wave 拆解与原子任务

> Wave 0 是**基线闭合**（只读 + 采样），不产生改动；后续 Wave 串行依赖。

### Wave 0 — 基线与环境闭合（只读 / 无改动）

| Task | 动作 | 出口校验 | 安全验证 | 回退 | 证据 |
|---|---|---|---|---|---|
| 0.1 | 建 `/www/wwwroot/satellite.api.authskin.xyz.staging/`，从线上 `cp -a` 一份完整副本 | `diff -r` 仅空差异 | 不涉及输入/权限（只读复制） | `rm -rf staging` | 目录存在 + 文件数一致 |
| 0.2 | 在 staging 跑 `php84 tests/run.php` 取基线 | 退出码 + 通过/失败条数 | 不涉及（本地 CLI，网络被 fixture 替代） | 无（只读） | 断言计数输出 |
| 0.3 | 采性能基线：`/v1/tle`、`/v1/tle/weather`、`/v1/transmitters?per_page=100`、`/v1/status`、`/v1/health` 各 20 次 `curl -w '%{time_total} %{size_download}'` | 输出样本 + p50/p95 | 不涉及（HTTP GET 只读） | 无 | 采样表 |
| 0.4 | 采响应头基线（动态 / 静态各 1 个） | 存档 `curl -D -` 输出 | 不涉及 | 无 | 头清单 |
| 0.5 | `mysql -usatapi -e 'SHOW GRANTS'` 确认 `CREATE` 权限 | 输出含 `CREATE` | 不暴露密码（用 `-p` 交互或 `MYSQL_PWD`） | 无 | GRANTS 输出（脱敏） |
| 0.6 | `redis-cli -n 9 keys '*'` 再次确认 DB9 空 | 输出为空 | 不涉及 | 无 | 输出为空 |

**Wave 0 出口**：基线数据归档到本文件第 4 节；`tests/run.php` 全绿（或记录既有失败项作为回归基线）。

### Wave 1 — 硬缺陷修复（X1~X4、X7）+ 运维修复（X2、X6）`[D2][D4]`

| Task | 文件 | 动作 | 出口 | 安全验证 | 回退 |
|---|---|---|---|---|---|
| 1.1 | `conf/bt-panel-rewrite.conf`、`conf/nginx.conf.example`、线上 vhost | 增加 `location = / { ... }` 或调整 `index` 顺序，使 `/` 直出 `index.html` | `curl /` → `200`，body 含 `<title>Satellite Data API` | 路径白名单（仅本站 root）；不开放 `..` 穿越 | vhost `.bak` + `nginx -t` 后 `reload`；失败回滚 .bak 并 reload |
| 1.2 | `favicon.ico`（新增）、`index.html`、`docs.html`、`404.html`、`site.webmanifest`、`robots.txt` | 生成 32×32 ICO（PNG-in-ICO，无新依赖：手写 ICO 头 + 复用 `favicon-32x32.png`），加 `<link rel="icon" href="/favicon.ico" sizes="any">` | `curl /favicon.ico` → `200`；`Content-Type: image/x-icon` | 二进制白名单（不解析用户输入） | 删除新增文件 + 回滚 HTML |
| 1.3 | `lib/Http/Response.php`、`conf/config.example.php`、vhost | 引入 `http.security_headers_owner`（`nginx`\|`app`\|`both`，默认 `nginx`）；`Response::withDefaults()` 按配置跳过 `X-Content-Type-Options`/`Referrer-Policy`；nginx 侧保留 | 动态与静态响应各恰好 1 个 `x-content-type-options`、1 个 `referrer-policy` | 无用户输入参与；头值为常量白名单 | 回滚文件；配置可一键回 `both` |
| 1.4 | `.user.ini` | 补尾换行（内容不变） | `od -c` 结尾为 `\n` | open_basedir 路径保持白名单不变 | 恢复原字节 |
| 1.5 | 服务器：`chown -R www:www /www/.../data`、`chmod 750`；`warmup.sh` 末尾补 `chown` | `/v1/health` → `"cache_dir_writable":true`；手工写测试文件成功 | 仅本站 `data/` 目录；不递归到 `/www/wwwroot` 父级 | `.bak` 原权限记录 + 可还原；`chown -R root:root` 反向命令已备 |
| 1.6 | 服务器 php82 `php.ini` / `php-cli.ini`：注释 `[swow] extension = swow.so`（保留 swoole）；修 `mbstring` 重复加载 | `php82 -v` 无 Warning；`php82 -m \| grep -E 'swoole\|swow'` 仅 1 个 | 仅改 php82；php84 不动；不涉及凭证 | 两个 ini 的 `.bak`；`systemctl reload php-fpm-82` 校验；失败回滚并 reload |
| 1.7 | 服务器：删除 webroot 内 `satellite.api.authskin.xyz_optimized.tar.gz` | 文件不存在；`curl` 该路径 → 404（nginx 已拦，双保险） | 删除前确认与本地归档 sha256 一致（**已存在本地副本**） | 本地归档可重新上传；不删其他文件 |

**Wave 1 出口**：X1~X4/X7 全部关闭，X2/X6 修复；`php84 tests/run.php` 仍全绿；响应头去重后数量断言通过。

### Wave 2 — 现代化基础设施（枚举 / 类型 / 头策略 / MySQL / Schema）`[D1][D3]`

| Task | 文件 | 动作 | 出口 |
|---|---|---|---|
| 2.1 | `lib/Support/Enum/CacheStatus.php`、`HealthStatus.php`、`MetricDriver.php`、`SourceId.php` | 新增 4 个 `enum`（`string` backed，含 `fromLegacy()` / `label()`） | `php84 -l` 全绿；`php84 -r 'require ...; var_dump(CacheStatus::from('HIT'));'` 输出正确枚举 |
| 2.2 | `lib/Config.php` + `conf/config.example.php` | 新增 `metrics.*`（driver/redis/mysql/retention）、`http.security_headers_owner`、`service.version=3.0.0`、`service.api_version=v1`；新增 `SATAPI_MYSQL_*` / `SATAPI_METRICS_*` 环境映射 | `php84 -r` 打印 `Config::get('metrics.mysql.dbname')` 正确；`grep -c SATAPI_ Config.php` 增加数符合 |
| 2.3 | `lib/Support/MySQL.php` | 惰性 PDO 单例；`available(): bool`、`pdo(): ?PDO`、`ping(): bool`；DSN 由 Config 拼装；失败返回 `null` 且记 warning（**不含 DSN/密码**） | MySQL 可达时 `available()===true`；故意填错密码时 `available()===false` 且无致命错误、日志无密码 |
| 2.4 | `lib/Support/Schema.php` + `schema/migrations/*.sql` | `ensure()` 执行幂等 DDL（第 3.4 节）；`prune(int $days)` | 建表后 `SHOW TABLES` 含 2 表；重复 `ensure()` 不报错；`php84 -r` 打印表结构 |
| 2.5 | `lib/Http/Headers.php` | 头单一来源策略对象（与 2.2 配置联动） | 单元断言：`owner=nginx` 时不含 nosniff；`owner=app` 时含 |
| 2.6 | `lib/Http/Response.php`、`Request.php`、`Errors.php`、`Logger.php`、`autoload.php` | `declare(strict_types=1)` + 参数/返回类型声明 + 属性类型 + `match`；保留行为 | `php84 -l` 全绿；`tests/run.php` 全绿（此时若无它改，应仍 163/163） |

**Wave 2 出口**：`php84 -l` 全绿；`tests/run.php` 全绿；DDL 幂等验证通过；`/v1/health` 的 `cache_dir_writable` 仍为 `true`。

### Wave 3 — Metrics 重构（驱动化 + Redis 原子计数）`[D1][D3]`

| Task | 文件 | 动作 | 出口 |
|---|---|---|---|
| 3.1 | `lib/Metrics/Driver.php`、`FileDriver.php` | 抽 interface；把现有 JSON 行为搬入 `FileDriver`（**行为等价**） | 新旧 `Metrics::summary()` 输出对同一份 `metrics.json` **逐字段一致**（脚本 diff） |
| 3.2 | `lib/Metrics/RedisDriver.php` | DB9 键设计（第 3.3 节）；`HINCRBY` 原子计数；`pipeline` 合并为 1 RTT；分钟桶 TTL 48h；ZSET 记录桶 | 连续 100 次 `record()` 后 `HGETALL` 的 `r` 字段 = 100（**并发断言**：4 进程并行各 250 次 → 合计 1000） |
| 3.3 | `lib/Metrics/CompositeDriver.php` | `file` + `redis` fan-out 写；读以 redis 权威、file 兜底 | Redis 关闭时自动降为 `FileDriver`；`/v1/health` `metrics_store` 标注 `file` |
| 3.4 | `lib/Support/Metrics.php` | 改为门面，**保留** `record/upstream/summary/flush/snapshot/setEndpoint/currentEndpoint` 全部签名 | `grep -rn 'Metrics::'` 所有调用点无需改动即通过（迁移完整性：残留 = 0） |
| 3.5 | `lib/Metrics/Rollup.php` | Redis 分钟桶 → MySQL 小时聚合（幂等 upsert）；计算 p50/p95/p99（Redis LIST 采样）；来源 `upstream` 一并聚合 | 同一小时桶重跑 2 次，MySQL 行数与数值**完全一致**（幂等断言）；p99 ≥ p95 ≥ p50 |
| 3.6 | `lib/Cli.php` | 新增 `metrics [--json] [--rollup] [--prune-days=N]`；`refresh`/`health`/`prune` 保持兼容 | `php84 index.php metrics --rollup --json` 退出码 0 且输出合法 JSON；`prune` 同时清理限流桶 + 过期日志 + 过期统计数据 |

**Wave 3 出口**：并发计数断言通过（1000/1000）；幂等断言通过；`tests/run.php` 扩展后全绿。

### Wave 4 — 数据层与端点现代化 `[D1]`

| Task | 文件 | 动作 | 出口 |
|---|---|---|---|
| 4.1 | `lib/Data/Repository.php`、`Collection.php`、`Amsat.php` | 类型化 + 枚举替换字符串状态；超时/重试策略调整（X5）：`connect_timeout` 8s→5s，`timeout` 20s→12s，`retries` 2→3（仅幂等 GET） | `php84 -l` 全绿；预热耗时与失败率对比基线（W0 采样）呈改善 |
| 4.2 | `lib/Tle/Parser.php` | 类型化 + `readonly` 值对象（`TleRecord`）；解析行为不变 | 对 `tests/fixtures/three-line.txt` 逐记录字段与旧实现**逐字段一致**（脚本 diff，非抽样） |
| 4.3 | `lib/Api/Router.php` | 路由表常量化 + 枚举；新增 `/v1/metrics` 路由；`patternToPath` 与 `index()` 保持一致 | `/v1/meta` 的 `endpoints[]` 含 `/v1/metrics`；`tests/run.php` 路由断言全绿 |
| 4.4 | `lib/Api/Endpoints/Base.php`、`Tle.php`、`Catalog.php`、`AmsatStatus.php` | 类型化；`envelopeMeta` 增加 `api_version` 枚举来源 | `php84 -l` 全绿；各端点 JSON `meta` 键集合与基线**超集**（无缺失） |
| 4.5 | `lib/Api/Endpoints/System.php` | 新增 `metrics()` 端点；`collect()` 增加 `metrics_store`；新增 `trends`（近 24h）与 `latency` | `/v1/metrics` 返回 200 且 `meta.count` 正确；MySQL 不可用时返回 200 并标注 `degraded`（**放行+标记档**） |
| 4.6 | `lib/Support/Maintenance.php`、`RateLimit.php`、`Problem.php`、`Legacy.php` | 类型化 + 枚举；`Problem::catalog()` 保持键不变 | `tests/run.php` 中 Problem/RateLimit/Maintenance 断言全绿 |
| 4.7 | `lib/Cache/Store.php`、`Lock.php`、`lib/Errors.php`、`lib/Logger.php`、`lib/Cli.php`、`lib/Config.php` | 类型化收尾 + `strict_types` | `php84 -l` 全绿；全仓 `grep -n 'array(' lib/` 残留 = 0（**迁移完整性 GATE**） |

**Wave 4 出口**：`php84 -l` 全绿；`tests/run.php` 全绿；`/v1/meta` 端点索引含新端点。

### Wave 5 — OpenAPI / 文档 / 爬虫配套 `[D1][D3][D2]`

| Task | 文件 | 动作 | 出口 |
|---|---|---|---|
| 5.1 | `openapi.yaml` | 新增 `/v1/metrics` 定义；`/v1/health`、`/v1/status`、`/v1/meta` 新键入 schema；`version` 3.0.0 | YAML 可解析（服务器用 `php84 -r 'yaml_parse_file(...)'`，`ext-yaml` 已加载）；与实现逐字段核对 |
| 5.2 | `README.md` | 更新版本、目录结构、`/v1/metrics`、配置项表（`SATAPI_MYSQL_*`、`SATAPI_METRICS_*`、`security_headers_owner`） | 配置项数量与 `Config::applyEnvironment()` 的 `$map` **逐键一致**（脚本比对） |
| 5.3 | `DEPLOY.md` | 新增：DB9/MySQL 启用步骤、DDL 与回滚 SQL、rollup cron（`*/5 * * * *`）、保留期与 prune、`/www/wwwroot/satbak` 备份与回滚步骤、CDN 刷新、`satbak` 清理 | 含可复制命令；回滚 SQL 完整；步骤经 W8 实操验证 |
| 5.4 | `robots.txt`、`sitemap.xml` | 加 `Allow: /favicon.ico`；`lastmod` 更新 | robots 语法合规（`User-agent` 段连续无空行错误） |

### Wave 6 — 前端 UI 优化 `[D2]`

| Task | 文件 | 动作 | 出口 |
|---|---|---|---|
| 6.1 | `assets/app.css` | 建立统一 design token（颜色/间距/字阶/圆角/阴影/动效时长）；修正对比度至 **WCAG AA**（正文 ≥ 4.5:1，大字 ≥ 3:1）；补 `:focus-visible` | 用服务器端脚本计算对比度并断言全部 AA 通过；`prefers-reduced-motion` 生效 |
| 6.2 | `index.html` | 统一 token 用法；补 `favicon.ico` 与 `apple-touch-icon`；语义与地标修正；移动端 `clamp()` 排版 | 真实浏览器驱动验证（见 6.5） |
| 6.3 | `docs.html` | 与落地页视觉统一（头部/页脚/按钮/间距/token）；`<meta http-equiv="X-Content-Type-Options">` 双保险；Swagger 主题对齐新 token | `/docs.html` 200；真实浏览器中 Swagger UI 正常渲染、试请求成功 |
| 6.4 | `lib/Api/Endpoints/StatusPage.php` | 与设计系统统一；新增 `trends` 图表（纯 CSS/inline SVG，无外部请求）；明确「空 / 加载 / 错误 / 无权限」四态 | 四态各有一张真实截图证据 |
| 6.5 | — | 真实驱动验证：Chromium/Edge headless 截图 + DOM 断言（`--headless --screenshot`），覆盖 `/`、`/docs.html`、`/v1/status`、移动端 375px、暗色主题 | 截图 + 断言输出（**真人功能验证 L0**） |
| 6.6 | `site.webmanifest`、`404.html` | 补 `maskable` 图标与 `apple-touch-icon`；404 页与设计系统统一 | manifest JSON 合法；404 页面渲染正确 |

### Wave 7 — 测试补强与回归 `[D1][D3][D2]`

| Task | 文件 | 动作 | 出口 |
|---|---|---|---|
| 7.1 | `tests/run.php` | 新增断言组：枚举、Metrics 三驱动、并发计数、Rollup 幂等、`security_headers_owner` 三态、对比度、新端点 | 断言总数从 163 增至 ≥ 210，失败 = 0 |
| 7.2 | `tests/request.php` | 新增 `/v1/metrics`、`/v1/status`、`/`(能否)、头去重、`If-None-Match` 304 回归 | 每条真实派发输出与期望状态码一致 |
| 7.3 | — | 覆盖完整性枚举：对称（GET/HEAD/POST/OPTIONS）、数据形态（空/单条/边界/超大/非法）、调用方（grep 全量）、分支（各 `if/else`） | 四类逐项给证据 |
| 7.4 | — | 迁移完整性 GATE：`grep -rn "array(" lib/` = 0；`grep -rn "strict_types" lib/ \| wc -l` = 文件数；无新旧混用 | 残留 = 0 |
| 7.5 | — | 回归基线对比：W0 的 `time_total` 采样 vs W7 同参数采样 | 表格 + 结论（禁止无基线声称提升） |

### Wave 8 — 部署与交付 `[D2][D4]`

| Task | 动作 | 出口 |
|---|---|---|
| 8.1 | 建 `/www/wwwroot/satbak/<相对路径>/` 并以 `cp` 生成每个待覆盖文件的 `.bak` | 备份文件数 = 待覆盖文件数；逐个 sha256 与线上一致 |
| 8.2 | 生成推送脚本 `bin/deploy-push.sh`（含 `php84 -l` 预检、`.bak`、`scp`、失败自动回滚） | 脚本 `bash -n` 通过；`--dry-run` 输出计划 |
| 8.3 | 分批推送：先 Wave 1（无依赖低风险），验证；再 Wave 2~7 | 每批后 `/v1/health` = 200、`tests/run.php` 全绿、无新 error log |
| 8.4 | 应用 DDL、装 cron（rollup `*/5`）、CDN 刷新 | `SHOW TABLES` 含 2 表；`crontab -l` 含新任务；rollup 首次执行产生行 |
| 8.5 | 端到端验收：真实 HTTPS 入口逐端点 + 首页 + docs + status + metrics | 状态码/字段/头三态断言表 |
| 8.6 | 交付：变更摘要 + 验证证据 + 已知限制 + 回滚手册 | 收尾报告（按模板） |

---

## 7. 不卡死计数（失败计数）

| 子目标 | 已确认失败次数 | 等级 | 备注 |
|---|---|---|---|
| — | 0 | L0 | 初始 |

---

## 8. 进度

| Wave | 状态 | 证据 |
|---|---|---|
| W0 | **完成** | E18~E27：测试 160/160、语法 0 失败、性能基线表、X8/X9/X10/X11/X12 定性。staging 副本建于 `/www/wwwroot/satellite.api.authskin.xyz.staging` |
| W1 | **完成** | 见 §10 Wave 1 交付证据。单元断言 160 → **270**，全绿；验收套件 0 失败；22 路径 × 4 安全头均恰好 1 份；php82 write delta = 0 |
| W2 | pending | — |
| W3 | pending | — |
| W4 | pending | — |
| W5 | pending | — |
| W6 | pending | — |
| W7 | pending | — |
| W8 | pending | — |

---

## 10. Wave 1 交付证据（已完成，线上生效）

### 10.1 关闭的缺陷

| ID | 缺陷 | 修复 | 验证证据 |
|---|---|---|---|
| X1 | `/` 返回 404 | 伪静态新增 `location = / { try_files /index.html =404; }` | `/` → 200，body 35812B，含 `hero__grid`/`id="overview"`，**静态直出 t=1.3ms** |
| X2 | 文件缓存层不可写 | `chown -R www:www data` + `warmup.sh` 末尾补 `chown` | `/v1/health` → `"cache_dir_writable":true` |
| X3 | 安全头重复 3 份且值冲突 | 三次隔离实验定位 3 个来源后归口：应用独占 4 头，nginx 各静态 location 自带，vhost 重复 3 行注释 | **22 条路径 × 4 头全部恰好 1 份**；permissions-policy 全站同值 |
| X4 | `favicon.ico` 缺失 | 新增 `favicon.ico`(16+32 PNG-in-ICO)、`apple-touch-icon.png`(180)、`icon-192/512.png`(maskable)、manifest 补全、robots 放行、三处页面挂载 | 全部 200；`Content-Type: image/x-icon`；manifest 含 `maskable`/`id` |
| X5 | 上游 weather/amateur 高频失败 | 登记为上游可用性问题（本轮未改超时策略，属 W4 范围） | `logs/app.log` 已无新增 `upstream_failed`（07:00 预热成功） |
| X6 | PHP 模块冲突告警 | 登记为服务器级配置项（`swow` vs `swoole`、`mbstring` 重复）；**未擅自改服务器 php.ini**，见 §11 待办 | `php82 -v` 仍有告警（低危，不影响本站） |
| X7 | `.user.ini` 缺尾换行 | **阻塞**：该文件被宝塔「文件防篡改」设为 `immutable`，`cp` 静默失败。已用 sha 校验捕获并回滚；处理方案见 §11 | 文件保持 59B 原样（未被破坏） |
| **X8** | `docs.html`/`openapi.yaml` 缺失全部安全头 | 各 location 显式补齐 4 头 + 新增静态兜底 location | `/docs.html` `/openapi.yaml` `/oauth2-redirect.*` `/swagger-ui-*` 均 1/1/1/1 |
| X11 | robots/manifest 未列图标 | `robots.txt` 补 8 条 Allow；manifest 补 3 个图标与 `id` | 见上 |
| X12 | README 宣称 163 条断言，实为 160 | 断言改为 270 条并修正版本断言口径 | `assertions: 270 passed: 270 failed: 0` |
| **X13** | **10 个 `*.php` 兼容外壳自 v2.2 起全部 500**（含 `.php` 外壳从未可用） | ① `Router::dispatch` 传 `{route,params}` 信封给 `run()` 导致 `$route['handler']` 未定义 → 改为取 `$match['route']`/`$match['params']`；② 新增 `shellRoute()` 从请求路径恢复路由 id | 9 个外壳全部 200（`satnogs_satellites_proxy.php` 503 = 真实维护窗口），带 `Deprecation` + `Link` 头 |
| **X14** | **`tle_weather.php` 等 5 个别名返回 meta 文档而非 TLE** | `legacyRoute()` 用 legacy 正则去匹配已注册路由失败 → 静默回退 `System::meta`。新增「等价 v1 参数化路由」回退 + `legacy_alias_without_route` 告警 | `tle_weather.php` → `DMSP 5D-3 F16` TLE；与 `/v1/tle/weather` 状态一致 |
| **X15** | **API 实际运行在 PHP 8.2（vhost 选 84）** | 伪静态删掉硬编码 `php-cgi-82.sock`，改 `rewrite ^ /index.php last;` | 50 次 `/v1/meta`：**php82 write delta = 0**，php84 = 163840 |

### 10.2 性能回归（同一台机器，改后 15 次采样 vs W0 基线 20 次）

| 端点 | W0 p50 | W1 p50 | 结论 |
|---|---|---|---|
| `/` | 无（404） | **0.0013s** | 从不可用 → 静态直出 |
| `/v1/meta` | 0.0644 | 0.0355 | 改善（走 php84 + opcache 热态） |
| `/v1/tle/weather` | 0.0622 | 0.0354 | 改善 |
| `/v1/health` | 0.1354 | 0.1103 | 改善 |
| `/v1/status` | 0.1432 | 0.1082 | 改善 |

> B4 门禁：有基线、有对比，未声称"无基线提升"。

### 10.3 新增/修改文件（本地 git `4bf89b7`）

20 个文件变更（+1333/−54），新增：`favicon.ico`、`apple-touch-icon.png`、`icon-192.png`、`icon-512.png`、`bin/build-favicon.js`、`bin/build-icons.js`、`bin/README.md`、`_plan.md`。

### 10.4 本轮踩到的**方法论错误**（已内化，见 §12）

1. 判定 HTTP 归属的 I/O 计数用了 `join` + `pgrep`，**pgrep 顺序不稳定** → 输出 `82=1313050543`、php84 为负的荒谬值。
2. PowerShell `Get-Content -Raw` 按 GBK 解码 UTF-8，导致我"以为"文件乱码 → 实际无损（U+FFFD 计数为 0）。
3. `ssh ... "cmd"` 的 stdout 被服务器 PHP shim 的 stderr 告警污染，我用"第一行"当 hash → 误判并回滚了一次正确上传。
4. 多进程管道到 ssh stdin 会吞引号 → 中文与引号被破坏，改用 base64 落盘再执行。

---

## 11. 待办与阻塞（Wave 2+ 与需你决策项）

| # | 项目 | 状态 | 说明 |
|---|---|---|---|
| B1 | `.user.ini` 尾换行 | **阻塞** | 文件被 `/www/server/tamper`（`tampercore.ko`，宝塔文件防篡改）设为 immutable，`cp` **静默失败**。修复需 `chattr -i` → 写入 → 恢复属性。**这是服务器级安全策略，需你确认**（或由你在面板临时关闭防篡改）。低危：不改也能正常运行 |
| B2 | `swow` vs `swoole` 模块冲突 | 待办（服务器级） | php82 的 `php.ini` L2002/L2009 同时启用两者。注释其中一个可消除 `php -v` 与预热日志噪声。**会影响其他站点，需你确认** |
| B3 | `mbstring` 重复加载 | 待办（服务器级） | php82/php84 的 `php.ini` 已静态加载又有一行 `extension=...mbstring.so`。同上，需你确认 |
| B4 | 上游 weather/amateur 失败率 | 待办（W4） | `logs/warmup.log` 显示两源各 13.2s 超时。W4 计划调 `connect_timeout` 8→5s、`timeout` 20→12s、`retries` 2→3（仅幂等 GET） |
| B5 | MySQL `satapi` 用户 DDL | 已验证可行 | `GRANT ALL PRIVILEGES ON satapi.*`，W2 可直接 `CREATE TABLE` |
| B6 | `redis maxmemory=0` + `noeviction` | 待办（登记） | DB9 统计键全部带 48h TTL + 桶数有界，故本站风险低；**不建议擅改服务器 redis.conf**（会影响其他站点） |
| B7 | README「错误一律 problem+json」与实现不一致 | 待办（W5） | `Tle`/`Catalog` 两条文本优先路由族按设计把 400 也渲染为可 grep 的纯文本（`Problem::setTextPreferred`）。W5.2 修 README 措辞 |
| B8 | 宝塔 vhost `error_page 404 /404.html` 替换应用 problem+json | **已知限制（文档化）** | 未知路径的 404 返回 HTML 错误页；路由命中的错误（400/405/429/503）不受影响。已在 `conf/bt-panel-rewrite.conf` 第 7 节写明绕过方法 |
| B9 | 宝塔「网站->设置->安全」可能重写 vhost 并恢复重复头 | **已知风险（文档化）** | 已在 vhost 待注释行留 `# [v3.0] single-owner:` 标记；W5.3 DEPLOY.md 写明"若面板重写，需重新注释这 3 行" |
| B10 | CDN（EdgeOne）头注入 | 登记 | 公网响应有 `eo-cache-status`、`eo-log-uuid` 与 CDN 侧 HSTS；与源站头不冲突（CDN 用不同 max-age 覆盖）。W8 做 CDN 刷新 |

---

## 12. Wave 1 复盘的 4 条判据（供后续 Wave 沿用）

1. **判定方法自身要先被验证**：任何"归属/因果"测量，先证明测量能区分两态；`pgrep` 顺序、`curl` 参数、grep 口径都属于测量装置。
2. **stderr 不是数据**：跨 SSH 解析远程输出前必须过滤非协议行（本环境每次都打 `mbstring` 告警），否则"第一行"可能是警告。
3. **多进程管道会改字节**：PowerShell → ssh stdin 一律走 base64 落盘；含中文/引号的脚本绝不直接管道。
4. **nginx `add_header` 的继承是"整组"语义**：子层一旦出现任何 `add_header`，父层全部丢弃；server 级头对 fastcgi 响应会被应用两次。三条结论均已实测记录在 `conf/bt-panel-rewrite.conf` 第 6 节，避免后人重复踩坑。

---

## 9. 待用户确认（唯一开放项）

无阻塞项。第 0 节的 5 条**假定项**按中级口径执行并可随时纠正。
