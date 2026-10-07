# Satellite Data API v2.2 — 完整部署流程

> 适用对象：`satellite.api.authskin.xyz`（CelesTrak / AMSAT / SatNOGS 卫星数据代理）优化版。
> 交付物：`satellite.api.authskin.xyz_optimized.tar.gz`
> 目标平台：宝塔面板 / aaPanel + nginx + PHP-FPM（其他 Linux + nginx 环境同样适用）

---

## 目录

1. [交付物与版本信息](#1-交付物与版本信息)
2. [环境要求](#2-环境要求)
3. [步骤一：上传与解压](#3-步骤一上传与解压)
4. [步骤二：目录与权限](#4-步骤二目录与权限)
5. [步骤三：配置](#5-步骤三配置)
6. [步骤四：Web 服务器（二选一）](#6-步骤四web-服务器二选一)
7. [步骤五：环境自检](#7-步骤五环境自检)
8. [步骤六：首次预热](#8-步骤六首次预热)
9. [步骤七：功能验证清单](#9-步骤七功能验证清单)
10. [步骤八：定时任务](#10-步骤八定时任务)
11. [步骤九：Redis（可选）](#11-步骤九redis可选)
12. [步骤十：CDN / EdgeOne 配置](#12-步骤十cdn--edgeone-配置)
13. [步骤十一：监控与告警](#13-步骤十一监控与告警)
14. [日常运维：维护模式](#14-日常运维维护模式)
15. [升级流程](#15-升级流程)
16. [回滚流程](#16-回滚流程)
17. [兼容性说明](#17-兼容性说明)
18. [故障排查](#18-故障排查)
19. [上线后 24 小时观察项](#19-上线后-24-小时观察项)
20. [命令速查表](#20-命令速查表)

---

## 1. 交付物与版本信息

| 项 | 值 |
| --- | --- |
| 服务版本 | `2.2.0`（`meta.version`，`X-Service-Version` 响应头） |
| API 版本 | `v1`（`/v1/*`），旧路径作为兼容层保留 |
| 压缩包 | `satellite.api.authskin.xyz_optimized.tar.gz`（约 640 KB，75 个文件） |
| 站点根目录 | `/www/wwwroot/satellite.api.authskin.xyz`（本文以宝塔默认路径为例） |
| 需要修改的配置 | 1 个（伪静态里的 PHP socket 路径）+ 可选 1 个（`conf/config.php`） |

包内结构（重点）：

```
satellite.api.authskin.xyz/
├── index.php                 前端控制器（唯一动态入口，含 CLI 分支）
├── bootstrap.php             配置 / 自动加载 / 错误处理
├── docs.html  index.html     API 参考门户 / 落地页（纯静态）
├── openapi.yaml              OpenAPI 3.1
├── lib/                      应用实现（路由、端点、缓存、解析、错误…）
├── assets/                   设计系统 CSS/JS + Swagger 主题
├── conf/                     配置模板 + nginx 参考 + 宝塔伪静态块
├── bin/warmup.sh             缓存预热脚本
├── tests/                    测试（probe/run/request）
├── data/  logs/              运行期目录（必须可写、必须禁止 Web 访问）
└── *.php（tle_merge.php 等） 旧文件名的兼容外壳
```

---

## 2. 环境要求

| 项目 | 要求 | 说明 |
| --- | --- | --- |
| PHP | **7.4 ~ 8.4** | 已在 8.4 上跑通全部测试 |
| 必需扩展 | `curl`、`json` | 缺 `curl` 会导致全部上游抓取失败（`probe` 会报错并返回 1） |
| 可选扩展 | `redis`、`zlib`、`openssl` | redis=共享缓存/维护开关；zlib=PHP 侧 gzip；openssl=旧票据校验 |
| Web 服务器 | nginx + PHP-FPM | Apache 可用包内 `.htaccess` 兜底 |
| 面板 | 宝塔 / aaPanel（推荐） | 伪静态块已随包提供 |
| 可选 | Redis 5+、CDN（EdgeOne 等） | 无 Redis 自动降级为文件缓存 |
| 磁盘 | ≥ 500 MB 可用 | 缓存目录峰值约 30–60 MB（含 SatNOGS 全量目录） |

---

## 3. 步骤一：上传与解压

### 3.1 备份现有站点（务必先做）

```bash
cd /www/wwwroot
tar -czf /www/backup/satapi-$(date +%F-%H%M).tar.gz satellite.api.authskin.xyz
```

### 3.2 上传并解压

宝塔面板：**文件 → 上传** 到 `/www/wwwroot/`，然后终端执行：

```bash
cd /www/wwwroot
tar -xzf satellite.api.authskin.xyz_optimized.tar.gz
ls -l satellite.api.authskin.xyz
```

> 包内顶层目录名就是 `satellite.api.authskin.xyz`，解压后直接覆盖站点目录，**无需改动 nginx 的 `root` 指向**。

### 3.3 校验解压完整性

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
ls index.php bootstrap.php lib/Api/Router.php openapi.yaml docs.html conf/bt-panel-rewrite.conf
# 期望：5 个路径全部存在，无 "No such file"
```

---

## 4. 步骤二：目录与权限

```bash
cd /www/wwwroot/satellite.api.authskin.xyz

# 运行期目录
mkdir -p data/cache data/ratelimit logs

# 属主（宝塔默认 www 用户；用 `ps aux | grep php-fpm` 确认实际用户）
chown -R www:www data logs
chmod -R 770 data logs

# 校验可写性（任一行返回 not writable 都要修正权限；宝塔默认用户为 www）
sudo -u www php tests/probe.php | sed -n '/directories/,/^$/p'
```

> `data/`、`logs/`、`lib/`、`conf/`、`bin/`、`tests/` 都必须**禁止 Web 访问**——第 6 步的伪静态块/nginx 配置已包含封禁规则。

---

## 5. 步骤三：配置

### 5.1 生成本地配置（可选）

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
cp conf/config.example.php conf/config.php
vi conf/config.php
```

只需按需修改，其余保持默认。常用项：

```php
return array(
    'admin' => array('token' => ''),          // 建议改用环境变量，不要写进文件
    'ratelimit' => array(
        'enabled'     => false,               // 先观察一周再打开
        'per_minute'  => 120,
        'trust_proxy' => true,                // 前面有 CDN/反代时设为 true
    ),
    'http' => array('verify_tls' => true),    // 生产环境保持 true
    'legacy' => array('warn' => true),        // 旧路由返回 Deprecation 头
);
```

### 5.2 环境变量（推荐，密钥不入库）

宝塔：**软件商店 → PHP-x.x → 配置修改** 不便注入环境变量，推荐用 **网站 → 设置 → 配置文件** 外的两种方式之一：

```bash
# 方式 A：写进 php-fpm 池配置（/www/server/php/82/etc/php-fpm.d/www.conf）
env[SATAPI_ADMIN_TOKEN] = "请替换为随机长字符串"
env[SATAPI_TRUST_PROXY] = "1"
env[SATAPI_LOG_LEVEL]   = "warning"

# 方式 B：如果通过 shell/cron 调用 CLI，则在脚本里 export
export SATAPI_ADMIN_TOKEN='...'
```

| 环境变量 | 作用 | 默认 |
| --- | --- | --- |
| `SATAPI_ADMIN_TOKEN` | `POST /v1/maintenance` 的 Bearer 令牌；为空时该接口返回 503（安全默认） | 空 |
| `SATAPI_RATELIMIT_ENABLED` | 启用应用内限流 | `0` |
| `SATAPI_RATELIMIT_PER_MINUTE` | 每 IP 每分钟额度 | `120` |
| `SATAPI_TRUST_PROXY` | 信任 `X-Forwarded-For`（CDN 后必须为 1，否则限流按 CDN 节点 IP 统计） | `0` |
| `SATAPI_REDIS_ENABLED` / `_HOST` / `_PORT` / `_DB` / `_PREFIX` | Redis 连接 | 启用 / 127.0.0.1 / 6379 / 8 / `satapi:` |
| `SATAPI_VERIFY_TLS` | 上游 TLS 校验（**不要关**） | `1` |
| `SATAPI_CA_BUNDLE` | 自签/旧 CA 环境的 CA 包路径 | 空 |
| `SATAPI_CORS_ORIGIN` | 允许来源，`*` 或逗号列表 | `*` |
| `SATAPI_LOG_LEVEL` | `debug`/`info`/`warning`/`error` | `warning` |

修改后重启 PHP：

```bash
/etc/init.d/php-fpm-82 restart     # 或 宝塔面板 → 软件商店 → PHP → 重启
```

### 5.3 `.user.ini` 路径确认

```bash
cat /www/wwwroot/satellite.api.authskin.xyz/.user.ini
# open_basedir=/www/wwwroot/satellite.api.authskin.xyz/:/tmp/
```

若站点路径不同，请同步修改 `open_basedir`。

---

## 6. 步骤四：Web 服务器（二选一）

### 方案 A（推荐）：宝塔面板伪静态

1. 面板 → **网站 → 设置 → 伪静态**
2. 全选删除原有内容，粘贴 `conf/bt-panel-rewrite.conf` 全文
3. **把 `fastcgi_pass unix:/tmp/php-cgi-82.sock;` 改成实际版本**：

```bash
ls /tmp/php-cgi-*.sock          # 例如输出 /tmp/php-cgi-82.sock
```

4. 保存 → 面板 → **服务 → Nginx → 重载配置**

> 伪静态块只含 `server`/`location` 级指令，通过 `=`（精确）与 `^~`（前缀）覆盖主配置的 location，不重复定义，因此不会触发 `duplicate location` 错误。若不想填 socket，可用块内注释的 `rewrite ^ /index.php last;` 变体。

### 方案 B：自管 nginx

将 `conf/nginx.conf.example` 合并进站点配置（替换 `<SITE_DOMAIN>`、`<SITE_ROOT>`、`<PHP_SOCK>`）：

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
cp conf/nginx.conf.example /www/server/panel/vhost/nginx/satellite.api.authskin.xyz.conf
sed -i 's#<SITE_ROOT>#/www/wwwroot/satellite.api.authskin.xyz#g; s#<PHP_SOCK>#unix:/tmp/php-cgi-82.sock#g; s#<SITE_DOMAIN>#satellite.api.authskin.xyz#g' \
    /www/server/panel/vhost/nginx/satellite.api.authskin.xyz.conf
nginx -t && nginx -s reload
```

### 6.1 两种方案都必须满足的要点

- `root` 指向站点目录，`index index.html index.php`
- 动态请求进入 `index.php`（`/v1/*` 与旧友好路径 `/tle`、`/health` …）
- `lib|data|logs|conf|bin|tests` 返回 404
- `*.tar.gz`、`*.log`、`*.cache`、`*.md`、`.user.ini` 返回 404，`.well-known` 保持可访问
- 开启 gzip（`application/json`、`text/plain`、`application/yaml`）

### 6.2 重载并检查

```bash
nginx -t
nginx -s reload
tail -n 50 /www/wwwlogs/satellite.api.authskin.xyz.error.log
```

---

## 7. 步骤五：环境自检

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
php tests/probe.php
echo "exit=$?"
```

期望输出（片段）：

```
PHP 8.2.x (cli), cache backend: redis+file

extensions:
  curl      loaded   (required for fetching upstream data)
  json      loaded   (required for JSON responses and SatNOGS parsing)
  ...
directories:
  cache        writable /www/wwwroot/satellite.api.authskin.xyz/data/cache
  logs         writable /www/wwwroot/satellite.api.authskin.xyz/logs
...
OK: all required capabilities present
```

**退出码 1 = 缺少必需能力**，必须先修复（通常是 `curl` 扩展或目录权限）。

接着跑自带测试（不需要外网）：

```bash
php tests/run.php
# 期望：assertions: 163  passed: 163  failed: 0
```

---

## 8. 步骤六：首次预热

```bash
cd /www/wwwroot/satellite.api.authskin.xyz

# 全量预热（TLE 合并 + SatNOGS 目录 + AMSAT 状态）
php index.php refresh --target=tle,satellites,transmitters,amsat-status
echo "exit=$?"

# 机器可读版本
php index.php refresh --target=tle --json
```

期望输出：

```
[HH:MM:SS]   tle/weather   ok       1733 records    820 ms
[HH:MM:SS]   tle/amateur   ok        412 records    760 ms
[HH:MM:SS]   tle/amsat     ok         58 records    610 ms
[HH:MM:SS]   tle/satnogs   ok       2775 records   1240 ms
[HH:MM:SS]   satnogs_satellites     ok   2775 records   1180 ms
[HH:MM:SS]   satnogs_transmitters   ok   5017 records   1650 ms
[HH:MM:SS]   amsat_status           ok    932 records    540 ms
```

退出码 `0` = 全部成功；`1` = 有上游失败（查看输出的 error 列，常见为网络/CA 问题）。

查看健康状态：

```bash
php index.php health
# overall: ok / degraded / critical ；退出码 1 表示 critical
```

---

## 9. 步骤七：功能验证清单

逐条执行，核对「期望」。

### 9.1 合并 TLE（文本）

```bash
curl -sS -D- -o /tmp/tle.txt 'https://satellite.api.authskin.xyz/v1/tle' | head -20
head -3 /tmp/tle.txt
grep -c '^1 ' /tmp/tle.txt
```

期望：
- `HTTP/1.1 200 OK`
- `Content-Type: text/plain; charset=utf-8`
- `ETag: "..."`、`Last-Modified: ... GMT`
- `Cache-Control: ... s-maxage=... stale-while-revalidate=...`
- `X-Cache-Status: HIT`（预热后）、`X-Cache-Age`、`X-Cache-TTL`、`X-Cache-Backend`
- 正文前几行是 `#` 注释，随后是标准 20 列 TLE；卫星数 ≈ 1700

### 9.2 JSON 表示与信封

```bash
curl -sS 'https://satellite.api.authskin.xyz/v1/tle?norad=25544&format=json' | php -r '
$d=json_decode(stream_get_contents(STDIN),true);
printf("api=%s version=%s count=%d first=%s\n",$d["meta"]["api_version"],$d["meta"]["version"],$d["meta"]["count"],$d["data"][0]["name"]);
printf("tle1=%s\n",$d["data"][0]["tle"][0]);'
```

期望：`api=v1 version=2.2.0 count=1 first=ISS (ZARYA)`，且 `tle[0]` 以 `1 25544U` 开头。

### 9.3 条件请求（304）

```bash
ETAG=$(curl -sSI 'https://satellite.api.authskin.xyz/v1/tle' | awk -F': ' 'tolower($1)=="etag"{print $2}' | tr -d '\r')
curl -sS -o /dev/null -w '%{http_code}\n' -H "If-None-Match: $ETAG" 'https://satellite.api.authskin.xyz/v1/tle'
```

期望：`304`。

### 9.4 目录分页与投影

```bash
curl -sS 'https://satellite.api.authskin.xyz/v1/transmitters?per_page=2&fields=uuid,mode,baud' | php -r '
$d=json_decode(stream_get_contents(STDIN),true);
printf("rows=%d total=%d pages=%d next=%s\n",count($d["data"]),$d["meta"]["pagination"]["total"],$d["meta"]["pagination"]["total_pages"],$d["meta"]["pagination"]["next"]);'
```

期望：`rows=2`，有 `total`/`total_pages`/`next`；响应体仅几 KB（对比旧版全量 3.67 MB）。

### 9.5 错误格式（RFC 9457）

```bash
curl -sS -D- 'https://satellite.api.authskin.xyz/v1/tle?format=xml' | head -12
```

期望：`HTTP/1.1 400`，`Content-Type: application/problem+json; charset=utf-8`，正文含
`type` / `title` / `status` / `detail` / `code=bad_request` / `instance` / `request_id`。

### 9.6 健康与探针

```bash
curl -sS -o /dev/null -w '/v1/health        -> %{http_code}\n' https://satellite.api.authskin.xyz/v1/health
curl -sS -o /dev/null -w '/v1/health/live   -> %{http_code}\n' https://satellite.api.authskin.xyz/v1/health/live
curl -sS -o /dev/null -w '/v1/health/ready  -> %{http_code}\n' https://satellite.api.authskin.xyz/v1/health/ready
curl -sS -o /dev/null -w '/v1/status        -> %{http_code}\n' https://satellite.api.authskin.xyz/v1/status
```

期望：`200 / 200 / 200（或 503 表示未就绪）/ 200`。

### 9.7 旧接口兼容

```bash
curl -sS -D- -o /dev/null 'https://satellite.api.authskin.xyz/tle' | grep -iE 'HTTP/|deprecation|link'
curl -sS -o /dev/null -w '%{http_code}\n' 'https://satellite.api.authskin.xyz/tle_weather.php'
curl -sS 'https://satellite.api.authskin.xyz/health' | head -c 120; echo
curl -sS -o /dev/null -w '%{http_code}\n' 'https://satellite.api.authskin.xyz/amsat/status'
```

期望：`/tle` 返回 200 且带 `Deprecation: true` 与 `Link: </v1/tle>; rel="successor-version"`；
`.php` 外壳与 `/health`、`/amsat/status` 均返回 200。

### 9.8 安全封禁

```bash
for p in /data/metrics.json /logs/app.log /lib/Config.php /conf/config.php /tests/run.php /.user.ini /README.md; do
  printf '%-24s -> %s\n' "$p" "$(curl -sS -o /dev/null -w '%{http_code}' https://satellite.api.authskin.xyz$p)"
done
```

期望：全部为 `404`（或 403）。

### 9.9 静态文档

```bash
curl -sS -o /dev/null -w 'docs.html    -> %{http_code} %{content_type}\n' https://satellite.api.authskin.xyz/docs.html
curl -sS -o /dev/null -w 'openapi.yaml -> %{http_code} %{content_type}\n' https://satellite.api.authskin.xyz/openapi.yaml
curl -sS -o /dev/null -w 'security.txt -> %{http_code}\n' https://satellite.api.authskin.xyz/.well-known/security.txt
```

期望：`200`，`openapi.yaml` 的 `content_type` 为 `application/yaml`（或 `text/yaml`）。

### 9.10 压缩

```bash
curl -sS -H 'Accept-Encoding: gzip' -D- -o /dev/null 'https://satellite.api.authskin.xyz/v1/transmitters?per_page=100' | grep -iE 'content-encoding|vary'
```

期望：出现 `Content-Encoding: gzip`（由 nginx 或 PHP 任一层提供）。

---

## 10. 步骤八：定时任务

宝塔：**计划任务 → 添加任务 → Shell 脚本**，或直接写 crontab：

```cron
*/10 * * * * /www/wwwroot/satellite.api.authskin.xyz/bin/warmup.sh --quiet
```

`bin/warmup.sh` 已内置：`flock` 防重入、日志按大小轮转、上游失败返回非 0 退出码。

环境变量覆盖（可选，写在脚本首行或 crontab 前）：

```bash
SITE_ROOT=/www/wwwroot/satellite.api.authskin.xyz
PHP_BIN=/www/server/php/82/bin/php
TARGETS=tle,satellites,transmitters,amsat-status
LOG_FILE=/www/wwwroot/satellite.api.authskin.xyz/logs/warmup.log
MAX_LOG_BYTES=5242880
```

验证：

```bash
/www/wwwroot/satellite.api.authskin.xyz/bin/warmup.sh --dry-run
tail -n 20 /www/wwwroot/satellite.api.authskin.xyz/logs/warmup.log
```

> 建议再加一条每日清理任务（限流桶、过期轮转日志）：
> `30 4 * * * /www/server/php/82/bin/php /www/wwwroot/satellite.api.authskin.xyz/index.php prune`

---

## 11. 步骤九：Redis（可选）

```bash
redis-cli -h 127.0.0.1 -p 6379 ping          # 期望 PONG
redis-cli -h 127.0.0.1 -p 6379 -n 8 dbsize   # 使用 DB 8
```

- 应用默认 `SATAPI_REDIS_DB=8`、键前缀 `satapi:`（`satapi:dataset:*`）。
- **维护开关沿用旧键** `maintenance:<endpoint>`（无前缀），升级后旧运维脚本仍可用：
  `redis-cli -n 8 setex maintenance:tle_weather 3600 "计划维护中"`
- Redis 不可用时自动降级为文件缓存，不会报错。

---

## 12. 步骤十：CDN / EdgeOne 配置

| 路径 | 缓存策略 | 说明 |
| --- | --- | --- |
| `/v1/tle`、`/v1/tle/*` | 遵循源站 `Cache-Control`（默认 3h/12h） | 已带 `ETag`/`Last-Modified`，支持 `stale-while-revalidate` |
| `/v1/satellites`、`/v1/transmitters` | 遵循源站（6h） | 分页参数属于缓存键的一部分（确保缓存键包含 query） |
| `/v1/amsat/status` | 遵循源站（1h） | — |
| `/v1/meta`、`/openapi.yaml`、`/docs.html`、`/assets/*` | 遵循源站（5m / 1h / 7d） | 静态资源可长缓存 |
| `/v1/health*`、`/v1/status` | **不缓存**（源站已 `no-store`） | 实时性要求 |
| `/v1/maintenance`（POST） | **不缓存** | 同时关闭该路径的 WAF 请求体改写 |

要点：

1. 缓存键**必须包含查询串**（`norad`、`format`、`page`、`fields` …），否则不同筛选会互相污染。
2. 开启「回源遵循 `Cache-Control`」，不要在前端强制固定 TTL。
3. 若启用源站限流，设置 `SATAPI_TRUST_PROXY=1`，并在 CDN 侧保留 `X-Forwarded-For`。
4. 回源协议用 HTTPS 时，确认 CDN 回源域名与证书匹配。

---

## 13. 步骤十一：监控与告警

### 13.1 探针

| 用途 | 路径 | 判定 |
| --- | --- | --- |
| 存活 | `/v1/health/live` | 200 即存活（不检查依赖） |
| 就绪 | `/v1/health/ready` | 200 可对外服务；503 未就绪 |
| 聚合 | `/v1/health` | 200=`ok/degraded`，503=`critical` |

```bash
# 单行探针脚本（可放入宝塔「计划任务」或拨测平台）
curl -fsS -m 5 -o /dev/null https://satellite.api.authskin.xyz/v1/health/ready || echo "READY FAIL"
```

### 13.2 指标

```bash
curl -sS https://satellite.api.authskin.xyz/v1/health | php -r '
$d=json_decode(stream_get_contents(STDIN),true);
printf("overall=%s redis=%s uptime=%ss req=%d err=%d avg=%sms\n",
 $d["overall"],$d["checks"]["redis"],$d["uptime_seconds"],
 $d["metrics"]["totals"]["requests"],$d["metrics"]["totals"]["errors"],$d["metrics"]["totals"]["avg_ms"]);
foreach($d["checks"]["cache_files"] as $k=>$v){ printf("  %-22s %s age=%s\n",$k,$v["exists"]?($v["fresh"]?"fresh":"stale"):"MISSING",$v["exists"]?$v["age_human"]:"-"); }'
```

指标持久化在 `data/metrics.json`，日志在 `logs/app.log`（JSON Lines，可按 `req_id` 关联）。

### 13.3 建议告警阈值

- `/v1/health` 连续 3 次 503 → 严重
- `checks.redis != connected` 持续 10 分钟 → 警告（降级为文件缓存）
- 任一 `cache_files.*.fresh == false` 或 `exists == false` 超过 1 小时 → 警告
- `metrics.upstream.*.fail` 持续增长 → 检查上游站点
- `logs/app.log` 出现 `fatal_error` / `uncaught_exception` → 立即处理

---

## 14. 日常运维：维护模式

按端点独立开关，端点 id：
`tle_merge`、`tle_weather`、`tle_amateur`、`tle_amsat`、`tle_satnogs`、`satnogs_satellites`、`satnogs_transmitters`、`amsat_status`、`health`

### 14.1 命令行

```bash
cd /www/wwwroot/satellite.api.authskin.xyz
php index.php maintenance --enable=tle_weather --message="计划升级，预计 30 分钟" --ttl=1800
php index.php maintenance --disable=tle_weather
php index.php maintenance --enable=all --ttl=600
```

### 14.2 HTTP API（需 `SATAPI_ADMIN_TOKEN`）

```bash
curl -sS -X POST https://satellite.api.authskin.xyz/v1/maintenance \
  -H "Authorization: Bearer $SATAPI_ADMIN_TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"endpoint":"tle_weather","enabled":true,"message":"Scheduled upgrade","ttl":1800}'

curl -sS https://satellite.api.authskin.xyz/v1/maintenance   # 查看当前状态（公开只读）
```

行为：JSON 客户端收到 `503 application/problem+json`（`code=maintenance`，带 `Retry-After`）；
浏览器收到维护页；TLE 文本客户端收到 `# 503 ...` 纯文本。

---

## 15. 升级流程

```bash
# 1) 备份（含数据目录，便于回滚时保留缓存）
cd /www/wwwroot
tar -czf /www/backup/satapi-$(date +%F-%H%M).tar.gz satellite.api.authskin.xyz

# 2) 解压新版本（覆盖，保留 data/ 与 logs/）
tar -xzf satellite.api.authskin.xyz_optimized.tar.gz

# 3) 权限修复
cd satellite.api.authskin.xyz
chown -R www:www data logs && chmod -R 770 data logs

# 4) 自检 + 测试
php tests/probe.php && php tests/run.php

# 5) 预热 + 健康
php index.php refresh --target=tle,satellites,transmitters,amsat-status
php index.php health

# 6) 重载 Web（伪静态/配置有变化时才需要）
nginx -t && nginx -s reload
```

升级注意：

- `conf/config.php` 与 `data/`、`logs/` 属于本地状态，**覆盖前确认未被新包中的同名文件替换**（新包不含 `conf/config.php`）。
- 若上一版本缓存格式不同，首次请求会重新回源，`X-Cache-Status: MISS` 属正常。

---

## 16. 回滚流程

```bash
cd /www/wwwroot
rm -rf satellite.api.authskin.xyz          # 或改名保留
tar -xzf /www/backup/satapi-YYYY-MM-DD-HHMM.tar.gz
chown -R www:www satellite.api.authskin.xyz/data satellite.api.authskin.xyz/logs
nginx -t && nginx -s reload
curl -sS -o /dev/null -w '%{http_code}\n' https://satellite.api.authskin.xyz/v1/tle
```

**关键点**：维护开关存放在 Redis（`maintenance:*`）或文件 `data/.maintenance_*`，新旧版本键名一致，
因此回滚**不需要数据迁移**；旧版写入的 `tle_*_cache.txt` 不再被新版读取（新版使用 `data/cache/`）。

---

## 17. 兼容性说明

| 旧接口 | 升级后行为 |
| --- | --- |
| `GET /tle` | 仍返回文本 TLE；注释头改为英文 ASCII（旧版头部为 GBK 乱码），名称行统一为 2LE |
| `GET /tle/weather`、`/tle/amateur`、`/tle/amsat`、`/tle/satnogs` | 内容一致，新增 `X-Cache-Status` 等诊断头 |
| `GET /satnogs/satellites`、`/satnogs/transmitters` | 仍返回**完整**上游数组（不分页），字段与顺序不变 |
| `GET /amsat/status` | 仍为 `{status,generated,total_reports,data[]}`，`timestamp` 仍为 `Y-m-d H:i:s` |
| `GET /health` | 保留 `status`/`overall`/`checks.redis`/`checks.cache_files.<旧名>`/`service`/`timestamp` |
| `GET /status` | 面板视觉重做（去掉 CDN 依赖） |
| `.php` 文件名 | `tle_merge.php`、`health.php` 等仍可访问（3 行外壳，转发到统一实现） |
| Redis 维护键 | 仍为 `maintenance:<endpoint>` |

---

## 18. 故障排查

| 现象 | 可能原因 | 处理 |
| --- | --- | --- |
| `nginx -t` 报 `duplicate location` | 伪静态块与主配置定义了同名 location（如自己又写了 `location /`） | 删除重复定义；本包伪静态块只新增 `=`/`^~` 与不冲突的正则 |
| 502 Bad Gateway | `fastcgi_pass` 的 socket 路径不对 | `ls /tmp/php-cgi-*.sock` 后修正伪静态块；或改用 `rewrite ^ /index.php last;` |
| 访问 `/v1/tle` 返回 404 且响应是 nginx 404 页 | 动态路由未生效（伪静态未保存/未重载） | 保存伪静态 → `nginx -s reload`；确认正则未被主配置的 `location /` 抢先（需 `~` 或 `^~`） |
| 全部接口 503 `upstream_unavailable` | 缺 `curl` 扩展、出网被封、CA 过期 | `php tests/probe.php`；`curl -I https://celestrak.org` 测试出网；必要时配 `SATAPI_CA_BUNDLE` |
| 中文/`?` 乱码 | 客户端按 GBK 解码 UTF-8 | 接口本身就是 UTF-8；`Content-Type` 已带 `charset=utf-8` |
| 一直是 `X-Cache-Status: MISS` | `data/cache` 不可写 / 每次请求都过期 | 修权限；检查 `php index.php health` 的 `cache_files.*.ttl` |
| 304 不生效 | 客户端未回传 `ETag`，或中间层改写了响应体 | 用 9.3 的脚本验证；CDN 侧开启「保留源站 ETag」 |
| 限流把所有请求算到一个 IP | 在 CDN 后但 `SATAPI_TRUST_PROXY=0` | 设为 `1` 并重启 PHP-FPM |
| `POST /v1/maintenance` 返回 503 | 未配置 `SATAPI_ADMIN_TOKEN` | 配置后重启；或改用 `php index.php maintenance` |
| 旧监控读到 `checks.cache_files.tle_merge` 为空 | 首次部署未预热 | 执行 `php index.php refresh --target=tle` |
| 日志暴涨 | `SATAPI_LOG_LEVEL=debug` | 改回 `warning`；`data/metrics.json` 与 `logs/*.log` 会自动轮转 |

排障三件套：

```bash
php tests/probe.php                      # 环境能力
php index.php health                     # 缓存/Redis/上游
curl -sS -D- -o /dev/null <URL>          # 状态码与响应头（X-Cache-Status / X-Request-Id）
tail -n 50 logs/app.log                  # JSON Lines；装了 jq 可用 `| jq .` 美化
```

---

## 19. 上线后 24 小时观察项

1. `/v1/health` 保持 `ok`（或 Redis 缺失时的 `degraded`），无 `critical`。
2. `X-Cache-Status` 命中率为 `HIT` 为主，`MISS` 只在 TTL 到期后出现。
3. `data/metrics.json` 中 `upstream.*.fail` 不持续增长。
4. `logs/app.log` 无 `fatal_error`、`uncaught_exception`、`cache_write_failed`。
5. CDN 回源量下降（分页 + 投影 + `stale-while-revalidate` 生效）。
6. 一周后视情况开启 `SATAPI_RATELIMIT_ENABLED=1`，并确认 `SATAPI_TRUST_PROXY=1`。

---

## 20. 命令速查表

```bash
cd /www/wwwroot/satellite.api.authskin.xyz

# 环境 / 测试
php tests/probe.php [--json]        # 运行环境能力自检（缺必需扩展时退出码 1）
php tests/run.php [--verbose]       # 163 条单元断言
php tests/request.php GET '/v1/tle?format=json' --seed   # 单请求调试

# 运行维护
php index.php refresh [--target=tle,satellites,transmitters,amsat-status] [--json]
php index.php health [--json]       # 退出码 1 = critical
php index.php prune                 # 清理限流桶与过期日志
php index.php maintenance --enable=<endpoint> --message="..." --ttl=1800
php index.php maintenance --disable=all|--disable=<endpoint>

# 预设计划任务
*/10 * * * * /www/wwwroot/satellite.api.authskin.xyz/bin/warmup.sh --quiet
30 4  * * * /www/server/php/82/bin/php /www/wwwroot/satellite.api.authskin.xyz/index.php prune

# Web
nginx -t && nginx -s reload
/etc/init.d/php-fpm-82 restart
```
