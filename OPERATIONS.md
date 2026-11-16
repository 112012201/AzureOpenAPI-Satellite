# AzureOpenAPI for Satellite — 操作手册

**服务**：AzureOpenAPI for Satellite · `3.1.2900-beta`
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
│   ├── Api/Endpoints/     Tle · Catalog · AmsatStatus · AmsatApi(上游 v1 透传) · System · Consent · Base
│   ├── Data/Repository.php  取数编排（主备、冷却、缩水检测）
│   ├── Data/SourceMerge.php 主备策略 / 冷却 / 组名单 / 互补
│   ├── Http/SpacetrackClient.php  SpaceTrack 会话与查询
│   ├── Support/           Consent · Pow · CapBootstrap · I18n · Announcement(条) · AnnouncementBoard(弹窗) · Problem
│   └── ...
├── partials/shell.php     页面外壳（公告条 + 公告弹窗 + 同意弹窗 + PoW 覆盖层 + 引导脚本）
├── content/
│   └── announcements/     **公告源文件（一条公告一个 .md）**，`php index.php announcements` 编译
├── assets/
│   ├── tokens.css         MD3 三级设计令牌（OKLCH）
│   ├── app.css            布局，消费令牌
│   ├── md3.css            组件层（必须最后加载）
│   ├── announce.js        公告弹窗：已读/稍后/拒绝 + 拒绝拦截
│   ├── consent.js         同意/PoW/引导客户端逻辑（公告关闭后才接管屏幕）
│   └── vendor/cap/        本地化的 Cap widget 与解题资源（零外联）
├── conf/
│   ├── config.php         **部署配置（0600 www:www，含凭据，不在仓库）**
│   ├── tle-groups.php     组名单（NORAD ID 列表）
│   ├── i18n.php           三语词条
│   ├── cap.env            **Cap 容器环境（0600，含 ADMIN_KEY）**
│   └── cap-compose.yml    Cap 容器编排
├── data/                  运行态（缓存、冷却、SpaceTrack 会话、announcements.php 编译产物）
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
#
#    ★ 顺序：**放在最后一次资源改动之后执行**。第 1 步那个 ?v= 是从 assets/ 下文件内容
#      算出来的哈希（HomePage::assetQueryFor），错误页把这个串也烤进去了 —— 所以任何
#      一次 app.css / app.js / consent.js 的改动都会让错误页立刻过期，哪怕模板没碰。
#      2026-10-28 实测：19:48 生成后 "up to date"，19:54 改了 app.js，19:57 再查就变成
#      "STALE (would be 7093 bytes)" —— 字节数一样，只有版本串不同，靠眼睛看不出来。
#      也因此 --check 必须看**文字**结论，不要只看退出码（被 shell 管道包一层就会丢）。
/www/server/php/84/bin/php bin/build-error-page.php
# 检查是否已过期（CI 可用；过期返回 1）
/www/server/php/84/bin/php bin/build-error-page.php --check
# 无条件重跑一次确认幂等：第二次生成的 sha256 应与第一次相同

# 3) 兜底路由与错误页是否仍生效
for p in /a/b/c /v1/nope /nonexistent-file.txt; do
  printf '%-22s %s  %s 字节\n' "$p" \
    "$(curl -s -o /tmp/e.html -w '%{http_code}' -H 'Host: satellite.api.authskin.xyz' http://127.0.0.1$p)" \
    "$(stat -c%s /tmp/e.html)"
done
# 期望：全部 404，且 /tmp/e.html 约 6378 字节（含 error-page__code 标记）

# 3b) 改了 openapi.yaml 后：**必须**跑严格 YAML 检查（$ref 检查抓不到重复键）
#     PHP 的 yaml 扩展遇到同名键会静默保留最后一个 —— `$ref` 依然解析、路径数依然对，
#     所以 check-openapi.php 会说 OK；而 /docs 里的 swagger-ui 用 js-yaml，直接整份拒绝，
#     页面显示 "Reference unavailable"。2026-11-03 实测就是这么坏的（重复键 AmsatReport，
#     报错位置 2325:5，与浏览器控制台一致）。
python3 bin/check-openapi-strict.py openapi.yaml
# 期望：STRICT OK + paths/operations/schemas 三个数字；重复键会打出 行:列 并返回 1

# 4) 兜底 location 的顺序（放错地方会吞掉 /cap/vendor/ 等资源）
grep -n 'location ~ \^/(?!cap/vendor' conf/bt-panel-rewrite.conf
# 该行必须是文件里**最后一条**正则 location
```

---

## 5.6 发布一条公告（公告弹窗）

公告的**唯一来源**是 `content/announcements/` 下的 Markdown 文件，一条公告一个 `.md`。
页面永不直接读 Markdown：CLI 先把它编译成 `data/announcements.php`，请求路径只读那个数组。
这样“改文件 → 一条命令 → 上线”成立，而页面依旧对每个访客逐字节相同（CDN 共享缓存的前提）。

```bash
cd /www/wwwroot/satellite.api.authskin.xyz

# 1) 新增/修改公告
$EDITOR content/announcements/2026-11-03-acceleration-domain-migration.md

# 2) 先校验，不写盘（改坏了不会有任何东西上线）
/www/server/php/84/bin/php index.php announcements --check

# 3) 正式编译发布
/www/server/php/84/bin/php index.php announcements

# 4) 让已缓存页面在边缘刷新（首页 s-maxage=600，最多 10 分钟自愈）
#    两个 CDN 各清一次 / ，或者直接等
```

**Front matter 字段**（文件头 `---` 之间，`key: value`）：

| 字段 | 必填 | 说明 |
|---|---|---|
| `id` | 建议 | 稳定标识，`[a-z0-9-]`，≤64 字符。省略时用文件名（去掉日期前缀）。**改 id = 让已读过的访客再看一次** |
| `lang` | 否 | `zh-Hans` / `zh-Hant` / `en`，省略 = `all`（所有人都看）。**同一条公告的多语言版本共用同一个 `id`**：id 也是“已读”的键，所以确认了中文版就等于确认了这条公告。每个版本都会渲染进页面，由浏览器按当前语言挑一个显示（`assets/announce.js`） |
| `title` | **是** | 弹窗标题 |
| `date` | **是** | 发布日期 `YYYY-MM-DD`。日期在未来 = 定时发布（到点自动上线，无需重跑命令） |
| `author` | 否 | 发布人，默认 `Announcement system` |
| `level` | 否 | `urgent` / `important` / `normal` / `minor`，也接受 `紧急`/`重要`/`一般`/`次要`、`critical`/`high`/`low`。默认 `normal` |
| `refusable` | 否 | `true` = 允许访客“拒绝”；拒绝后正文被遮挡，只剩“刷新网页并重新询问”。**只给政策类公告用** |
| `ack` | 否 | `agree` / `read`，决定按钮文案；`refusable` 为真时强制 `agree` |
| `expires` | 否 | 到期日 `YYYY-MM-DD`（当日 23:59:59 前有效）。到期后**自动下线**，不需要重跑命令 |
| `draft` | 否 | `true` = 不编译（写一半的稿子不会泄漏到线上） |

行为约束（都对应已实测的缺陷，不要“优化”掉）：

- **有任何一个文件报错，整批不写盘**。以免一条打错的公告把另一条从线上顶掉而没人发现。
- 目录里 `*.md` 为 0 时报错并拒绝写空板；确实要清空用 `--force`。
- `--check` 只校验不写；`--json` 给脚本用。
- 访客状态（已读/拒绝）只存在浏览器 cookie 里：`announcement_read`、`announcement_declined`。
  **绝不要把“谁读过”渲染进 HTML** —— 页面是 CDN 共享缓存，那正是修过两次的串台缺陷。
- 编译产物是 `data/announcements.php`（`<?php return array(...)`）。请不要手改，
  也不要换成 `.json`：静态文件 location 会把 `.json` 原样发出去。
### 撤回 vs 删除

| 目的 | 做法 | 结果 |
|---|---|---|
| **立刻下架，但保留文件以后再说** | 文件里加 `draft: true`，然后编译 | 编译产物里没有它，线上立刻消失；文件还在，改回 `false` 即可复活 |
| **彻底删除** | 删掉 `.md`，然后编译 | 文件与线上同时消失（编译是整份重写，不是增量） |
| **只让新访客不再看到（已读的人不受影响）** | — | 做不到：公告是“未读才弹”，没有“只对新访客隐藏”的状态。要停就下架 |
| **定时下架** | `expires: YYYY-MM-DD` | 到那天 23:59:59 自动失效，无需重跑命令、无需改文件 |
| **撤回后让所有人重新看一次（比如改了个关键细节）** | 改 `id` | 新的 id 在 cookie 里是“未读”，于是会再弹一次 |

编译命令与发布相同（`php index.php announcements`）；**撤回也要编译**，只改文件不编译等于没改。
下线后想让边缘缓存立刻跟上，清一次 `data/announcements.php` 无法直接做到（它在 PHP 进程内被 OPcache 缓存），
实际做法是两个 CDN 各清一次 `/`，或者等首页 `s-maxage=600` 自愈。

- 公告弹窗在**三个域名上都渲染**（含 `satellite.api.authskin.xyz`）。
  「不在 API 地址显示」指的是 **API 响应**（`/v1/*`、`/amsat/status` 这类 JSON）——那本来就没有 HTML，
  是结构决定的，不是开关。`Config::endpoints.registry.*.announcements` 只是给未来某个"不该带公告"的
  域名留的钩子，当前三项全为 `true`。

---

## 5.7 维护开关：暂停 / 恢复 / 停止维护 / 停止服务

**先把四个词分清**，它们不是一个开关的四个档位：

| 说法 | 实际动作 | 访客看到 |
|---|---|---|
| 进入维护（暂停某端点） | `maintenance --enable=<endpoint>` | 该端点 `503` + 一张维护页（带 `Retry-After`） |
| 恢复（结束维护） | `maintenance --disable=<endpoint>` | 正常响应 |
| 停止维护（结束维护模式） | `maintenance --disable=all` | 同上，但作用于全部端点 |
| **停止服务** | 不是这个开关：停站点用面板/nginx，或全端点维护 + 明确文案 | 全站 503 维护页 |

**端点清单**（`Maintenance::ENDPOINTS`，`--enable=all` 表示全部）：

```
tle_merge  tle_weather  tle_amateur  tle_amsat  tle_satnogs
satnogs_satellites  satnogs_transmitters  amsat_status  amsat_api  health
```

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
PHP=/www/server/php/84/bin/php

# 暂停一个端点，并给出文案与时长（ttl 秒，60..86400；省略 = 3600）
$PHP index.php maintenance --enable=tle_merge --message="上游要素集正在重算" --ttl=1800

# 恢复
$PHP index.php maintenance --disable=tle_merge

# 全部端点一起进入维护（公告式停机，例如换证书 / 迁移）
$PHP index.php maintenance --enable=all --message="站点维护中，预计 30 分钟" --ttl=1800

# 结束全部维护
$PHP index.php maintenance --disable=all
```

也可以走 HTTP（需要 `admin.token` / `SATAPI_ADMIN_TOKEN`）：

```bash
curl -sS -X POST https://satellite.authskin.xyz/v1/maintenance   -H "Authorization: Bearer $SATAPI_ADMIN_TOKEN" -H 'Content-Type: application/json'   -d '{"endpoint":"tle_merge","enabled":true,"message":"上游要素集正在重算","ttl":1800}'
```

状态存在 Redis 的 `maintenance:<endpoint>`（带 TTL），Redis 不可用时回落到 `data/.maintenance_<endpoint>` 文件。
所以 **ttl 到期 = 自动恢复**，不需要人工回来关闭；要看当前状态用 `GET /v1/maintenance` 或
`curl -s /v1/health | jq .checks.maintenance`。

---

## 5.8 节日主题（圣诞 / 新年 / 春节）：什么时候自动生效

主题是**一层皮肤**：配色 + 首屏视频 + 一个背景特效 + （节日期间的）音乐 + Loading 页的祝福语。
它不改任何标记、路由、接口或文案，默认由 `conf/themes.php` 里的日历**自动**生效。

| 主题 | 生效窗口（Asia/Shanghai） | 首屏视频 | 页面背景 | 音乐 |
|---|---|---|---|---|
| 平时 `default` | 全年 | ISS 舱外地球（1.2 倍速，单镜头） | 无特效 | 无 |
| `christmas` | 2026-12-24 00:00 → 12-26 23:59 | 圣诞树上的挂饰与姜饼 | 圣诞夜空 + 圣诞树 | Jingle Bell Rock；**12-25 全天**换成 All I Want for Christmas Is You |
| `newyear` | 2026-12-31 00:00 → 2027-01-02 23:59 | 烟花（红金） | 烟花（稍浅的红） | 元旦 / Joy To The World，随机起播后轮换 |
| `spring` | 2027-02-04 00:00 → 02-09 23:59（02-10 0 点结束） | 烟花（金色 → 黄昏天际线） | 烟花（深红） | 花好月圆；**02-06** 春节序曲；**02-09** 难忘今宵 |

窗口结束时刻用的是**开区间**（写 `2027-02-10 00:00`），避免"23:59 还是 23:59:59"这种比较误差。

**主题名显示成祝福语，不是节日名。** 右下角徽标上写的是「圣诞快乐！/ Merry Christmas! /
聖誕快樂！」，不是「圣诞节 / Christmas」——节日期间要出现的是庆祝的话，不是贴在页面上的标签。
所以 `conf/i18n.php` 里 `theme.name.*` 与 `theme.greeting.*` 对同一个语言是同一句话（这是故意的），
徽标有用的是它的另一半：`theme.exit`（离开主题）。同理，打字标语里三种节日语都带感叹号
（中文/日文用全角「！」，其余用半角「!」）；平时的 Welcome / 欢迎 **不带** —— 那是邀请，不是祝贺。

**平时不需要配置。** 要临时验证或提前点亮：

```bash
# 预览（不改配置，也不影响别人）：加一个查询参数
open 'https://satellite.authskin.xyz/?season=christmas'

# 关掉（对当前访客）：右下角徽标的「离开主题」，或 ?season=default
# 关掉（对全站）：不要动 conf/config.php 之外的东西，等窗口结束即可
```

**缓存**：由日历或配置决定的主题对所有人相同 → 页面照旧缓存；由 `?season=` 或访客 cookie
决定的主题是"按访客"的 → 该响应自动 `no-store`（与语言、地区同一条规则）。

### 首屏视频（film）

首页的版式是 whitehouse.gov 那种：导航栏不动，一段全宽视频占满第一屏剩下的高度，页面内容
整体排在它下面。**视频不是浮层**，它就是文档流里的一个 `<section class="film">`，往下滚才看到正文。

- 文件在 `assets/film/`：每个主题两档（`-1080.mp4` 桌面 / `-720.mp4` 手机）+ 一张 `-poster.jpg`。
  H.264、30fps、20 秒、**没有音轨**、循环播放。规格与来源见 `assets/film/README.md`。
- 哪个档位由 `assets/film.js` 按视口宽度与 `saveData` 决定（`<source media>` 对 video 无效）。
- 视频上有自己的**暂停按钮**（右上角）——循环播放必须给得出来的停法（WCAG 2.2.2）；
  `prefers-reduced-motion` 的用户默认就是暂停在首帧。
- **文件缺了就是没有这条视频带**，页面其余部分完好，主题的 canvas 特效接管背景。所以可以先把
  代码上线、后补视频。
- 高度是 `100svh - 实测顶部偏移`：公告条、接入提示、导航栏都算在"第一屏"里，写死 58px 会把
  视频底边和下滑提示顶到折线以下。

### 标语打字机（slogan）

视频带上那行字不是静态的：它逐字打出来、删掉、换成另一种语言再打，旁边的小字是**这种语言的名字**，
用**读者页面的语言**写（中文页看俄语时是「俄语」，英文页看俄语时是 `Russian-RU`）。

- 文案表在 `conf/slogans.php`：`languages`（26 种语言的名字，三种界面语言各一份 + `dir` 读写方向）
  和 `slogans`（四个主题 × 26 种语言：平时是 Welcome，节日是各自的祝福语）。
- `Season::filmPhrases()` 组装轮播；**第一句永远是页面语言**，读者不用等四句才看到自己的语言。
  `Season::filmLead()` 返回这一句，也就是没有 JS 时读到的那句、以及屏幕阅读器读到的那句。
- 首页标语是 **Welcome**（不是 Loading 屏那句 tagline，那个没变）。
- 打字的是 `assets/film.js` 的 `createTyper()`：读到页面里的
  `<script type="application/json" data-film-slogans>`，逐字上屏；光标是 CSS 画的。
- 停下来：右上角暂停按钮同时停视频和打字；滚出视口、切到别的标签页也会停。
  `prefers-reduced-motion` 直接不打字，服务端那句就是终态。
- **加一种语言**：在 `conf/slogans.php` 的 `languages` 加一行（名字 + dir），再在四个
  `slogans` 里各加一句；`tests/run.php` 会因为"有缺口"报红，所以漏了就看得见。

**上传**：这些文件只能从宝塔面板的文件管理器传。实测 SSH 上行到源站只有 ~1.4 KB/s
（10KB 要 6.8 秒），四十多 MB 要好几小时；下行 350 KB/s 正常。要传的文件按服务器路径放在
`I:\VAD\` 下（`I:\VAD\assets\film\…`、`I:\VAD\assets\music\…`），拖上去即可。传完核对：

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
sha256sum assets/film/*.enc assets/film/*.jpg assets/music/*.enc | sort
# 再跑一次测试：php tests/run.php（缺文件时 film 那组断言会红，就是它在报"还没传"）
```

### 视频与音乐加密（AES-256 + /media-key）

视频和音乐**不在磁盘上以明文存在**：`assets/film/*.mp4.enc`、`assets/music/*.m4a.enc` 是
AES-256-CBC 密文，页面拿到密钥后用 Web Crypto 解密成 Blob 再交给 `<video>` / `<audio>`。
爬虫顺着 `/assets/film/*.mp4`、`/assets/music/*.m4a` 只会拿到 404；就算猜到 `.enc` 路径，
没有密钥也只是噪声。

- **密钥与 IV** 在 `conf/media.php`（**已加进 `.gitignore`，不入库**）：一个 32 字节主密钥 +
  每个文件一个 16 字节 IV + MIME。所有文件共用主密钥（页面本来就要持有它），换密钥 = 改这一处
  再重跑加密脚本。
- **密钥端点**：`GET /media-key` → `{"v":1,"key":"<base64>"}`，`Cache-Control: no-store`，
  必须带**已解出 PoW 的 `cap_session` 签名 cookie**（`Pow::hasValidSession`），否则 403
  `{"error":"challenge_required"}`。nginx 里必须显式列名（`location ~ ^/(…|media-key)$`），
  否则会被兜底 location 404 掉——这一步漏了页面就解不开。
- **页面侧**：`assets/media.js`（`SatApiMedia.load(url, iv, mime)`）负责取密钥、取密文、解密、
  返回 `blob:` URL；`film.js` 与 `theme.js` 都走它，密钥只取一次、同一 URL 只解一次。
  挑战还没解完时会等 `body.is-verified` 再重试一次（密钥端点要 cookie）。
- **加一首歌 / 换一个视频**：
  1. 本地生成 IV：`php -r 'echo bin2hex(random_bytes(16));'`
  2. 把新条目写进 `conf/media.php` 的 `files`
  3. 明文上传到 `assets/film/` 或 `assets/music/`（同名 + `.enc` 会被脚本生成）
  4. 服务器上跑 `php bin/media-encrypt.php`：加密、**回读校验 sha256**、把明文挪到
     `/www/wwwroot/satbak/media/`（web 根之外）。`--dry-run` 先看清单，`--force` 重加密。
  5. `php tests/run.php`：会检查密文在盘、IV 在表、解密后头 4 字节是 `ftyp`、明文已不在 web 根。
- **回滚**：把 `/www/wwwroot/satbak/media/` 里的明文挪回 `assets/` 对应目录，再回退
  `Season.php` / `film.js` / `theme.js` / `media.js` 与 `index.php` 的 `/media-key` 路由。
- **它挡不住什么**：密钥必须交给浏览器，所以**有决心的人仍可解出明文**（截获 Blob、
  或者在 devtools 里重放）。它挡的是爬虫批量抓取、站点盗链和直接下载，这也是这条需求的真实
  目标。要更强的保护只能上 DRM（Widevine/FairPlay），那是另一套成本和兼容性。
- **首次播放延迟**：要先下载完整个密文再解密（视频 1.6–7.2MB、音乐 3.2–7.4MB），所以点开
  播放会多 100–500ms；换来的是密文照样进 CDN 与浏览器缓存（缓存里是密文，没有密钥没用）。

**音乐**：`assets/music/*.m4a.enc`（**AAC 192k**，8 首，响度实测 −12.7 ~ −13.3 LUFS，0.6 LU 跨度；
见该目录 README 的表格与编码链）。文件不存在时该曲目被跳过、窗口回退到本主题的默认曲目，
**站点不会变成静音**；补文件即可生效，不用改配置。
两首 AVS3（Joy To The World、春节序曲）用 `E:\ffmpeg`（带 `libarcdav3a` 的 HDR Vivid 版本）
解出来并入列。圣诞的第二首 `christmas-last-christmas.m4a` **已补回**——它的 opus 原件一直在
`E:\Users\Administrator\Music\NcmForge\`，之前说"源文件不在本机"是因为搜索只看了 J: 盘。
浏览器禁止带声音的自动播放：页面先尝试一次，被拦下后**首次点击/按键**才开始，
左下角始终有播放 / 暂停 / 重播；某首文件坏了会自动跳到下一首，不会把整个主题弄哑。

### 角落浮框：滚下去才出现

第一屏上有**固定**浮框：左下角 Cap 验证胶囊（**每个页面都有**，包括默认页）、左下角音乐条和
右下角主题徽标（主题开着时才有）。它们压着的正是视频带底部那行**打字标语**，所以现在第一屏上
**一个都不显示**，往下滚到视频带底边**越过它们**之后才淡入（240ms，`translateY(10px)` → 0）；
滚回顶部再淡出。

- **闸门写在 HTML 里**：`Season::bodyAttrs()` 给**所有页面**的 `<body>` 加 `data-floats="gated"`
  （`/verify` 自己造 `<body>`，不经过它，所以那个页面不受影响）。样式表（`assets/app.css`）据此
  先藏起来，不是"脚本先显示再藏"——那样会闪一帧。
- **开门的是 `assets/app.js` 的 `floats` 模块**，不是 `theme.js`：默认页根本不加载主题脚本，而
  Cap 胶囊在那儿是同一个角落。
- **阈值是视频带的底边，不是固定像素**：`film.getBoundingClientRect().bottom <= 视口高 − 140`。
  140px 是角落那一摞的最大占用（带 Cap 时音乐条 bottom 92px + 高约 34px，留出余量）。
  没有视频带的页面（`/docs`、`/legal/*`）退化成"滚过 24px 就出现"。
- **失败的验证永不被藏**：挑战失败时 `consent.js` 给胶囊打 `data-cap-state="failed"`，样式表
  对这一种状态直接放行——否则访客连重试的按钮都看不见。
- **键盘可达**：藏起来用的是 `opacity`，不是 `visibility`，控件仍在 Tab 顺序里；焦点落进去
  （`focusin`）就立刻淡入，离开再按滚动位置决定。
- **无脚本**：`@media (scripting: none)` 直接把闸门打开——没有脚本时音乐本来就播不了，
  但控件不该跟着消失。`prefers-reduced-motion` 只去掉位移和过渡，不改变"第一屏不显示"。
- **Cap 胶囊仍然优先**：它固定在左下角 `16px / z-index 210`，音乐条带 `themebar--cap` 抬到 92px，
  两者同时出现时仍有 11px 间隙（见 `assets/theme.css`）。
- **回滚**：这三处都不碰布局，删掉 `app.css` 的闸门段 + `app.js` 的 `floats` 模块即回到
  "一直显示"；`data-floats` 属性留着也无害。

### 滚动条

文档滚动条整个隐藏了（`assets/app.css` 末尾）：`html { scrollbar-width: none }` +
`html::-webkit-scrollbar { width: 0; height: 0 }`。**滚轮、触摸、键盘、PgUp/PgDn、Home/End、
页内锚点都不受影响**，只是那条 15–17px 的灰槽不再切进满宽视频带、也不再在深色页面上发白。

- `scrollbar-width` 是**继承属性**，只写在 `html` 上会把公告面板、代码块、宽表格里的滚动条
  一起弄没，所以紧接着一条 `body * { scrollbar-width: auto }` 把默认值还回去。
- 想要回来：删掉 `app.css` 末尾那三行即可（Firefox 与 Chromium 各一条，缺一条就只修一半）。
- 隐藏滚动条之后没有位置指示了；这是"沉浸式"的代价，滚动本身没坏。

**新增一个主题**：在 `conf/themes.php` 里加一项（窗口、调色板 css、特效 js、音乐、祝福语 key、
`film`），在 `conf/i18n.php` 里加 `theme.name.<名字>` 与 `theme.greeting.<名字>`，把视频放进
`assets/film/`。不需要碰任何模板。

---

## 5.9 搜索引擎可见性（SEO）

**一句话**：`lib/Support/Seo.php` 是 `<head>` 里所有面向搜索引擎的标签的唯一出处；规范域名
只有一个，`Config::get('seo.site')`，留空时回退到 `endpoints.registry.origin.url`
（即 `https://satellite.authskin.xyz`）。

### 2026-11-14 修掉的四个真问题

这四条都不是"优化空间"，是**当前正在扣分**的缺陷。它们在页面上看不出来，任何一条都不会报错。

| # | 现象（改前实测） | 后果 | 现在 |
|---|---|---|---|
| 1 | 首页/法务页 `<link rel="canonical">` = `https://www.authskin.xyz/` | 那是**运营方的另一个站**。canonical 指到别处 = 请求搜索引擎不要收录本页 | canonical 一律是**本页在本站的地址** |
| 2 | `sitemap.xml` 每个 `<loc>` 都是 `https://satellite.api.authskin.xyz`（**该域名不解析**） | 整个 sitemap 是一份死链清单；robots 里的 `Sitemap:` 也一样 | 7 条真实地址，全部以 `seo.site` 开头 |
| 3 | `/docs` 的 canonical = `{{host}}/docs.html`，而 `/docs.html` 是 301 到 `/docs` | canonical 指向一个会跳转的地址，搜索引擎直接忽略这条声明 | canonical = `/docs` |
| 4 | 任何不存在的路径（如 `/cookies`）= **HTTP 200** + 错误页正文 | 全站"软 404"：进软 404 报告、消耗抓取预算、可能被当作薄内容索引 | 真 404（见下面的 nginx 说明） |

顺带修掉的：`/no-acceleration` 从**打不开**（首页页脚 `/no-accel` → 301 → 404）变成 200；
`/legal/privacy`、`/legal/terms`、`/legal/no-acceleration` 改为 301 到各自的规范地址；
法务页不再一律 `X-Robots-Tag: noindex`（只有 `?region=` 手选文档时才是 noindex —— 那份不是
别人拿到的地址，不该成为被索引的代表）。

### 每个 HTML 页面现在输出什么

`Seo::head()` 一次给全：`robots`（`index, follow, max-image-preview:large, ...`）、
`description`、`canonical`、**自引用的 hreflang 全簇**（`zh-Hans` / `zh-Hant` / `en` /
`x-default`，全部**绝对地址**）、`og:*`（含 `og:description`、`og:locale`、图片宽高与
`og:image:alt`）、`twitter:*`，以及一段 JSON-LD `@graph`。

JSON-LD 的节点：首页 `Organization` + `WebSite` + `SoftwareApplication`；`/docs`
`WebPage` + `BreadcrumbList` + `TechArticle`；法务页 `WebPage`（+ 非首页时的 `BreadcrumbList`）。
所有节点用 `@id` 互相引用，所以同一个对象只出现一次。

- **`<title>` 不由 `Seo::head()` 发**：每个调用方本来就在发，两个 title 才是 bug。
- **canonical 不是 `?lang=` 的发明者**：只有当访问者确实用了 `?lang=` 才把该参数写进 canonical，
  否则就是不带参数的规范地址。
- **`en` 只声明一次**：本站只有一份英文正文，`en-US` / `en-GB` 是按访客子标签变的 `html lang`，
  声明成两个 hreflang 等于承诺了两个其实是同一份的页面。
- JSON-LD 用 `JSON_HEX_TAG` 转义 `<`/`>`，正文里出现 `</script>` 也不会把图泄漏成文本。

### meta description 的长度规则

Bing 站长中心会直接报 `Meta description too long or too short`，判据是 **25–160 字符**。2026-11-14
实测：英文首页 176 字符（超），中文 125 字符（合规但在中文里等于两倍像素宽，搜索结果会被截一半）。

所以本站按**两条线**收敛，`tests/run.php` 的 `meta descriptions` 组会逐语言断言：

| 语言 | 下限 | 上限 | 依据 |
|---|---|---|---|
| `en` | 25 | **158** | 160 字符规则 + 留 2 字符余量 |
| `zh-Hans` / `zh-Hant` | 25 | **75** | 一个汉字≈两个英文字宽，75 字约等于两行完整展示 |

- 文案在 `conf/i18n.php`：`page.home.description`、`docs.meta_description`、`legal.meta.description*`。
- **法务页四条各自不同**（`legal.meta.description` 是总览，`.privacy` / `.terms` / `.no_accel` 各一条），
  四页共用一条会被判重复描述。
- 改完验收（6 个页面 × 3 语言都应在 25–160 内）：

  ```bash
  for u in / /docs /legal /privacy /terms /no-acceleration; do for l in zh-Hans zh-Hant en; do
    curl -sS "https://satellite.authskin.xyz$u?lang=$l" \
      | grep -o 'name="description" content="[^"]*"' | wc -m; done; done
  ```

  注意 `wc -m` 会把标签本身算进去，精确值看测试输出或 `php -r 'echo mb_strlen(...);'`。

### sitemap 与 robots

`sitemap.xml` 是**静态文件**（nginx 直出，`max-age=86400`），7 条：`/` `/docs`
`/openapi.yaml` `/legal` `/privacy` `/terms` `/no-acceleration`。
`robots.txt` 末尾那行 `Sitemap:` 必须与它同一个主机。

⚠ 两个文件都会被 `tests/run.php` 断言：每条 `<loc>` 必须以 `Seo::site()` 开头、路径必须是本应用
真的会服务的、`robots.txt` 里只能有**一条** `Sitemap:` 且主机正确。所以**加页面要同时改这三处**
（页面本身、sitemap、测试），否则套件会红。

### 软 404 的根因（nginx，值得记住）

`conf/bt-panel-rewrite.conf` 的兜底 location 原来是：

```nginx
location ~ ^/(?!cap/vendor/|assets/|error\.html) {
    try_files /error.html =404;   # <- 返回 200
}
```

`try_files` 命中文件时是一次**内部重定向**，状态码取那个文件的（200）；`=404` 只在文件**缺失**
时才生效。所以"状态码由 nginx 自己产生"这句注释是错的，实测就是 200。

现在是 `return 404;`，由 vhost 里那一条 `error_page 404 /error.html;` 接管 —— 这条链路本来就
被 `/v1/` 下的未知路由验证过（它们返回的正是 404 + error.html）。错误页自己的
`Cache-Control` / `X-Robots-Tag` 等头写在 `location = /error.html` 里，因为 **error_page 是内部
重定向，原 location 的 `add_header` 不会跟过去**。

### 验证（改完必跑）

```bash
cd /www/wwwroot/satellite.api.authskin.xyz && php tests/run.php   # 1103 条，含 40 条 SEO 断言

curl -sS https://satellite.authskin.xyz/ | grep -o '<link rel="canonical"[^>]*>'   # 必须是本站
curl -sS https://satellite.authskin.xyz/sitemap.xml | grep -c '<url>'              # 7
curl -sS https://satellite.authskin.xyz/robots.txt | tail -1                       # Sitemap: 本站/...
curl -sS -o /dev/null -w '%{http_code}\n' https://satellite.authskin.xyz/cookies   # 404，不是 200
curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' https://satellite.authskin.xyz/no-accel  # 301
curl -sS -o /dev/null -w '%{http_code}\n' https://satellite.authskin.xyz/no-acceleration            # 200
curl -sS -o /dev/null -w '%{http_code}\n' https://satellite.authskin.xyz/legal/privacy              # 301
```

换域名时只改一处：`config.php` 里 `'seo' => array('site' => 'https://新域名')`（或环境变量
`SATAPI_SEO_SITE`）。canonical、og:url、sitemap 的断言会一起跟着变 —— 但 `sitemap.xml` 是静态
文件，**要手工重新生成**，测试会立刻告诉你忘了。

### 已知限制（诚实列出）

1. **同一个 URL 会因为来源 IP 返回不同语言的正文**（大陆 → 简体，境外 → 英文/繁体，见 §2 的
   Region）。所以 `/?lang=en` 在 Googlebot（美国）那里是英文，在大陆的抓取器那里仍是简体。
   彻底解法是给每个语种独立路径（`/zh/`、`/en/`），那是另一次改动；现阶段 hreflang 只承诺
   "正文里同时有这三种语言，`?lang=` 选一个"。
2. **首页 HTML 124 KB（gzip 后 25.8 KB）**：26 个语种的正文全内联，这是"禁用 JS 也能读对语言"
   的代价（见 §2）。对 LCP 有影响，但没有做成客户端注入 —— 那会让爬虫看到空壳。
3. **三个域名同内容**（origin / EdgeOne / SCDN）。canonical 全部指回 origin，所以对搜索引擎是
   重复内容而不是三份内容；但若想更干净，可以在 CDN 上对 HTML 做 301 到 origin。
4. **Google Search Console / Bing 站长平台未接入**：没有验证文件、没有提交 sitemap、看不到索引
   覆盖报告。这一步需要域名控制权或 DNS TXT，属于运营动作，不是代码改动。

---

## 5.10 IndexNow 与站长平台验证

### 先说清楚它覆盖谁

**IndexNow 覆盖 Bing / Yandex / Seznam / Naver 等参与方，Google 不参与。** Google 的
Indexing API 官方只接受 `JobPosting` 与 `BroadcastEvent` 两类结构化数据，拿它提交普通页面违反
条款且实际无效。所以到 Google 只有两条正规通道：**sitemap + robots**、以及**已验证的 Search
Console 属性里手动提交**。本文档里任何"已提交 Google"的说法都不成立，别照着复述。

一次提交打进 `api.indexnow.org` 会扇出给所有参与方；本实现同时打 Bing 自己的端点
（`www.bing.com/indexnow`），多一个来回，但 Bing 是唯一有公开后台可核对的参与方。

### 初始化（每个部署一次）

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
php bin/indexnow.php --init     # 生成 32 位十六进制 key
```

它写两样东西：`conf/indexnow.php`（只存 key，已进 `.gitignore`）和根目录 `<key>.txt`
（内容就是 key，0644 —— nginx 以 www 用户读静态文件，0600 会让它 403，而 403 正好是引擎拒绝
提交的那条错误码）。**key 不是秘密**，协议要求它公开可读，这是验证方式本身。

### 验证与提交

```bash
php bin/indexnow.php --status   # key、公开可读性（自己 HTTPS 取一次并逐字节比对）、待提交 URL
php bin/indexnow.php --dry-run  # 只打印 JSON，不发
php bin/indexnow.php            # 提交 sitemap.xml 里的全部 URL
php bin/indexnow.php --url=/docs
```

URL 列表**从 `sitemap.xml` 解析**，不是第二份手写清单 —— 提交一个 404 地址会被回 422，两份清单
迟早会漂。`tests/run.php` 里 `indexnow` 那组断言：key 合法、key 文件存在且内容一致、URL 数等于
sitemap、payload 只有协议规定的四个字段、端点里没有 google。

### 状态码怎么读（别把 202 当失败）

| 返回 | 含义 | 现场怎么处理 |
|---|---|---|
| **200** | 已接收并校验通过，进了抓取队列 | 正常 |
| **202** | 已接收，**密钥还在异步校验**，列表已排队 | 正常。首次提交常见；几分钟后再提一次会变 200 |
| 400 | JSON 或字段有问题 | 看 `--dry-run` 的 payload |
| 403 | `keyLocation` 上的 key 文件取不到或内容不符 | 跑 `--status`，多半是 404/403/内容不一致 |
| 422 | URL 不属于该 host，或 key 与 host 不匹配 | 检查 `seo.site` 与 sitemap 是不是同一主机 |
| 429 | 配额 | 隔一段时间再提 |

**2026-11-14 本次实测**：首次提交 `indexnow 202 / bing 202`；约 5 分钟后再次提交
`indexnow 200 / bing 200` —— 说明密钥已被两边读到并校验，列表在队列里。
**"在队列里"不等于"已收录"**，这是两件事，交付时不要混着说。

### Bing Webmaster API（和 IndexNow 并行的第二路）

IndexNow 无凭证、只回一个 202/200；Bing 的 API 绑定**已验证属性**、有配额、而且能回读 sitemap 的
处理状态。两条都发，是因为它们回答的问题不同：一个"收没收到"，一个"归属于谁、还剩多少额度"。

```bash
php bin/bing.php --status    # 密钥（掩码）、可用站点、配额、已登记的 sitemap、待提交 URL
php bin/bing.php --dry-run   # 只打印两个请求体
php bin/bing.php --submit    # SubmitUrlbatch：sitemap 里 7 条一次性提交
php bin/bing.php --sitemap   # SubmitFeed：刷新 sitemap，然后回读状态
php bin/bing.php --set-key   # 从 stdin 读新密钥并写盘（0600），不打印
```

**凭证**：`conf/bing.php`（**0600**，已进 `.gitignore`），或在 `conf/config.php` / 环境变量
`SATAPI_BING_API_KEY`。这个 key 是**真秘密**（和 IndexNow 的 key 相反），所以：

- 命令行的任何输出都只给 `23a0****ff44` 这种掩码；`bin/bing.php` 里根本读不到明文 key，
  失败信息只报方法名与 HTTP 码。
- 轮换用 `php bin/bing.php --set-key`，密钥从 stdin 进，不要经过聊天/工单/截图。
- 泄漏了就当它已经泄漏：Bing 后台「设置 → API 访问」重新生成，然后 `--set-key` 覆盖。

**站点归属（实测，别猜）**：这把 key 名下只有 `https://authskin.xyz/`（顶域，已验证），没有
`satellite.authskin.xyz`。但用顶域作 `siteUrl` 时 `GetFeeds` 能回读到
`https://satellite.authskin.xyz/sitemap.xml`，`SubmitUrlbatch` 提交子域 URL 也返回 200 ——
**顶域属性覆盖子域**，不需要再单独加站点。

**`InvalidApiKey`（HTTP 400，code 3）＝ 这把 key 已经失效**，不是代码问题：同一个 key 一小时前
`GetUserSites` 还正常，之后调用全部 400 code 3——说明它在 Bing 后台被轮换/吊销了。处理：
后台重新生成 → `php bin/bing.php --set-key` → `php bin/bing.php --status` 确认站点与配额回来。
（IndexNow 不受影响：它不带凭证，同一时刻仍返回 200。）

**2026-11-14 实测记录**：`GetUserSites` 1 个已验证站点；配额 `98/天、1698/月`；
`GetFeeds` 已有一条 `satellite…/sitemap.xml [Success]`（**说明 sitemap 早就提交过**）；
`--submit` HTTP **200 accepted**（7 条进抓取队列）；`--sitemap` HTTP **200**，随后状态变
`[Pending]` —— SubmitFeed 之后 Bing 会重新抓一遍 sitemap，**抓完才回到 `Success`**，别在
Pending 时说它失败。



### 站长后台验证（Search Console / Bing Webmaster）

两条路都能走通，任选其一：

1. **HTML 文件**（最省事）：Search Console 下载 `googleXXXXXXXX.html`，放到站点根目录即可。
   实测根目录任意 `*.html` 由静态规则直出（`/googleTESTONLY.html` → 200 `text/html`，无 noindex），
   不需要动 nginx。
2. **meta 标签**：把控制台给的串填进配置，标签会自动出现在**每个页面**的 `<head>` 里：

   ```php
   'seo' => array(
       'google_verification' => '那串 token',
       'bing_verification'   => 'Bing 的 msvalidate.01 token',
   ),
   ```
   或环境变量 `SATAPI_GOOGLE_VERIFICATION` / `SATAPI_BING_VERIFICATION`（优先级更高）。
   验收：`curl -sS https://satellite.authskin.xyz/ | grep -E 'google-site-verification|msvalidate'`。

验证通过后，在 Search Console 里提交 `https://satellite.authskin.xyz/sitemap.xml`，并对需要的
URL 用"请求编入索引"。Bing 侧在 Webmaster Tools 里同样提交 sitemap（IndexNow 与 sitemap 是两套
并行信号，都要有）。

### 加页面之后

改 `sitemap.xml` → 跑测试（它会告诉你有没有漏）→ `php bin/indexnow.php` 提交。
没有 cron：只在内容真的变了才提，每次改 CSS 都提会把信号稀释掉。

### 回滚

删掉 `conf/indexnow.php` 与根目录的 `<key>.txt` 即可，站点其他部分不依赖它（没有 key 时
`IndexNow::key()` 返回空，命令行直接报错退出，网页完全不受影响）。

---

## 5.11 部署目录就是 git 检出（2026-11-16 起）

线上 `/www/wwwroot/satellite.api.authskin.xyz` **本身是一个 git 检出**，更新走 `git pull`，
不再用 tar 推送。仓库：<https://github.com/112012201/AzureOpenAPI-Satellite>（公开，`main` 分支）。

### 为什么 web 根里会有 `.git`

检出是原地建立的（`git init` + `fetch` + `checkout -f -B main`），所以 `.git/` 就躺在文档根里。
**它靠一条 nginx 规则活着**：

```nginx
location ~ /\.(?!well-known) { return 404; }
```

实测 `/.git/config`、`/.git/HEAD`、`/.git/index` 全部 404。**这条规则一旦被删，整个源码历史（含
所有历史版本）就公开可读**，`tests/run.php` 的 `security review` 组会盯着它。想彻底摆脱这个依赖，
把仓库放到 web 根之外再用 `include`/符号链接——但那是另一次改动。

### 日常更新

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
git fetch origin
git log --oneline HEAD..origin/main
git pull --ff-only origin main
php tests/run.php
```

### `.user.ini` 这个坑（第一次 pull 就踩了）

面板对 `.user.ini` 打了 `chattr +i`，git 无法替换它：

```
error: unable to unlink old '.user.ini': Operation not permitted
```

而且它里面是**本机的绝对路径**（`open_basedir=/www/wwwroot/satellite.api.authskin.xyz/:/tmp/`），
本来就不该进仓库。现在它已在 `.gitignore` 里、不再被跟踪。若某天又需要改它：

```bash
chattr -i .user.ini    # 改
chattr +i .user.ini    # 改回防篡改
```

同理不在仓库里、只在服务器上的：`conf/cap.env`、`conf/tle-groups.php`（生成）、`data/*.php`（生成）、
各 `conf/*.php` 凭据、`assets/film|music/*.enc`、IndexNow 的 `<key>.txt`。

### 回滚

第一次转换时的完整备份留在 `/www/wwwroot/satellite.api.authskin.xyz.pregit-<时间戳>`（277MB，
含当时全部文件）。整目录替换即可回滚到"非检出"状态。

---

## 5.12 安全审查结论（2026-11-16）

范围：外部可达面 + 代码入口 + 凭据 + 头部。**结论：外部面是紧的，两个真问题已修，三条已记录。**

### 已修

| # | 问题 | 处理 |
|---|---|---|
| 1 | 管理令牌接受 `?token=` | 查询串会进 access log / shell 历史 / Referer，等于把管理凭据写进日志。改为**默认只认 `X-Admin-Token` 与 `Authorization: Bearer`**，`?token=` 需要 `admin.allow_query_token=true` 才生效（默认 false，401 与错令牌无差别） |
| 2 | 转换检出后 web 根里出现 `.git/` | 已由点文件规则挡住（实测 404），并**加断言**防止规则被删；写进 §5.11 |
| 3 | `.user.ini` 被跟踪 | 它含本机绝对路径且不可替换，导致每次 pull 中断；移出仓库并加入 `.gitignore` |

### 已核验（无需改动）

- **外部路径全部 404**：`/conf/*`（含 `cap.env`、`tle-groups.php`、`cap-compose.yml`）、`/data/*`、
  `/logs/*`、`/lib/*`、`/partials/*`、`/tests/run.php`、`/bin/*`、`/.env`、`/.user.ini`、`/.git/*`。
- **明文媒体不可下载**：根目录那支 151MB 的 `.flac`、`assets/film/*.mp4`、`assets/music/*.m4a` 全是
  404 —— 静态白名单里没有这些扩展名。加密产物 `.enc` 才是 200。
- **危险函数**：无 `eval`；`unserialize` 一律带 `allowed_classes => false`；无动态 include 用户输入；
  无 `echo $_GET` 这类直出。
- **错误处理**：`display_errors=Off`（php.ini 与应用双保险），500 只回通用文案，细节进日志。
- **管理接口**：`hash_equals` 常量时间比较；未配置令牌时按 404 回（不暴露存在性）。
- **Cookie**：`Secure; SameSite=Lax`，PoW 会话另加 `HttpOnly`。
- **头部**：`nosniff` / `SAMEORIGIN` / `Referrer-Policy` / `Permissions-Policy` / `HSTS` 在 HTML 与
  API 响应上都存在；`Server` 不泄漏版本；TRACE/PUT/DELETE 一律 405。
- **CORS**：`*` 但不带凭据、只放 GET/HEAD/OPTIONS —— 公开只读 API 的合理选择。

### 记录但未改（需要运维取舍）

1. **应用层限流是关的**（`ratelimit.enabled=false`）。源站没有 CDN，`/v1/tle` 一次约 800KB，冷缓存
   还会打上游。**为什么不直接开**：开启后按 `REMOTE_ADDR` 计数，而 `satellite.db` 那条 CDN 路径
   的所有请求在源站看来是同一两个 IP —— 一开就把 CDN 用户共用一个桶。要开得先解决"CDN 后面怎么
   拿到真实客户端 IP"（可信代理段 + 只对已知边缘 IP 采信 `X-Forwarded-For`）。限流实现本身是
   **fail-open**（存储出错时放行），所以不存在"一开就把人挡在外面"的风险。
2. **没有 CSP**。页面里有内联脚本（主题预置、JSON-LD、加载器），严格 CSP 需要 nonce 化，属独立改动。
3. **根目录残留**：`404.php`（已无路由可达，nginx 兜底给 `error.html`）、151MB 明文 `.flac`、
   `BingSiteAuth.xml`（Bing 验证文件，应保留）。前两个建议移出 web 根。

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

### 6.4 AMSAT 上游 v1 代理（`/v1/amsat/*`）

`/v1/amsat/{catalog,reports,summary,statuses,health,satellites,sat-info}` 是
`https://status.amsat.org/api/v1/` 的**逐字节透传**，不加本站信封。三个不变量：

| 不变量 | 值 / 理由 |
|---|---|
| 上游轮询节奏 | **300 秒**（`Config::amsat_api.ttl`）。AMSAT 规范写明"不要比每 5 分钟更频繁地轮询"，所以这是下限，不是性能旋钮 |
| 可透传的参数 | 只有 AMSAT 文档里列的（`name`、`hours`、`since`、`limit`、`callsign`、`grid_square`、`status`、`include_stats`）。未知参数**直接丢弃**，数字按文档区间**钳制**，`since` 归一成 UTC，最后排序 —— 否则参数顺序/拼写会各自生成一个缓存键，把上游轮询放大 |
| 写入 | **不代理** `POST /reports.php`。本站是只读镜像，转发写入等于给 AMSAT 数据库当匿名跳板；该路径 `405` |

```bash
# 上游健康（AMSAT 自己的判定，不等同于本站 /v1/health）
curl -s https://satellite.authskin.xyz/v1/amsat/health

# 停/恢复整组代理（与 /v1/amsat/status 的开关是分开的两个）
/www/server/php/84/bin/php index.php maintenance --enable=amsat_api --message="AMSAT upstream maintenance"
/www/server/php/84/bin/php index.php maintenance --disable=amsat_api
```

**2026-11-03 实测**：8 个上游接口全部 200 + 真 JSON，但 `catalog` / `reports` / `summary` /
`satellites` / `sat_info` 返回 **0 条**（上游数据库为空），只有 `statuses`
（4 条：Heard / Telemetry Only / Not Heard / Crew Active）与 `health` 有内容。
透传是照实的：`"count": 0` 就是上游此刻的状态，不是本站故障。
`/v1/amsat/status` 与它们**不是同一个来源**（那条抓的是 amsat.org/status 的 HTML，仍有数据），
排障时不要互相印证。

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

### 8.6 CDN 应该缓存什么（清单）

源站每一类路径都已经用 `Cache-Control` 说明了意图，CDN 的职责是**别比源站更短**，并且
**别把按访客变化的东西缓存起来**。实测（2026-11-14，源站直连）：

| 路径 | 缓存 | 边缘 TTL 建议 | 源站实际发的头 | 为什么 |
|---|---|---|---|---|
| `/assets/film/*` | ✅ | **30 天** | `public, max-age=31536000, immutable` | 首屏视频 1.5–7MB；换片靠 URL 里的 `?v=<mtime hex>` |
| `/assets/music/*` | ✅ | 7 天 | `public, max-age=604800, immutable` | 节日期间反复播；换曲=部署 |
| `/assets/*`（css/js/字体） | ✅ | 7 天 | 同上 | 引用处带 `?v=<sha8>`，改版即换 URL |
| `/favicon*` `/icon-*` `/apple-touch-icon.png` | ✅ | 30 天 | `max-age=2592000` | 不常变 |
| `/site.webmanifest` `/robots.txt` `/sitemap.xml` | ✅ | 1 天 | `max-age=86400` | 偶尔变 |
| `/openapi.yaml` | ✅ | 10 分钟 | `s-maxage=600` | 契约会变 |
| `/v1/tle*` `/v1/satellites` `/v1/transmitters` `/tle*` | ✅ | 30 秒 + SWR 6 小时 | `s-maxage=30, stale-while-revalidate=21600` | 源站每 3 小时刷一次，30 秒足够新鲜 |
| `/` `/docs` `/legal/*`（HTML） | ⚠️ 仅国际站 | ≤5 分钟 | 大陆 `no-store`，国际 `public, max-age=300` | 正文随地区/语言/主题变化 |
| `/v1/health*` `/v1/status` `/v1/consent/*` `/cap/*` `/verify` | ❌ | 0 | `no-store` | 实时状态、每访客挑战 |
| `/media-key` | ❌ | 0 | `no-store, max-age=0` | 每访客的密钥，回答 403 或一份 base64；**绝不能进 CDN 缓存** |

> 视频与音乐现在是**密文**（`*.mp4.enc` / `*.m4a.enc`），上面两条的缓存策略不变：密文照样可以
> 放边缘，没有 `/media-key` 的密钥就是噪声。唯一的区别是 `.mp4`/`.m4a` 已经不存在，命中会 404。

三条硬规矩（都踩过）：

1. **`?v=` 必须参与缓存键**（EdgeOne：`QueryString` 全保留）。否则换片之后边缘还在发旧片子，
   而浏览器因为 `immutable` 根本不会回来问。
2. **Cookie 不参与缓存键，也不进缓存**。文档必须对每个访客逐字节相同——这正是修过两次的串台缺陷。
3. **免费版内嵌 IF 只有 3 条，已经用满**（§8.2 第 3 条）。想让 `/assets/film/*` 单独用 30 天，
   要么把现有 `/assets/*` 那条整体调长（会连带 css/js），要么升级套餐再加一条。

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
| 7 | 同一个 URL 按来源 IP 返回不同语言的正文（Region） | 各语种的 `?lang=` 变体对大陆抓取器仍是简体；hreflang 只承诺"正文含三语，参数选一" | 设计取舍，彻底解法是每语种独立路径。见 §5.9 已知限制 |
| 8 | **Bing 侧已打通**：API key 可用，属性 `https://authskin.xyz/` 已验证 | —— | 已解决。IndexNow 202→200；`SubmitUrlbatch` 7 条 **200 accepted**；`GetFeeds` 已有 `satellite…/sitemap.xml`。见 §5.10 |
| 9 | 同一个域有两个"站点"身份（顶域已验证、子域未单列） | 依赖 Bing 顶域属性覆盖子域（实测成立） | 若将来顶域不再覆盖子域，`SubmitUrlbatch` 会回 400；那时把 `seo.bing_site` 改成子域并在后台加站点 |
| 10 | **Google 未做任何提交**（按用户决定：仅等待自动收录） | 收录完全取决于 sitemap 抓取与自然发现，慢且不可控 | 代码侧只保证 sitemap/robots/canonical 正确；要主动推进只能验证 Search Console 后手动提交 |

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
| 2026-11-16 | 部署目录转为 **git 检出**（原地 `git init` + `checkout -f -B main`），更新改走 `git pull --ff-only`；安全审查：管理令牌默认不再接受 `?token=`、`.user.ini` 移出仓库、版本 3.3.1+ra | 检出后 `git log` = 75408cb、`php tests/run.php` 1138/1138、`/.git/config` 404；见 §5.11 / §5.12 |
| 2026-11-14 | meta description 按 25–160 收敛：英文首页 176→135，中文首页 125→90，中文 docs 101→66；四个法务页各写一条（原为同一条 74 字） | 线上 6 页 × 3 语言实测 50–137；断言 1133 → **1138/1138**。见 §5.9 |
| 2026-11-14 | Bing Webmaster API 接入：`bin/bing.php`（status/submit/sitemap/dry-run/set-key）+ `conf/bing.php` 0600；IndexNow 与它并行 | `--status` 掩码 `23a0****ff44`、1 个已验证站点、配额 98/1698、feeds 1 条 Success；`--submit` **200 accepted**（7 URL）；`--sitemap` **200**（随后 Pending）；断言 1124 → **1133/1133**。见 §5.10 |
| 2026-11-14 | IndexNow 接入：key 生成 + `<key>.txt` 公开验证文件 + `bin/indexnow.php`（sitemap 单源提交）；站长平台验证 meta 标签就位 | 首提 `api.indexnow.org 202` / `bing 202`，5 分钟后二提 **200 / 200**；`--status` 自检 `HTTP 200, body matches the key`；断言 1103 → **1124/1124**。详见 §5.10 |
| 2026-11-14 | SEO 修复：canonical 从 `www.authskin.xyz`（运营方另一个站）改为本站 origin 地址；sitemap/robots 从**不解析**的 `satellite.api.authskin.xyz` 改为 `satellite.authskin.xyz`；`/docs` canonical 从 301 地址改为 `/docs`；未知路径从 **200 软 404** 改为真 404 | 断言 1064 → **1103/1103**；`/cookies` 404、`/no-accel` 301→200、`/legal/privacy` 301、`/` canonical = 本站、sitemap 7 条全在本站。详见 §5.9 |
| 2026-10-07 | 更名 `AzureOpenAPI for Satellite` / 版本 `3.1.2900-beta` | 全仓旧名/旧版本号残留 0 |
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
| 2026-10-07 | 加载画面改为参照 **endfield.hypergryph.com** 的结构 | 全屏暗底 + `blur(8px)` 背景、居中方框标记、超大百分比读数、细进度条；离场为 `::after` 色带 `scaleX(0→1)` 横向擦除（`transform-origin:left`，560ms `cubic-bezier(1,0,.7,1)`）+ 延迟淡出。横屏进度条横向、竖屏自动改为左侧竖条（`orientation` 媒体查询）。**配色用本站品牌青而非参考站的 `#fffa00`，圆角保持 0** |
| 2026-10-07 | 加载动画按参考站**组件源码重置为三阶段**（预响应 / 线性加载条 / 全屏铺满进入，铺满后保持 1500ms） | 实测 铺满→淡出 2121ms、铺满→移除 2401ms；blur 8px→0 共 39 个中间值；断言 662 → 691 |
| 2026-10-07 | 加载动画修正：擦除改回**横向**左→右铺开、进度条纵向速度对齐 620ms、数字**逐值滚动** | 实测擦除 scaleX 0→1（origin left center）；绘制日志 1→67 零跳号；断言 691 → 702 |
| 2026-10-07 | 加载动画手感：追赶缓冲 · 100%停留加粗2倍 · 左慢右快缓加速平铺 · 交叉淡入 | 实测 停留300ms / 加粗2.00x / 拟合RMSE 0.1423 vs 0.2500 / 交叉透明度0→1；穷举差距1..100最慢384ms；断言 702 → 722 |
| 2026-10-07 | 加载动画四条轨道/四段曲线：常粗 12px · 全贝塞尔 · 进度四段 · 平铺四段 · 无停留直触发 | 实测 间隔 0ms / 加粗恒 12px / 平铺 scaleX 0.428→0.572 / 14 处贝塞尔 / 断言 709/709 |

### 加载画面（**所有 HTML 页面**）· 三阶段

**实现依据是参考站的组件源码**，不是照着截图猜的。该组件在
`web.hycdn.cn/endfield/official-v4/_next/static/chunks/8858-b4305fa4f5be6c8a.js`
（模块 `71272`），核心逻辑是：

```js
let [M, Z] = useState(0);                 // M = 已完成任务数
let h = C.length;                         // h = 任务总数
let A = () => { for (let e of (Z(0), C)) e().finally(() => Z(e => e + 1)); };
let f = useSpring(0, { stiffness: 120, damping: 20 });
useEffect(() => { f.set(M / h * 100) }, [M, h, f]);
useEffect(() => {
  if (M >= h) { onLeaving?.(); g(true);                       // 阶段 3 开始
    setTimeout(() => m.setState({ loaded: true }), 1500);      // 铺满后保持 1500ms
    let e = setTimeout(() => onFinished?.(), 2400);            // 2400ms 交棒
    return () => clearTimeout(e);
  } g(false)
}, [M]);
// 背景持续去焦（不是只在结尾才切换）：
animate={{ filter: `blur(${8 - M / h * 8}px)` }} transition={{ type: 'tween', duration: .5 }}
// 进度条：竖向生长
animate={{ height: `${M / h * 100}%`, width: '100%' }}
```

**关键认识有两条**：进度由**真实任务完成数**驱动（`M/h`），不是定时器；**背景模糊也是进度的函数**——页面在加载过程中**连续对焦**，而不是最后才切换。

| 阶段 | 表现 | 本站实现 |
|---|---|---|
| **1 预响应** | 进度 0，背景**深度失焦 8px** | 进度 0，`blur(calc(8px - var(--boot-progress) * 8px))` |
| **2 线性加载条** | 左侧竖轨随任务完成生长；**背景同步去焦 8px→0** | 三个真实任务（`/v1/health/live` 往返 · Cap 挑战 · 交棒）驱动 `0 → 33 → 67 → 100`，伴随 **39 个**中间 blur 值 |
| **3 全屏铺满进入** | 色带铺满 → **保持 1500ms** → 淡出 → 2400ms 交棒 | `boot--leaving` 起 `boot-fadeIn`（620ms 铺满）→ `+1500ms` 加 `boot--done` 淡出 → `+2400ms` 移除 |

> **第三阶段的"保持"是最容易漏掉的一环**，也是整个动画手感的关键。早先的实现一擦除完就移除覆盖层，于是变成"闪一下"而不是"进入"。
> 实测：铺满→淡出 **2121ms**（期望 620+1500 = 2120ms）、铺满→移除 **2401ms**（期望 2400ms）。

**轴向是刻意相反的两条轴，不要"统一"它们**：

| 元素 | 轴向 | 依据 |
|---|---|---|
| **进度条** | **纵向**（上→下生长，`scaleY`，`transform-origin: center top`） | 参考实现即 `height: '${M/h*100}%'` |
| **离场擦除** | **横向**（左→右铺开，`scaleX`，`transform-origin: left center`） | 参考实现即 `transform-origin:left; transform:scaleX(0)`；且各客户端结尾一律是左→右横扫 |
| 数字/文案位移 | 纵向（跟随进度条） | 与进度条同轴 |
| 入场、标记、扫描线 | 纵向 | 运维要求 |

> ⚠ **曾经把擦除也改成纵向，那是错的**。参考里唯一横向的动作就是这道擦除，唯一纵向的是进度条。
> 断言因此按"双轴契约"分开校验（擦除必须 `scaleX` 且不得含 `scaleY`；进度条必须 `scaleY` 且不得含 `scaleX`），
> 而不是全局禁用 `scaleX`——全局禁令会让正确答案也判失败。

**缓动**：除**离场平铺**这一处按点名要求用 ease-in（左慢右快）外，其余 11 处声明全为 `linear`；断言固定"**恰好 1 处缓动**"。
**速度统一 620ms**：进度条 `transition: transform 620ms linear`、数字位移 `top 620ms linear`、离场平铺 `boot-fadeIn 620ms`——三者同速，读起来是一套系统而不是两个无关时序。
`orientation` 媒体查询只调尺寸，**不改变轴向**。

### 进度条与平铺（四条轨道 / 四段曲线）

**实现依据是两个参考站**，各自提供不同的部分。**参考站的原始实现在 [`docs/reference/loading/`](docs/reference/loading/) 里单独存放**（含来源 URL 与逐条摘录）；本节只记录本项目自己的取值与实测，要改加载动画请先读那两个文件，不要在本文档里找转述：

| 来源 | 提供的内容 |
|---|---|
| **tongdiaojihua.com** | `.app-loading-overlay:before { transform-origin:0; transition: transform .5s cubic-bezier(.76,0,.24,1); transform: scaleX(0) → scaleX(1) }`（左→右平铺）；`.progress-bar-container { width:12px }`（**常粗 12px**）；`height: var(--progress)` / `top: var(--progress)`（**像素长度驱动填充与读数**）；覆盖层 `transition: opacity .4s ease-out .5s`（**延迟淡出**） |
| **endfield.hypergryph.com** | 由**真实任务完成数**驱动全部视觉 |

**四项按你的要求改动**（与上一轮不同，以本轮为准）：

| 要求 | 实现 |
|---|---|
| 加载条**全部加粗**，不是完成时加粗 | 轨道**常数 `12px`**；删除 `.boot--full` 与 `width` 过渡 —— 宽度永不变化，因此**没有任何可导致闪烁的过渡** |
| **全部换为贝塞尔曲线** | 主声明 **14 处贝塞尔**，`linear` 仅存于 reduced-motion 分支（那正是它的用意）；断言固定"主声明无 linear 残留" |
| 进度条四段：**启动 / 维持 / 减速 / 停止** | 四段各有曲线与**行程份额**：`startup 0.28` / `sustain 0.38` / `deceleration 0.22` / `rest 0.12`（合计 1.000，维持段最大） |
| **不要突然闪烁，直接触发平铺** | 删除 100% 停留（300ms）与加粗那一拍；到 100% 后**等计数到位即平铺**（`settleRoll`），无中间步骤 |
| 平铺四段：**启动 / 加速 / 停止 / 淡入** | 平铺 `boot-fill 760ms cubic-bezier(.76,0,.24,1)`（参考站原曲线）；叠加内容淡出 `boot-out 300ms`；覆盖层延迟 500ms 淡出 `400ms`；页面同期交叉淡入 `400ms` |

**四段的选参不是靠眼感，而是搜索出来的。** 在候选曲线与份额组合中，保留**同时满足全部五条判据**的解：启动段从近静止加速、维持段为四段中最快、减速段末速低于本段均值、停止段最慢、全程无停顿（最低瞬时速率 >5 %/s）。共 3326 组通过，取其中最低瞬时速率最高（6.9 %/s）的一组：

```
段            份额    曲线                    段均值    段首    段末
启动          0.28   ease-in  (0.42,0,1,1)      88       8     143   %/s
维持          0.38   强 in-out (0.60,0,0.40,1)  123      81       7   %/s
减速          0.22   ease-out (0.00,0,0.58,1)    71      45      55   %/s
停止          0.12   in-out   (0.42,0,0.58,1)    39      28       5   %/s
```

> ⚠ **段名是"行程分配"而非"速率常数"**。份额即该段承担的**行程比例**，速率剖面由此**推导**出来，而不是靠调参。这样改差距大小时形状不变。
> 早先版本用 `remaining / frames` 推速率，该项在后期趋近 0，把曲线形状淹没了——实测启动段 164 px/s、维持段 1051 px/s，且启动段反而最慢。

> ⚠ **两个已修的实现缺陷**（都会让动效"看起来没生效"）：
> ① `startRoll()` 每次 `setProgress` 都重设秒表 —— 而 `setProgress` 每完成一个任务就调用一次，于是行程计时不断清零，显示值卡在 0.001 而目标已到 67。**秒表属于整段行程，不属于每次目标更新**。
> ② `total`（行程距离）只在**首次** `startRoll` 时计算，而首次发生在进度 0（无处可去），于是被钳到 0.001 并再未更新。**目标变化时必须重设行程基准**。

**验收（实测）**：

```
轨道    横屏 12×900 / 竖屏 12×896      常数 12px，无宽度变化
填充    竖屏 12×403 = 45%×896          像素长度驱动
读数    top: 403.2px                   与填充同源
平铺    数字到 100 与平铺开始间隔 0ms   无停留、无闪烁
        scaleX 0.428 → 0.572 → 0       缓加速（左慢右快）
平铺→淡出 783ms（期望 ≈760）· 平铺→移除 1679ms（期望 ≈1660）
交叉淡入 页面透明度 24 个取值
绘制日志 100 条、零跳号
断言 709/709 · 主声明无 linear 残留
```
**数字是逐值滚动的，不是跳变**。任务按步完成，原始值是跳的（0→33→67→100）。现在**数字与进度条都由"显示值"驱动**（目标值先行、显示值追赶），并**逐个绘出途经的每个整数**，所以从 10% 跨到 60% 会显示 11、12、13…60。

> **验证方式很重要**：`MutationObserver` **无法**证明是否跳号——它批量投递记录，同一帧内的多次写入会合并成一条，于是正确的滚动看起来像在跳（实测曾读到"3→6→8→12"这样的假跳号）。
> 唯一可信来源是**绘制顺序本身**，已记录在 `window.__SATAPI_BOOT_ROLL__`（`paintFigure` 每画一个值就追加）。实测该日志为 `1 → 2 → 3 → … → 67`，**零跳号**。

**验收：**

```bash
bash /tmp/loader-check2.sh          # 覆盖范围 / 三阶段 / 双轴 / 全线性 / 文案不串台 / 断言
```

```js
// 浏览器控制台：查看数字的真实绘制序列（判断是否逐值滚动）
window.__SATAPI_BOOT_ROLL__.join(' → ')
// 期望：1 → 2 → 3 → … → 67（每个整数都在，无跳号）
```

**时序常数（三者必须一起改）**：`assets/consent.js` 的 `PHASE3_BAND_MS = 620`、`PHASE3_HOLD_MS = 1500`、`PHASE3_TOTAL_MS = 2400`，对应 `md3.css` 的 `boot-fadeIn` 与 `.boot--done`，两处注释互相指向。

**进度变量写在 `document.documentElement` 上**，不是写在进度条元素上。背景层 `.boot__bg` 是进度条的**兄弟节点**，而 CSS 变量只能向下继承——写在条上会让背景永远读到兜底值 0，去焦完全不发生。这是实测发现 `blur` 恒为 `8px` 才定位到的。

**验收：**

```bash
bash /tmp/loader-check2.sh
# 覆盖范围 / 三阶段结构 / 全垂直 / 全线性 / 文案不串台 / 691 断言
```

三阶段时间线需要真实浏览器驱动，期望值如下：

```
首次 leaving:  ~6.3s    进度 100，色带开始铺满
首次 done:     ~8.4s    铺满后保持 → 淡出      间隔 ≈ 2120ms (= 620 + 1500)
覆盖层移除:    ~8.7s                           间隔 ≈ 2400ms
blur:  8px → 0px，约 39 个中间值（连续去焦，不是跳变）
进度:  0 → 33 → 67 → 100（真实任务，不是定时器）
```

**改了 `shell.php` / 公告条 / 同意弹窗 / 三语词条后必须重新生成错误页**（它内嵌了 shell）：

```bash
/www/server/php/84/bin/php bin/build-error-page.php          # 生成
/www/server/php/84/bin/php bin/build-error-page.php --check  # 过期则退出码 1
```

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
