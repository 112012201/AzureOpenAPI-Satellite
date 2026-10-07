# AzureOpenAPI for Satellite — 操作手册

**服务**：AzureOpenAPI for Satellite · `3.1.2789-beta`
**站点**：https://satellite.api.authskin.xyz
**主机**：`154.64.249.233` · 宝塔面板 · nginx 1.30.4 · PHP 8.4（`php82` 保留用于兼容校验）
**应用根**：`/www/wwwroot/satellite.api.authskin.xyz`
**文档版本**：2026-10-07 · 适用代码版本 `4ab87a2` 及之后

> 本手册只写**可执行的判据与命令**。凡是"应该"而没有实测证据的说法，都会明确标注为
> **未验证**。每一条踩过的坑都保留了报错原文，因为下次遇到时会先搜原文。

---

## 1. 一分钟健康检查

```bash
# 1) 站点与 API 是否活着
curl -s -o /dev/null -w '%{http_code}\n' https://satellite.api.authskin.xyz/v1/health

# 2) 数据源是否都拿到了数据（重点看 weather / amateur）
for s in weather amateur amsat satnogs; do
  printf '%-9s %s 条\n' "$s" \
    "$(curl -s -H 'Accept: text/plain' https://satellite.api.authskin.xyz/v1/tle/$s | grep -c '^1 ')"
done

# 3) 容器与端口
docker inspect --format '{{.State.Status}} health={{.State.Health.Status}}' cap
ss -ltnp | grep -E ':3000'

# 4) 最近是否在刷错
tail -20 /www/wwwroot/satellite.api.authskin.xyz/logs/app.log
```

**健康基线**（2026-10-07 实测）：`weather 72` · `amateur 95` · `amsat 97` · `satnogs 1497`；
容器 `running health=healthy`；`app.log` 无新增 `php_error`。

---

## 2. 架构与数据流

```
访客 ──► EdgeOne CDN（缓存 + 回源）
           │
           ├─ 静态/页面 ──► nginx ──► index.php（前端控制器）
           │                            ├─ HomePage      首页（PHP 渲染，三语）
           │                            ├─ Docs          /docs（注入 Cap 覆盖点）
           │                            ├─ Legal         /legal /privacy /terms
           │                            └─ Router         /v1/* 与旧路径
           │
           └─ /cap/*（不缓存）──► nginx 反代 ──► Cap 容器（host 网络，127.0.0.1:3000）
                                                   └─ Redis db 12（会话/挑战/密钥）

数据缓存：Redis db 8（`satapi:` 前缀）+ 文件缓存（data/cache）
上游：CelesTrak（主）→ SpaceTrack（备）· AMSAT · SatNOGS DB
```

### 关键路径与负责文件

| 路径 | 处理者 | 缓存 |
|---|---|---|
| `/` `/index` | `lib/HomePage.php` → `partials/page-home.php` | EdgeOne 1h |
| `/docs` | `lib/Docs.php`（正文仍是 `docs.html`） | EdgeOne 1h |
| `/legal` `/privacy` `/terms` | `lib/Legal.php` | EdgeOne 1h |
| `/v1/tle*` `/tle*` `/v1/transmitters` `/v1/satellites` | `lib/Api/Endpoints/Tle.php` `Catalog.php` | EdgeOne 3h |
| `/v1/health*` `/v1/status` `/v1/maintenance` `/v1/consent/*` `/cap/*` | 各自端点 / Cap 容器 | **不缓存** |
| `/404.php` | `404.php` | 见 §9 已知问题 |

### 数据源主备策略（**实测决定，不是假设**）

| 数据集 | 主源 | 备源 | 依据 |
|---|---|---|---|
| weather / amateur | CelesTrak | SpaceTrack | 两者 TLE epoch **实测等同**（weather 71/72 逐字节相同，amateur 94/95 逐秒相同）。真差别是可达性：CelesTrak 有 IP 限流，SpaceTrack 需凭据但无限流。**不做并行双拉**——同源数据双拉只增加限流风险。 |
| amsat / satnogs | 各自单一源 | — | 无等价备源 |

**SpaceTrack 两条硬约束**（写代码时必须遵守）：
1. **登录限流远严于查询**。连续多次登录后，此前成功的查询会开始返回 401。因此会话必须缓存复用（`data/state/spacetrack.session`），只在过期或显式 401 时重新登录，并有跨进程最小登录间隔（默认 300s）。
2. **无 GROUP 预设**（`class/gp/GROUP/weather` → 500）。必须按 NORAD ID 列表查询，名单在 `conf/tle-groups.php`（由 `bin/tle-groups-from-cache.php` 从最近一次成功的 CelesTrak 快照生成）。

**CelesTrak 限流的原文与含义**：

> `Access from IP address 154.64.249.233 was blocked for celestrak.org/NORAD/elements/gp.php?GROUP=weather&FORMAT=tle.`
> `We have detected excessive downloads for files in the /NORAD/elements directory and access has been temporarily blocked.`
> `Access will be automatically restored once the excessive downloads have ceased for 2 hours.`

要点：**它自愈的条件是"2 小时内不再请求"**。所以被限流时**每一次重试都在重置计时器**。
处理方式是 `source_cooldown`（403/429 → 默认 7200s；连接超时/DNS 失败 → 900s），
期间自动走备源。warmup 已从**每小时**降到**每 6 小时**（`0 */6 * * *`）。

---

## 3. 文件与配置地图

```
/www/wwwroot/satellite.api.authskin.xyz/
├── index.php              前端控制器（唯一入口：API + 页面 + CLI）
├── 404.php                错误页（见 §9）
├── bootstrap.php          Config/Logger/autoload 初始化
├── lib/
│   ├── Config.php         配置单点（含环境变量映射表）
│   ├── HomePage.php · Docs.php · Legal.php        页面渲染
│   ├── Api/Router.php     路由表 + 同意门禁
│   ├── Api/Endpoints/     Tle · Catalog · AmsatStatus · System · Consent · Base
│   ├── Data/Repository.php  取数编排（主备、冷却、缩水检测）
│   ├── Data/SourceMerge.php 主备策略 / 冷却 / 组名单 / 互补
│   ├── Http/SpacetrackClient.php  SpaceTrack 会话与查询
│   ├── Support/           Consent · Pow · CapBootstrap · I18n · Announcement · Problem
│   └── ...
├── partials/shell.php     页面外壳（公告条 + 同意弹窗 + PoW 覆盖层 + 引导脚本）
├── assets/
│   ├── tokens.css         MD3 三级设计令牌（OKLCH）
│   ├── app.css            布局，消费令牌
│   ├── md3.css            组件层（必须最后加载）
│   ├── consent.js         公告/同意/PoW 客户端逻辑
│   └── vendor/cap/        本地化的 Cap widget 与解题资源（零外联）
├── conf/
│   ├── config.php         **部署配置（0600 www:www，含凭据，不在仓库）**
│   ├── tle-groups.php     组名单（NORAD ID 列表）
│   ├── i18n.php           三语词条
│   ├── cap.env            **Cap 容器环境（0600，含 ADMIN_KEY）**
│   └── cap-compose.yml    Cap 容器编排
├── data/                  运行态（缓存、冷却、SpaceTrack 会话）
├── logs/                  app.log · warmup.log
├── tests/                 run.php（单元）· request.php（单请求派发器）
└── bin/                   warmup.sh · restore-dataset.php · probe-* · diag-*
```

### 配置优先级

```
lib/Config.php 内置默认  <  conf/config.php  <  SATAPI_* 环境变量
```

**环境变量的两条规则**（`Config::coerceEnvValue()`）：
- 空字符串 = **未设置**（不覆盖）。这样"变量没导出"和"导出为空"不会产生不同结果。
- **单个 `-` = 显式清空**。没有它就无法用环境变量撤销配置文件里的值（例如清空 `SATAPI_ANNOUNCEMENT_ID` 以让公告按内容派生 id）。例：`SATAPI_SPACETRACK_PASSWORD=-` 清空密码。

---

## 4. 凭据与密钥清单（**全部不入仓库**）

| 密钥 | 位置 | 权限 | 轮换方式 |
|---|---|---|---|
| SpaceTrack 账号/密码 | `conf/config.php` → `sources.spacetrack` | `0600 www:www` | 改文件 **或** 导出 `SATAPI_SPACETRACK_IDENTITY` / `SATAPI_SPACETRACK_PASSWORD`（环境变量优先） |
| Cap `ADMIN_KEY` | `conf/cap.env` | `0600 www:www` | 改文件 → `docker compose -f conf/cap-compose.yml up -d` 重建 |
| Cap site key / secret | `conf/config.php` → `pow.site_key` / `pow.secret_key`；备份在 `/www/wwwroot/satbak/cap/sitekeys.txt`（0600） | 同上 | 见 §7.3 |
| Cap bot token | `/www/wwwroot/satbak/cap/sitekeys.txt` | 0600 | 见 §7.3 |
| 腾讯云 EdgeOne API 密钥 | **不落盘** | — | 见 §8 |

**nginx 封禁**：`/conf/`、`/data/`、`/lib/`、`/logs/`、`/bin/`、`/tests/`、`/partials/`、`/schema/`
一律 404；`partials/shell.php` 另有**自身防直连守卫**（不依赖 Web 服务器配置）。
**验证命令**：

```bash
for p in /conf/config.php /conf/cap.env /partials/shell.php /lib/Config.php /data/state; do
  printf '%-24s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' https://satellite.api.authskin.xyz$p)"
done
# 期望全部 404
```

---

## 5. 部署流程（**禁止在服务器上直接改文件**）

### 5.1 标准推送（本地 → 服务器）

```powershell
# 本地 D:\DSH
Set-ExecutionPolicy -Scope Process -ExecutionPolicy Bypass -Force
. D:\DSH\work\push-deliverables.ps1
Push-Deliverables -Paths 'lib/HomePage.php','assets/md3.css'
```

推送器做的事：**白名单校验**（未列入的路径直接拒绝）→ `scp` 到临时区 →
`cp` 备份到 `/www/wwwroot/satbak/<相对路径>.bak` → 覆盖 → `sha256` 校验 →
**不匹配则自动回滚**。`.sh` 保持 `0755`，其余 `0644`。

> `.sh` 的可执行位：曾因 `install -m 0644` 被静默剥掉，导致 cron 报
> `Permission denied`（exit 126）而无其他症状。推送器已按扩展名分别设权限。

### 5.2 nginx 配置变更

```bash
# 服务器上
RW=/www/server/panel/vhost/rewrite/satellite.authskin.xyz.conf
cp -p "$RW" /www/wwwroot/satbak/_nginx/satellite.authskin.xyz.conf.$(date +%s).bak
install -m 0644 /www/wwwroot/satellite.api.authskin.xyz/conf/bt-panel-rewrite.conf "$RW"
nginx -t && nginx -s reload
```

**`nginx -t` 通过 ≠ 配置生效**。本手册记录了三类"合法但无效"的情况，见 §9。

### 5.3 改动后的必做校验

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
# 双版本语法（必须两个都过）
find . -name '*.php' -not -path './tests/tmp/*' -not -path './data/*' \
  -exec /www/server/php/82/bin/php -l {} \; 2>&1 | grep -v 'No syntax' | grep -v mbstring
find . -name '*.php' -not -path './tests/tmp/*' -not -path './data/*' \
  -exec /www/server/php/84/bin/php -l {} \; 2>&1 | grep -v 'No syntax' | grep -v mbstring

# 单元测试
rm -rf tests/tmp && mkdir -p tests/tmp/cache/locks tests/tmp/ratelimit
chown -R www:www tests/tmp
SATAPI_REDIS_ENABLED=0 /www/server/php/84/bin/php tests/run.php 2>&1 | tail -6

# 全站端点
for p in / /docs /legal /privacy /terms /v1/meta /v1/health /v1/tle /v1/tle/weather \
         /v1/transmitters /v1/satellites /v1/amsat/status /v1/consent/status /openapi.yaml; do
  printf '%-24s %s\n' "$p" "$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: satellite.api.authskin.xyz' http://127.0.0.1$p)"
done
```

### 5.4 回滚

```bash
# 单文件
cp -p /www/wwwroot/satbak/<相对路径>.bak /www/wwwroot/satellite.api.authskin.xyz/<相对路径>
# nginx
cp -p /www/wwwroot/satbak/_nginx/<最新备份> /www/server/panel/vhost/rewrite/satellite.authskin.xyz.conf
nginx -t && nginx -s reload
```

### 5.5 改动 UI 或页面后的必做步骤

```bash
cd /www/wwwroot/satellite.api.authskin.xyz

# 1) MD3 令牌或页面模板改动后：确认资源版本串已变化（否则用户拿到旧文件）
curl -s -H 'Host: satellite.api.authskin.xyz' http://127.0.0.1/ | grep -oE '/assets/[a-z]+\.(css|js)\?v=[0-9a-f]+'

# 2) 改了公告条 / 同意弹窗 / 三语词条 后：**必须重新生成错误页**
#    错误页是构建期生成的静态文件，内嵌了上述内容；不重新生成就会与站内不一致。
/www/server/php/84/bin/php bin/build-error-page.php
# 检查是否已过期（CI 可用；过期返回 1）
/www/server/php/84/bin/php bin/build-error-page.php --check

# 3) 兜底路由与错误页是否仍生效
for p in /a/b/c /v1/nope /nonexistent-file.txt; do
  printf '%-22s %s  %s 字节\n' "$p" \
    "$(curl -s -o /tmp/e.html -w '%{http_code}' -H 'Host: satellite.api.authskin.xyz' http://127.0.0.1$p)" \
    "$(stat -c%s /tmp/e.html)"
done
# 期望：全部 404，且 /tmp/e.html 约 6378 字节（含 error-page__code 标记）

# 4) 兜底 location 的顺序（放错地方会吞掉 /cap/vendor/ 等资源）
grep -n 'location ~ \^/(?!cap/vendor' conf/bt-panel-rewrite.conf
# 该行必须是文件里**最后一条**正则 location
```

---

## 6. 数据层运维

### 6.1 手动预热

```bash
/www/wwwroot/satellite.api.authskin.xyz/bin/warmup.sh --quiet      # 全部目标
/www/wwwroot/satellite.api.authskin.xyz/bin/warmup.sh --target=tle # 仅 TLE
/www/wwwroot/satellite.api.authskin.xyz/bin/warmup.sh --dry-run    # 只看会做什么
```

cron：`0 */6 * * *`（已从每小时降频，原因见 §2 的 CelesTrak 限流说明）。

> **预热走的是与线上相同的取数路径**（`Repository::source()`），因此它也遵守主备与冷却。
> 早期版本自己直连 URL，导致 CelesTrak 被限流时 cron 报 `FAIL 0 records`
> 而站点实际在正常提供数据——**运维信号说了反话**，这是最危险的一类缺陷。

### 6.2 缓存与冷却状态

```bash
redis-cli -n 8 --scan --pattern 'satapi:*' | head         # 数据缓存
redis-cli -n 8 GET satapi:dataset:tle:weather | head -c 80
redis-cli -n 12 --scan | head                              # Cap 用库
cat /www/wwwroot/satellite.api.authskin.xyz/data/cooldown_weather   # 主源冷却截止时间戳
```

**清冷却**（确认上游已恢复后再做）：

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
/www/server/php/84/bin/php -r 'require "bootstrap.php"; \SatApi\Data\SourceMerge::clearCooldown("weather");'
```

### 6.3 数据集恢复工具

```bash
# 默认 dry-run，只打印将要做什么
/www/server/php/84/bin/php bin/restore-dataset.php
# 确认后写入
/www/server/php/84/bin/php bin/restore-dataset.php --apply
```

守卫：记录数 < 20 拒绝、任何 TLE 行长度 ≠ 69 拒绝、来源不可解析拒绝。

> **教训（ERR-001）**：曾为验证"缓存里没有旧品牌名"而执行 `redis-cli del`，删掉了**唯一一份**数据，
> 同期上游正好被限流，端点 503 约 2 分钟，后从文件缓存恢复。
> **动生产状态前必须回答三个问题**：①这是唯一副本吗？②上游现在可用吗？③多久、通过什么恢复？

---

## 7. 工作量证明（Cap）

### 7.1 架构与供应商审计（部署前已做）

| 项 | 值 |
|---|---|
| 镜像 | `tiago2/cap@sha256:8a83e06c4fde9ada7cfe15528c79885bbdfdf886863fd97c02666e40014cfa9c` |
| 版本 | 3.1.14（2026-09-24） |
| 许可 | **Apache-2.0**（GitHub 判 `NOASSERTION`，因其 `package.json` 未声明 license 字段；`LICENSE` 文件是 Apache 2.0 全文） |
| 运行身份 | `uid=1000(bun)`，**非 root** |
| 遥测 | 无。镜像内 `sentry/posthog/analytics/telemetry` 仅命中 `docs/` 示例与无关的 `chance` 包 |
| 基础镜像 | `oven/bun:1.4.0-slim` |

**更新镜像时必须重做**：固定 digest、确认非 root、复查无遥测、`docker export` 后检查文件清单。

### 7.2 容器运维

```bash
docker inspect --format '{{.State.Health.Status}}' cap
docker logs --tail 50 cap
docker compose -f /www/wwwroot/satellite.api.authskin.xyz/conf/cap-compose.yml up -d     # 重建
docker compose -f /www/wwwroot/satellite.api.authskin.xyz/conf/cap-compose.yml down      # 停
```

**为什么用 `network_mode: host`**：宿主机 Redis 是 `bind 127.0.0.1` + `protected-mode yes`，
容器经 docker0 网关（172.17.0.1）**连不上**。三条出路里——
(a) 放宽 Redis 的 bind（否决，动了全站缓存的安全边界）、
(b) 再跑一个 valkey 容器（否决，多一份要备份要监控的状态）、
(c) host 网络（**采纳**）。代价是 3000 端口落在所有网卡上，因此有 iptables 规则：

```bash
iptables -L DOCKER-USER -n | grep 3000
# DROP tcp dpt:3000 !127.0.0.1
# 验证（从外部）：154.64.249.233:3000 应不可达，443/80 应可达
```

> 端口收敛**必须从外部验证**。本机访问自己的公网 IP 走 loopback，不经过 DOCKER-USER 链，
> 会看到"可达"的假象。

### 7.3 Cap 管理接口（会话鉴权很绕，原文记录）

| 操作 | 请求 |
|---|---|
| 登录 | `POST /auth/login` body `{"admin_key":"…"}` → 返回 `session_token` + `hashed_token` |
| 会话凭据 | `Authorization: Bearer <base64(JSON{"token":…,"hash":…})>` ← **不是直接放 token**，放错了会报 `Unauthorized. Malformed session token` |
| 列出站点密钥 | `GET /server/keys` |
| 创建站点密钥 | `POST /server/keys` body `{"corsOrigins":["https://satellite.api.authskin.xyz"]}` → 返回 `siteKey` + `secretKey`（**secret 只此一次可见**） |
| 创建长期 bot token | `POST /server/settings/apikeys` body `{"name":"…","siteKeys":["…"]}` → 返回 `apiKey`，之后用 `Authorization: Bot <id>_<token>` |
| 轮换 secret | `POST /server/keys/{siteKey}/rotate-secret` |

Redis db 12 结构：`key:<siteKey>`（hash：`secretHash`/`jwtSecret`/`config`/`name`/`created`）、
`apikeys` 集合、`session:*`、`token:*`。

### 7.4 前端本地化（**零第三方外联**，这是有代价的）

Cap 的 widget 内部**硬编码 `cdn.jsdelivr.net`** 用于 WASM 解题器与 pako 回退，Cap 服务端的
`CACHE_HOST` 默认也是同一个 CDN。所以"自托管"若不额外处理，访客浏览器仍会访问第三方。

两道独立机制（**都要保留，缺一不可**）：

1. `lib/Support/CapBootstrap.php` 在 widget 加载**之前**注入：
   - `window.CAP_CUSTOM_WASM_URL` → `/cap/vendor/cap_wasm_bg.wasm`
   - `window.CAP_CUSTOM_HASHWX_URL` → `/cap/vendor/hashwx.wasm`
   - `window.CAP_PAKO_URL` → `/cap/vendor/pako_inflate.min.js`
   widget 对前两者是 `fetch(url).then(arrayBuffer()).then(WebAssembly.compile)`，
   **必须是完整的 `.wasm` 文件 URL，不能是目录**（给目录 → 请求目录本身 → 404，且只记一条 debug 日志）。
2. nginx 对 `/cap/widget.js` 做 `sub_filter`，把 CDN 前缀改写成 `/cap/vendor/`。

> **`sub_filter` 默认只作用于 `text/html`**，而这里响应是 `application/javascript`。
> 不显式加 `sub_filter_types application/javascript` 会**完全静默失效**
> （实测响应字节与磁盘文件逐字节相同）。

**Cap 升级核对清单**：
```bash
# 1) widget 内 CDN 前缀是否还在（sub_filter 的目标）
grep -c 'cdn\.jsdelivr\.net' assets/vendor/cap/cap.min.js
# 2) 三个 CAP_CUSTOM_* 变量名是否仍被 widget 读取
grep -o 'CAP_CUSTOM_[A-Z_]*' assets/vendor/cap/cap.min.js | sort -u
# 3) 改写是否生效（必须为 0）
curl -s https://satellite.api.authskin.xyz/cap/widget.js | grep -c 'cdn\.jsdelivr\.net'
# 4) 真实浏览器跑一遍：不应有任何非本站请求
```

### 7.5 PoW 失败处理：**fail-open**

Cap 不可用时**放行**并在响应里标 `pow.state=unavailable`，不锁死访客。
理由：本站威胁模型是"日常抓取"而非定向滥用，**短暂失去人机验证比全站不可用更好**。
如需改为 fail-closed，设 `pow.fail_open = false`（不推荐）。

**实测**：停掉容器后 `POST /v1/consent/verify-pow` 仍 200、`/` 与 `/docs` 仍 200。

### 7.6 作用范围与已知限制

PoW 覆盖 `/` 与 `/docs`。**`/v1/*` 与静态资源不验证**——API 客户端无法解 PoW，且会让 CDN 缓存失效。

> **限制（必须知道）**：门禁在**客户端**。`/cap/*` 的挑战由浏览器 JS 完成，
> 因此**不执行 JavaScript 的爬虫可以绕过 HTML 门禁**（但仍受 API 层限流与同意参数约束）。
> 服务端强制的代价是让 `/` 由 PHP 检查 Cookie 后再交付——现已是 PHP 渲染，
> 但真正的服务端校验需要在 HTML 输出前判定会话。

---

## 8. EdgeOne CDN

**站点**：`zone-3olyyikvatea`（`authskin.xyz`） · **域名**：`satellite.api.authskin.xyz`
（DomainId `edge-3s6evegkqk67`，CNAME `satellite.api.authskin.xyz.eo.dnse1.com`，IPv6 on）
**源站**：`154.64.249.233`，HostHeader 同名，`OriginProtocol=FOLLOW`
**证书**：EdgeOne 免费证书，**注意到期日**（本次核查时为 2026-12-16）

该 zone 下另有 5 条规则属于**别的域名**（`docs.authskin.xyz`、`stat.authskin.xyz`、
`package.authskin.xyz`、`qsl/qrzdb.bi3cff.authskin.xyz`）。**改规则时只动
`API 加速-satellite.api.authskin.xyz`（`rule-3s6evdnzwdr3`）这一条。**

### 8.1 现行配置（三个顶层分支）

因为平台限制"每个 `Branches` 数组最多 1 项"，路径分流全部靠**同层并列的 `SubRules`**：

```
顶层 Condition = host(satellite.api.authskin.xyz)
顶层 Actions   = NoCache        ← 默认不缓存，未列举的路径都靠它兜底
 ├ IF1: API 路径           → CacheKey(排除 consent) + 3h 缓存
 ├ IF2: 页面与静态路径      → 1h 缓存
 └ IF3: host              → SmartRouting + UpstreamHTTP2 + StatusCodeCache
```

覆盖路径：
- **3h**：`/v1/tle/*` `/v1/tle` `/v1/transmitters` `/v1/satellites` `/tle` `/tle/*`
- **1h**：`/` `/docs` `/docs.html` `/legal` `/privacy` `/terms` `/v1/meta` `/v1/amsat/status`
  `/openapi.yaml` `/sitemap.xml` `/robots.txt` `/favicon.ico` `/site.webmanifest`
  `/assets/*` `/icon-*.png` `/favicon-*.png`
- **不缓存**：`/v1/health*` `/v1/status` `/v1/maintenance` `/v1/consent/*` `/cap/*`（以及一切未列举路径）

### 8.2 平台硬约束（**每条都由 API 明确报错，改配置前必读**）

| # | 约束 | 报错原文（节选） |
|---|---|---|
| 1 | **`RulePriority` 只读**：`Describe` 会返回，`Modify` 带上即失败 | `FailedOperation.InvalidParam`（无详情）；去掉即成功 |
| 2 | **每个 `Branches` 最多 1 项** | `must contain at maximum 1 item` |
| 3 | **内嵌 IF 最多 3 条**（免费版） | `内嵌 IF 规则数已超出当前套餐最大配额 3 个` |
| 4 | `FullURLCache` 与 `QueryString.Switch` 不能同时 on | `can not be "ON" at the same time` |
| 5 | **`StatusCodeCache` 不接受 451** | 允许集 `[400 401 403 404 405 407 414 500 501 502 503 504 509 514]` |
| 6 | `true` 不是合法匹配类型 | `ConfigConditionUnknownTarget: Invalid Matching type` |
| 7 | 正则 location 里 `proxy_pass` 不能带 URI | `proxy_pass cannot have URI part in location given by regular expression` |

**第 5 条的正确解法**：451（"未同意"的临时状态）的防缓存**不能靠 CDN 状态码列表**，
而由**源站**承担——`Problem::send` 对 451 发 `Cache-Control: no-store`，
EdgeOne 对未列入该表的状态码遵循源站头。所以列表里放 403（同类"拒绝访问"，TTL=0）。

### 8.3 改规则的完整流程

```python
# 1) 先备份当前规则（只读）
DescribeL7AccRules(ZoneId, Filters=[{"Name":"rule-id","Values":["rule-3s6evdnzwdr3"]}])
#    存成 eo-rule-backup.json —— 这是回滚的唯一依据

# 2) 构造新结构（注意：不要带 RulePriority；Branches 只 1 项；IF ≤ 3）

# 3) 写入（覆盖式）
ModifyL7AccRule(ZoneId=..., Rule={...})

# 4) 回读逐项校验（必做）
DescribeL7AccRules(...)   # 逐条比对：RuleId/RuleName/Status 未变、目标路径已在、其他规则未动
```

**校验失败时 API 是零写入**（实测多次），所以试错不会污染生产；但仍应每次回读确认。

### 8.4 刷缓存

```python
CreatePurgeTask(ZoneId=..., Type="purge_url", Method="invalidate",
                Targets=["https://satellite.api.authskin.xyz/"])
# 返回 JobId；注意 DescribePurgeTasks 不接受 JobIds 参数（UnknownParameter）
```

**改了资源文件不必刷缓存**：`assets/*.js|css` 已带**内容哈希查询串**（`?v=abcdef12`），
URL 变化即自动绕过旧缓存。（`app.css`/`app.js`/`consent.js`/`tokens.css`/`md3.css`）

### 8.5 同意强制 vs CDN 缓存（**互斥，必须二选一**）

```
consent.required = true   → 未带参数的请求收 451；每个响应随 consent 参数变化
                            ⇒ API 实际上不可缓存（命中率崩、回源量升）
consent.required = false  → 宽松模式：不阻断，只在响应里标注
                            ⇒ API 可全量缓存（当前采用）
```

**当前为宽松模式**：全端点 200，但仍返回
`X-Consent-Required: privacy,terms;version=1;state=missing` 与 `meta.consent` 作为标注。
如需强制：`conf/config.php` 里 `consent.required => true`（或 `SATAPI_CONSENT_REQUIRED=1`）。

**为保证缓存命中率**，同意状态取自**查询参数**（`?consent=…`）而非 Cookie，
且缓存键**排除** `consent`/`consent_version`，`Cookie` 也不参与缓存键。

---

## 9. 已知问题与限制（**未修复项一律诚实列出**）

| # | 问题 | 影响 | 状态 |
|---|---|---|---|
| 1 | ~~nginx `error_page 404` 未生效~~ | ~~未知路径返回无安全头的内置页~~ | **已修复**。四种写法实测均无效，且 nginx 内置错误页**不带任何安全头**；改为**兜底正则 location** 提供 `error.html`（`bin/build-error-page.php` 构建期生成，三语内嵌）。实测 `/a/b/c`、`/v1/nope`、`/docs/nope`、`/nonexistent-file.txt` 全部 404 + 6378 字节自定义页 + 4 个安全头。**改动后必须重新生成**：见 §5.5 |
| 2 | PoW 门禁在客户端 | 不执行 JS 的爬虫可绕过 HTML 门禁 | 设计取舍（见 §7.6） |
| 3 | 面板「网站→设置→安全」重新保存会恢复重复安全头 | 安全头出现多份 | 需重新注释 vhost 里的 3 行（见 §10） |
| 4 | `swagger-ui-bundle.js` 内嵌旧服务名 | `vendor` 文件，未改 | 迁移 GATE 已将其列为**明示豁免**（不是静默跳过） |
| 5 | `.user.ini` 不可写 | 宝塔文件防篡改（`chattr +i`） | 需 `chattr -i` → 写 → `chattr +i`；**需运维批准** |
| 6 | CelesTrak 仍在限流 | weather/amateur 走 SpaceTrack 备源 | 自愈中（2 小时静默）。**不要在此期间反复试探** |

### 曾长期隐藏、现已修掉的两类缺陷（值得记住，因为都不会报错）

**测试套件静默截断。** 缺一个 `use SatApi\Support\Pow;` 导致 `Error: Class "Pow" not found`；
该异常被本站错误处理器转成 **500 JSON 文档**输出，于是**既没有报错信息、也没有汇总行**，
而 **exit code 仍是 0** —— 一个含 440 条断言的套件看起来"通过"，实际一条都没统计。
同类问题共三处缺 import。现在 `tests/run.php` 报 **632/632**，退出码可信。

> **排查手法**（值得复用于任何"脚本静默结束"）：用一个 wrapper 把入口包在 try/catch 里并注册
> `register_shutdown_function`，异常就会现形：
> ```php
> try { require '/path/tests/run.php'; }
> catch (\Throwable $t) { fwrite(STDERR, get_class($t).': '.$t->getMessage().' @ '.$t->getFile().':'.$t->getLine()); }
> ```

**静态资源改动无法触达已访问用户。** `assets/*.js|css` 带 `immutable, max-age=7d`，
一旦改版，浏览器会**继续执行旧文件一整周**。这类问题极难察觉：服务器与 CDN 都在发新版，
只有浏览器在跑旧代码。现在所有资源引用都带**内容哈希查询串**（`?v=<sha256 前 8 位>`），
由 `HomePage::assetQueryFor()` 计算；改动即换 URL，无需刷 CDN。


---

## 10. 排障速查

### 症状 → 先查什么

| 症状 | 第一步 | 常见原因 |
|---|---|---|
| `/v1/tle/weather` 503 | `tail logs/app.log`；看 `SourceMerge::remainingCooldown('weather')` | 主源被限流且备源也失败；或缓存被清且上游不可用 |
| 端点返回 451 | `grep consent conf/config.php` | 同意强制被打开。加 `?consent=privacy,terms&consent_version=1` 或改回宽松 |
| 页面变成英文 | 请求头 `Accept-Language` | 语言协商按 `?lang=` > Cookie > `Accept-Language` > en |
| 首页改动不生效 | `curl -sI https://.../` 看 `eo-cache-status` | CDN 缓存（1h）。刷缓存，或等 `max-age` 到期 |
| JS/CSS 改动不生效 | 看页面里的 `?v=` 是否变化 | 若未变化说明未部署；若变化了则是浏览器缓存 |
| PoW 覆盖层一直转 | 浏览器控制台是否有 `/cap/vendor/*.wasm` 404 | `CAP_CUSTOM_*` 指错（必须是文件 URL，不能是目录） |
| PoW 覆盖层一直转（2） | Network 里是否有 `POST /cap/{siteKey}/challenge` | 若没有，widget 未启动（需 `solve()`）；若有且 500，端点缺 siteKey |
| 容器 unhealthy | `docker logs cap` | Redis 连不上（`network_mode: host` 是否还在）、或 `REDIS_URL` 指错库 |
| cron 报 warmup FAILED | `logs/warmup.log` | 注意：**预热结论已与线上一致**，若仍报 FAIL 是真失败 |
| 安全头出现多份 | `curl -sI` 数 `x-content-type-options` 的份数 | 面板重新保存了「安全」设置，需重新注释 vhost 三行 |

### 安全头单点归属（**改之前先读这段**）

实测三条 nginx 规则：
1. `add_header` 到不了 `location ~ [^/]\.php` 的 fastcgi 响应——除非写在 `server` 层或该 location 内。
2. `server` 层的 `add_header` 对**动态响应会应用两次**（PHP 发 3 个时出现 3×2，而 nosniff 只有 1 份）。
3. 精确/前缀 location **一旦自己写了 `add_header`**，`server` 层的整组**全部丢弃**。

因此：**动态响应由 PHP 下发**（`lib/Http/Response.php`，`http.security_headers_owner = 'app'`），
**每个静态 location 自己把 4 个头抄全**，`server` 层不写。若把 owner 改成 `nginx`，
动态响应会**失去全部安全头**（规则 1）。

验证：

```bash
for p in / /docs /legal /privacy /terms /v1/meta /openapi.yaml /assets/app.css; do
  h=$(curl -s -o /dev/null -D - https://satellite.api.authskin.xyz$p | tr -d '\r')
  printf '%-20s nosniff=%s referrer=%s frame=%s perm=%s\n' "$p" \
    "$(printf '%s' "$h" | grep -ci '^x-content-type-options:')" \
    "$(printf '%s' "$h" | grep -ci '^referrer-policy:')" \
    "$(printf '%s' "$h" | grep -ci '^x-frame-options:')" \
    "$(printf '%s' "$h" | grep -ci '^permissions-policy:')"
done
# 期望每个都是 1（301 跳转除外，安全头由目标响应承载）
```

### 运维信号必须与线上同源

任何"预热/巡检/健康检查"脚本**必须调用与线上相同的取数路径**。反例（已修）：
`Repository::fetchInto()` 曾自己直连 URL 并写缓存，于是它没有主备、没有冷却、URL 还被冻结，
结果 CelesTrak 被限流时 cron 报 `FAIL 0 records`，而站点正从 SpaceTrack 正常提供 72 条。
**当同一个数据集存在两条取数路径时，先确认它们是不是同一个实现。**

---

## 11. 变更记录

| 日期 | 变更 | 证据 |
|---|---|---|
| 2026-10-07 | 更名 `AzureOpenAPI for Satellite` / 版本 `3.1.2789-beta` | 全仓旧名/旧版本号残留 0 |
| 2026-10-07 | 双源接入：CelesTrak 主 / SpaceTrack 备（**实测时效性等同**） | weather 71/72 逐字节相同；amateur 94/95 逐秒相同 |
| 2026-10-07 | warmup 每小时 → **每 6 小时**（CelesTrak 限流根因） | cron `0 */6 * * *`；改前 crontab 已备份 |
| 2026-10-07 | `?envelope=none` 仅数据块（修"软件无法识别 data 块"） | 断言 351 → 377 |
| 2026-10-07 | 同意体系（宽松默认 / 451 可开关） | 6 个端点实测 |
| 2026-10-07 | index 转 PHP + 伪静态去扩展名 + 三语法务页 | 6 页 200、5 个旧地址 301、22 个 API 未受影响 |
| 2026-10-07 | 上游三修：共享会话 / 超时冷却 / warmup 走真实路径 | warmup 摘要 `FAIL 0 records` → `ok 72 records`；二次 refresh 19s → 9s |
| 2026-10-07 | Cap PoW 部署（固定 digest、零外联、fail-open） | 浏览器实测 challenge→redeem→verify-pow 全通 |
| 2026-10-07 | EdgeOne 规则修正（仅目标域名） | 18/18 校验；其他 5 条规则逐条比对未变 |
| 2026-10-07 | MD3 三级令牌 + 组件层接入 | 三页加载顺序 tokens→app→md3；三条门禁仍完好 |
| 2026-10-07 | UI 改为**扁平 + 直角**（`--shape-*` 全 0、`--elev-*` 全 none） | `border-radius: 0px` / `box-shadow: none` 实测；375/768/1024/1440 无横向溢出 |
| 2026-10-07 | 加载动画实现（此前几乎不可见） | 最小驻留 900ms；四格 + 扫描线 + 进度条；`prefers-reduced-motion` 下停止 |
| 2026-10-07 | 错误页改为构建期生成的静态页 | 5 条未匹配路径全部 404 + 6378B 自定义三语页 + 4 安全头 |
| 2026-10-07 | 修复测试套件静默截断（缺 `use` → 500 JSON → exit 0） | 断言 447 → **632/632**，退出码恢复可信 |
| 2026-10-07 | 修复 `/legal`、`/privacy`、`/terms` 未被 PoW 豁免 | 门禁不能挡住"被要求同意的文档" |
| 2026-10-07 | 首页数据修正 | 过时的 `3 h cache TTL` → `6 h refresh interval`；无可测依据的 `99.9% cache hit` → 实时 `service uptime` |

---

## 12. 术语表

| 术语 | 含义 |
|---|---|
| **envelope** | 响应外壳。`full` = `{meta,data}`；`none` = `{data,generated,count[,total]}` |
| **cooldown** | 数据源"不再重试"的窗口。403/429 → 7200s；传输层失败 → 900s |
| **primary / fallback** | 主源 / 备源。weather、amateur 的主源是 CelesTrak，备源是 SpaceTrack |
| **缩水检测** | 某源返回的记录数低于上次的 50% 时视为异常，改用备源 |
| **fail-open** | 依赖不可用时放行而非阻断（Cap 不可用 → 放行并标注） |
| **宽松模式** | 同意未被强制：不阻断，只在响应里标注 |
| **Stat-Led** | 页面首屏以实时服务数字开场，而非口号 |
| **零外联** | 访客浏览器不访问任何第三方域名 |
