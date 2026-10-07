# AzureOpenAPI for Satellite v2.2

统一的 TLE 与卫星元数据代理服务，聚合 **CelesTrak（Weather + Amateur）**、**AMSAT**、**SatNOGS DB** 三个上游，
对外提供去重合并的 TLE 流、单源 TLE、卫星/发射机目录、AMSAT 实时状态报告，以及健康检查与状态面板。

- API 文档（交互式）：`/docs.html`
- OpenAPI 3.1：`/openapi.yaml`
- 状态面板：`/v1/status`
- 服务元数据：`/v1/meta`
- **部署手册：`DEPLOY.md`（完整步骤、验证清单、排障与回滚）**

> 从旧版（`tle_merge.php` / `health.php` 直连脚本）迁移而来：**所有旧 URL 仍然可用**，
> 会附带 `Deprecation` 与 `Link: rel="successor-version"` 响应头，指向 `/v1` 中的对应端点。

---

## 1. 目录结构

```
.
├── index.php               前端控制器（唯一入口，含 CLI 分支）
├── bootstrap.php           配置/自动加载/错误处理/目录初始化
├── router.php              仅用于 `php -S` 本地开发
├── index.html              落地页（自包含，无 CDN 依赖）
├── docs.html               API 参考（内置 Swagger UI 5.32 + 自定义主题）
├── 404.html                自定义错误页
├── openapi.yaml            OpenAPI 3.1 描述
├── common.php              旧版函数兼容层（check_maintenance 等）
├── tle_merge.php …        旧文件名的兼容外壳（各 3 行）
├── lib/
│   ├── Api/Router.php      路由表（v1 + legacy）
│   ├── Api/Endpoints/      Tle / Catalog / AmsatStatus / System / StatusPage
│   ├── Cache/Store.php     Redis + 文件两级缓存，SWR + 单飞 + 原子写
│   ├── Cache/Lock.php      flock 单飞锁
│   ├── Data/Repository.php 所有数据集的取数/解析/缓存编排
│   ├── Data/Collection.php 过滤、投影、排序、分页
│   ├── Data/Amsat.php      AMSAT 状态页解析
│   ├── Http/Client.php     上游 HTTP 客户端（TLS 校验、重试、限长、并行）
│   ├── Http/Request.php    请求解析与内容协商
│   ├── Http/Response.php   ETag/304、缓存头、CORS、压缩、延迟任务
│   ├── Support/…           Problem(RFC 9457)、Maintenance、RateLimit、Metrics、Legacy
│   ├── Tle/Parser.php      TLE 解析/校验/合并
│   ├── Config.php          默认配置 + conf/config.php + SATAPI_* 环境变量
│   ├── Logger.php          JSON Lines 日志 + 轮转
│   ├── Errors.php          错误/异常/致命错误统一转为 problem+json
│   └── Cli.php             refresh / health / prune / maintenance
├── assets/                 设计系统 CSS/JS + Swagger 暗色主题
├── conf/                   config.example.php、nginx.conf.example
├── bin/warmup.sh           缓存预热（flock、日志轮转、退出码）
├── tests/                  run.php（单元）、request.php（单请求调试）、fixtures/
├── data/                   缓存与状态（**必须禁止 Web 访问**）
└── logs/                   app.log / warmup.log
```

## 2. 部署

### 2.1 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 7.4 ~ 8.4（已兼容 8.4） |
| 扩展 | `curl`（必需）、`json`（必需）、`openssl`（仅旧票据校验）、`redis`（可选） |
| Web 服务器 | nginx + PHP-FPM（推荐）；Apache 可用 `.htaccess` 兜底 |
| 可选 | Redis（缓存 + 维护开关），EdgeOne 或其他 CDN |

### 2.2 步骤

```bash
# 1) 解压到站点根目录（保持目录结构）
tar -xzf satellite.api.authskin.xyz_optimized.tar.gz -C /www/wwwroot/
cd /www/wwwroot/satellite.api.authskin.xyz

# 2) 可写目录
mkdir -p data/cache data/ratelimit logs
chown -R www:www data logs

# 3) 本地配置（可选）
cp conf/config.example.php conf/config.php && vi conf/config.php

# 4) 环境变量（推荐用环境变量保存密钥）
export SATAPI_ADMIN_TOKEN='<随机长字符串>'   # 启用 POST /v1/maintenance
export SATAPI_RATELIMIT_ENABLED=1            # 可选：源站限流
export SATAPI_REDIS_HOST=127.0.0.1

# 5) nginx：二选一
#    5a) 宝塔面板（推荐）：网站 → 设置 → 伪静态，粘贴 conf/bt-panel-rewrite.conf
#        的内容即可，无需改动主配置；记得把块里的 php-cgi-82.sock 换成实际版本。
#    5b) 自管 nginx：参考 conf/nginx.conf.example（含静态缓存、目录封禁、限流、gzip）

# 6) 预热缓存并自检
php index.php refresh --target=tle,satellites,transmitters,amsat-status
php index.php health
```

### 2.3 定时任务

```cron
*/10 * * * * /www/wwwroot/satellite.api.authskin.xyz/bin/warmup.sh --quiet
```

`warmup.sh` 使用 `flock` 防重入、按大小轮转日志，并在上游失败时返回非 0 退出码。

## 3. API 一览

| 方法 | 路径 | 说明 | 缓存 |
| --- | --- | --- | --- |
| GET | `/v1/tle` | 合并去重 TLE（`group`、`norad`、`limit`、`format`、`meta`） | 3h |
| GET | `/v1/tle/{source}` | 单源 TLE：`weather` `amateur` `amsat` `satnogs` | 3h / 12h |
| GET | `/v1/satellites` | SatNOGS 卫星目录，分页 + 字段投影 + 过滤 | 6h |
| GET | `/v1/transmitters` | SatNOGS 发射机目录，分页 + 字段投影 + 过滤 | 6h |
| GET | `/v1/amsat/status` | AMSAT 实时状态报告，过滤 + 分页 | 1h |
| GET | `/v1/health` | 聚合健康（`?ui=1` 返回面板） | 实时 |
| GET | `/v1/health/live` | 存活探针 | 实时 |
| GET | `/v1/health/ready` | 就绪探针 | 实时 |
| GET | `/v1/status` | HTML 状态面板 | 实时 |
| GET | `/v1/meta` | 服务元数据与端点索引 | 5m |
| GET | `/v1/maintenance` | 各端点维护状态 | 实时 |
| POST | `/v1/maintenance` | 切换维护状态（需 Bearer Token） | 实时 |

示例：

```bash
curl -sS 'https://satellite.api.authskin.xyz/v1/tle?norad=25544&format=json' | jq '.data[0]'
curl -sS 'https://satellite.api.authskin.xyz/v1/tle/weather'            # 经典 20 列 TLE
curl -sS 'https://satellite.api.authskin.xyz/v1/transmitters?mode=FM&per_page=20&fields=uuid,mode,baud'
curl -sS -X POST 'https://satellite.api.authskin.xyz/v1/maintenance' \
     -H "Authorization: Bearer $SATAPI_ADMIN_TOKEN" \
     -H 'Content-Type: application/json' \
     -d '{"endpoint":"tle_weather","enabled":true,"message":"Scheduled upgrade","ttl":1800}'
```

### 响应约定

- **JSON 信封**：`{ "meta": { … }, "data": … }`，`meta` 含 `service/version/api_version/generated/request_id/elapsed_ms/count` 等。
- **错误**：RFC 9457 `application/problem+json`，字段 `type/title/status/detail/code/instance/request_id`；
  TLE 文本客户端仍收到 `# 4xx/5xx` 纯文本错误（内容协商自动区分）。
- **缓存**：`ETag` + `Last-Modified`，支持 `If-None-Match`/`If-Modified-Since` → `304`；
  `Cache-Control` 含 `s-maxage` 与 `stale-while-revalidate`，便于 CDN 边缘缓存；
  `X-Cache-Status`（`HIT|MISS|STALE`）、`X-Cache-Age`、`X-Cache-TTL`、`X-Cache-Backend` 便于排障。
- **CORS**：默认 `Access-Control-Allow-Origin: *`，`OPTIONS` 预检返回 204。
- **压缩**：客户端支持且响应 > 1 KiB 时由 PHP gzip（nginx 亦可接管）。

## 4. 运维命令

```bash
php index.php refresh [--target=tle,satellites,transmitters,amsat-status] [--json]
php index.php health [--json]          # 退出码 1 = critical
php index.php prune                    # 清理限流桶与过期轮转日志
php index.php maintenance --enable=tle_weather --message="..." --ttl=1800
php index.php maintenance --disable=all
```

## 5. 测试

```bash
php tests/run.php --verbose        # 163 条断言：解析、合并、缓存、限流、协商、路由、健康
php tests/request.php GET '/v1/tle?format=json' --seed      # 单请求调试（打印真实响应）
```

`tests/request.php` 是一个无需 Web 服务器的请求派发器，可直接复现线上路由行为。

## 6. 配置项

优先级：内置默认值 → `conf/config.php` → `SATAPI_*` 环境变量。

| 环境变量 | 说明 | 默认 |
| --- | --- | --- |
| `SATAPI_ADMIN_TOKEN` | 维护写接口令牌（为空则写接口返回 503） | 空 |
| `SATAPI_RATELIMIT_ENABLED` | 是否启用源站限流 | `0` |
| `SATAPI_RATELIMIT_PER_MINUTE` | 每 IP 每分钟额度 | `120` |
| `SATAPI_TRUST_PROXY` | 是否信任 `X-Forwarded-For`（CDN 场景设为 1） | `0` |
| `SATAPI_REDIS_ENABLED` / `_HOST` / `_PORT` / `_DB` / `_PREFIX` | Redis 配置 | 启用 / 127.0.0.1 / 6379 / 8 / `satapi:` |
| `SATAPI_VERIFY_TLS` | 上游 TLS 校验（**请勿关闭**） | `1` |
| `SATAPI_LOG_LEVEL` | `debug|info|warning|error` | `warning` |
| `SATAPI_CORS_ORIGIN` | 允许的来源，`*` 或逗号列表 | `*` |

## 7. 安全须知

- `data/`、`logs/`、`lib/`、`conf/`、`bin/`、`tests/` **必须**拒绝 Web 访问（nginx 示例已包含）。
- Redis 维护开关沿用旧键 `maintenance:<endpoint>`，升级后旧运维脚本仍可继续使用。
- 旧版 `verify_ticket()` 不再使用硬编码密钥：未配置 `AES_KEY`/`AES_IV` 时直接判定失败。
- 请通过 `SATAPI_ADMIN_TOKEN` 提供管理令牌，不要写入仓库。

## 8. 许可与数据来源

接口代码 MIT；上游数据版权归 **CelesTrak**、**AMSAT**、**SatNOGS DB（Libre Space Foundation）** 所有，
请遵守各站点的使用条款并保留署名。
