# AzureOpenAPI for Satellite — API 参考

> **服务名**：`AzureOpenAPI for Satellite` · **版本**：`3.3.1+ra` · **API 版本**：`v1`
> （`lib/Config.php:38,40`；`service.name` 的取值就是这四个词，不是本文的文体主张）
>
> **Base URL**：`https://satellite.api.authskin.xyz` · 别名 `https://satellite.authskin.xyz`
>
> **本文覆盖**：13 条 `/v1/*` 路径（14 个方法声明）、7 条旧路径、以及 10 个 `.php` 兼容别名。
> 路由表取自 `lib/Api/Router.php:252-277`（v1）与 `:270-276`、`:317-331`（旧路径）。
>
> 凡标「**实测**」的，都是在本文写作时对线上真实发起的 HTTP 请求的原样输出；
> 其余标注了源码行号。**本文只写在本地，未部署。**

---

## 目录

- [0. 五分钟速览](#0-五分钟速览)
- [1. 路由总览](#1-路由总览)
- [2. 查询参数速查表](#2-查询参数速查表全部-24-个)
- [3. `/v1` 端点逐条](#3-v1-端点逐条)
- [4. 旧路径（deprecated）](#4-旧路径deprecated)
- [5. 全局约定](#5-全局约定)
- [6. 实测发现（9 条）](#6-实测发现9-条)
- [7. 未验证与已知限制](#7-未验证与已知限制)
- [8. 双地址 / 预处理 / 热重载 / 预应答（2026-10-28 新增）](#8-双地址--预处理--热重载--预应答2026-10-28-新增)

---

## 0. 五分钟速览

### 0.1 两个信封，一个错误格式

**成功响应有两种信封**，由 `?envelope=` 选择（`lib/Api/Endpoints/Base.php:96-145`）：

```jsonc
// envelope=full（默认）
{ "meta": { … }, "data": [ … ] }

// envelope=none
{ "data": [ … ], "generated": "2026-10-11T14:29:30Z", "count": 100, "total": 2832 }
```

`envelope=none` 是为「把顶层当数组解析」的客户端准备的：它看到 `{meta,data}` 会报错或得到零行。
`generated`/`count`（以及分页端点的 `total`）在扁平形态里**刻意保留**——一个裸数组会让客户端无法判断数据新鲜度。
**未知的 `envelope` 值是 400，不是静默回落**（`Base.php:99-107`；**实测** `envelope=bogus` → 400 `bad_request`）。

**错误响应用 RFC 9457**（`lib/Support/Problem.php:8-9`，`Content-Type: application/problem+json; charset=utf-8`）：

```jsonc
{
  "type":     "https://satellite.api.authskin.xyz/errors/bad-request",  // code 里的 _ 换成 -
  "title":    "Bad Request",
  "status":   400,
  "detail":   "Parameter \"since\" must be an ISO-8601 timestamp or a Unix timestamp.",
  "code":     "bad_request",
  "instance": "/v1/transmitters",
  "request_id": "req_2bf24b2c500ceaec",
  "parameter": "since"                       // 各端点自带的额外字段
}
```

11 个 `code` 全表见 [5.2](#52-错误码全表11-个)。

### 0.2 鉴权

| 范围 | 要求 |
|---|---|
| 全部 `GET`/`HEAD` 数据与运维端点 | **无鉴权** |
| `POST /v1/consent/verify-pow` | 无鉴权（它是「让人进门」的端点） |
| `POST /v1/maintenance` | **`Authorization: Bearer <admin.token>`**，或 `X-Admin-Token: <token>`（`System.php:232-248`）。未配置 token 时该路由返回 **503**，不是 401 |

**实测**：`POST /v1/maintenance` 无 token → `401` + `WWW-Authenticate: Bearer`。

### 0.3 数据新鲜度与缓存

每个数据端点都在响应头里自述缓存状态（`Base.php:155-179`），**实测**：

```
x-cache-status: HIT          # HIT | MISS | STALE | EXPIRED | UNKNOWN
x-cache-age: 16107
x-cache-ttl: 21600
cache-control: public, max-age=5463, s-maxage=5463,
               stale-while-revalidate=43200, stale-if-error=604800
etag: "d5bc6f46a4939edd9f0c249c458f603f"
```

⚠️ **`X-Cache-Status` 描述的是数据缓存（redis/文件），不是 HTTP 响应缓存**——所以它和 CDN 是否命中无关。

### 0.4 同意（consent）与工作量证明（PoW）

- **同意声明**是请求参数：`?consent=privacy,terms&consent_version=1`，或请求头 `X-Consent: privacy,terms;version=1`。
  未声明时响应会带 `meta.consent` 对象与 **`X-Consent-Required`** 响应头（`Response.php:258-264`），但**默认不阻断**。
- **强制模式**（`SATAPI_CONSENT_REQUIRED=1`）下，未声明会得到 **HTTP 451** + `code: consent_required`
  （`Router.php:129-137`）。当前线上是**宽松模式**。
- **PoW 只针对 HTML 入口**，不挑战 `/v1/*`（`Config.php:206-217` 的设计说明）。默认 `pow.enabled=false`。

### 0.5 每个响应都带的头

| 头 | 值 / 说明 |
|---|---|
| `X-Service-Version` | `3.3.1+ra`（`Response.php:227`） |
| `X-Request-Id` | `req_` + 16 hex。**入站的 `X-Request-Id` 会被沿用**，但只接受 `^[A-Za-z0-9._-]{1,64}$`（`Request.php:97-101`） |
| `Vary` | 默认 `Accept, Accept-Encoding`；JSON 端点再追加 `envelope`（`Base.php:130-136`） |
| `Access-Control-Allow-Origin` | `*`（`cors.allow_origin` 默认 `*`，`Response.php:319-342`） |
| 安全头 | `X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Referrer-Policy: strict-origin-when-cross-origin`、`Permissions-Policy: camera=(), microphone=(), geolocation=()` |

---

## 1. 路由总览

### `/v1`（13 条路径 / 14 个方法声明）

| 方法 | 路径 | 端点 id | 限流桶 | 数据 TTL | 备注 |
|---|---|---|---|---|---|
| GET/HEAD | `/v1/consent/status` | `consent_status` | — | — | 同意 + PoW + cookie 清单 |
| POST | `/v1/consent/verify-pow` | `consent_verify_pow` | — | — | 换取 `cap_session` cookie |
| GET/HEAD | `/v1/tle` | `tle_merge` | `tle` | 取各源最小 TTL，无源时 900 | **text/plain 默认** |
| GET/HEAD | `/v1/tle/{source}` | `tle_source` | `tle` | weather 10800 · amateur 10800 · amsat 43200 · satnogs 10800 | `source` ∈ `weather\|amateur\|amsat\|satnogs` |
| GET/HEAD | `/v1/satellites` | `satnogs_satellites` | `catalog` | 21600 | 默认投影 9 字段 |
| GET/HEAD | `/v1/transmitters` | `satnogs_transmitters` | `catalog` | 21600 | 默认投影 11 字段 |
| GET/HEAD | `/v1/amsat/status` | `amsat_status` | `amsat` | 3600 | |
| GET/HEAD | `/v1/meta` | `meta` | — | — | 自述文档；`legacy_routes` 是**字符串 id 数组** |
| GET/HEAD | `/v1/health` | `health` | — | — | `?ui=1` 或浏览器 → HTML 面板 |
| GET/HEAD | `/v1/health/live` | `health_live` | — | — | 存活 |
| GET/HEAD | `/v1/health/ready` | `health_ready` | — | — | 就绪，依赖不可用时 **503** |
| GET/HEAD | `/v1/status` | `status` | — | — | **HTML** 面板 |
| GET/HEAD | `/v1/maintenance` | `maintenance` | — | — | 各端点维护状态 |
| POST | `/v1/maintenance` | `maintenance_write` | — | — | **需要 Bearer token** |

TTL 取自 `lib/Config.php:285,293,301,309,317,325,333`。

### 旧路径（7 条，全部 `Deprecation: true`）

| 旧路径 | 后继 | 与后继的差别 |
|---|---|---|
| `/tle` | `/v1/tle` | 注释头是旧格式（`Source status:` + `Merged satellites:`） |
| `/tle/{source}` | `/v1/tle/{source}` | 同上 |
| `/satnogs/satellites` | `/v1/satellites` | **返回原始裸数组**，不分页、不投影 |
| `/satnogs/transmitters` | `/v1/transmitters` | 同上 |
| `/amsat/status` | `/v1/amsat/status` | 旧信封 `{status,generated,total_reports,data}`，时间格式 `Y-m-d H:i:s` |
| `/health` | `/v1/health` | 同 body |
| `/status` | `/v1/status` | 同 HTML 面板 |

旧路径会带（**实测**，`Legacy.php:30-41`）：

```
deprecation: true
link: </v1/satellites>; rel="successor-version"
sunset: <仅当配置了 legacy.sunset>
```

⚠️ 这些头**只由调用了 `cacheHeaders()` 的端点发出**（`Base.php:178` 是 `Legacy::apply` 的唯一调用点），
所以 `/v1/meta`、health、status 与所有错误响应**不会**带 `Deprecation`。

### `.php` 兼容别名（10 个）

`/tle_merge.php`、`/tle_weather.php`、`/tle_amateur.php`、`/tle_amsat.php`、`/tle_satnogs.php`、
`/satnogs_satellites_proxy.php`、`/satnogs_transmitters_proxy.php`、`/amsat_status_proxy.php`、
`/health.php`、`/status.php`（`Router.php:317-331`）。

---

## 2. 查询参数速查表（全部 25 个）

★ **先读这一节的语义，再读各端点的参数表。** 这个 API 的参数解析有几个反直觉之处，全部来自
`lib/Http/Request.php`，不是我的推断：

| 解析函数 | 关键语义 | 源码 |
|---|---|---|
| `param($k,$d)` | 缺失 → `$d`。**值重复或嵌套（`?a=1&a=2`、`?a[b]=1`）会解析成数组 → 也返回 `$d`**，不是取第一个 | `:160-171` |
| `bool($k,$d)` | 真值集合 = `1` `true` `yes` `on` **以及空字符串**（所以 `?all` 本身就等于 `all=1`）。其它一切（含 `0`/`false`/`no`）为假。大小写不敏感 | `:173-181` |
| `int($k,$d,$min,$max)` | 缺失**或非数字** → `$d`；否则 `(int)` 转换后**夹到 min/max**（不是拒绝） | `:183-198` |
| `list($k)` | **只认逗号分隔**，`?norad=1&norad=2` 得到**空列表**。会 trim 并丢弃空项 | `:205-217` |
| `format($allowed)` | `?format=` 非空时**总是优先**；别名 `application/json`→json、`text/plain`/`plain`→tle、`text`→txt（**txt 不在允许集里，故 `?format=text` 是 400**）。不认识 → `null` → 调用方答 400 | `:254-274` |
| `envelope($allowed)` | 缺失 → `full`；别名 `data`/`data-only`/`dataonly`/`flat`/`bare`→`none`，`default`/`normal`→`full`；不认识 → `null` → 400 | `:293-316` |

### 2.1 参数总表

| 参数 | 出现在 | 类型/解析 | 默认 | 取值与约束 |
|---|---|---|---|---|
| `envelope` | 所有 JSON 数据端点 | enum | `full` | `full` · `none`（+6 个别名）。**未知值 400** |
| `format` | `/v1/tle*` | enum | `tle`（**只看 URL，不看 Accept**） | `tle` · `json`；`?format=text`/`ndjson` → 400。**`Accept` 在本路由被忽略**：实测边缘只发 `vary: Accept-Encoding`，`Accept` 不在 Vary 里，于是同一 URL 先被 `Accept: application/json` 预热后，下一个要文本的请求在边缘 `HIT` 上仍拿到 JSON |
| **`json`** | `/v1/tle`、`/v1/tle/{source}`（及旧 `/tle*`） | bool | 不设 | **推荐的 JSON 开关**：`json=true`（`1`/`yes`/`on`/裸 `?json`）→ JSON 信封；`json=false`（`0`/`no`/`off`）→ 强制 TLE 文本。优先级高于 `format=`，且因为 `Accept` 已不参与，它也是唯一明确的"要 JSON"的方式。不设时默认**标准 20 列 TLE 文本**。对只服务 JSON 的 `/v1/satellites`、`/v1/transmitters` 传 `json=false` → 400 |
| `format` | `/v1/satellites`、`/v1/transmitters` | enum | `json` | **只允许 `json`**；其它值 400（`Catalog.php:53-57`） |
| `consent` | 全部（除豁免路径） | csv | `""` | 文档名，逗号分隔。别名：`privacy-policy`/`privacy_policy`/`privacypolicy`→`privacy`，`tos`/`terms-of-service`/`terms_of_service`/`termsofservice`/`service`→`terms`。字符集 `[A-Za-z0-9_,. -]`，≤512 字节 |
| `consent_version` | 同上 | int | 无 | `^[0-9]{1,6}$`；与 `consent.version`（当前 `1`）不符 ⇒ `state: stale` |
| `page` | `/v1/satellites`、`/v1/transmitters`、`/v1/amsat/status` | int | `1` | 最小 1 |
| `per_page` | 同上 | int | `100` | 1..**1000**（`MAX_PER_PAGE`） |
| `all` | 同上 | bool | `false` | 真则**不分页**，返回全部（`?all` 空值也算真） |
| `fields` | `/v1/satellites`、`/v1/transmitters` | csv | 各端点的默认投影 | `all`/`*` = 全部；否则字段名须匹配 `^[a-z0-9_]{1,40}$`，**最多 30 个**，否则 400 |
| `search` | 同上 | string | `""` | 子串搜索，范围随端点不同（见 3.3/3.4） |
| `since` | `/v1/satellites`、`/v1/transmitters`、`/v1/amsat/status` | ISO-8601 或 Unix | `""` | 非法 → **400** `bad_request` |
| `until` | `/v1/amsat/status` | ISO-8601 或 Unix | `""` | 非法 → 400 |
| `sort` | `/v1/satellites`、`/v1/transmitters` | string | `""` | 字段名 |
| `order` | 同上 | string | `asc` | 仅与 `sort` 同用时生效 |
| `norad_cat_id` | `/v1/satellites`、`/v1/transmitters` | csv | 无 | 精确匹配 |
| `uuid` | `/v1/satellites`、`/v1/transmitters` | csv | 无 | 精确匹配 |
| `sat_id` | `/v1/satellites`、`/v1/transmitters` | csv | 无 | 精确匹配 |
| `mode` `status` `type` `alive` `operator` | `/v1/satellites`、`/v1/transmitters` | string | 无 | 单值精确匹配（非 csv） |
| `norad` | `/v1/tle`、`/v1/tle/{source}` | csv | 无 | 只保留这些 NORAD 编号；全不匹配 → **404** `not_found` |
| `limit` | `/v1/tle`、`/v1/tle/{source}` | int | `0`（不限） | 夹到 0..20000 |
| `group` | `/v1/tle` | enum | `all` | `all` · `weather` · `amateur` · `amsat` · `satnogs`；未知 → **400** |
| `meta` | `/v1/tle`、`/v1/tle/{source}` | bool | `true` | 假则**省略 text/plain 的注释头**。另可用 `include_meta=0/false/no/off` 抑制 |
| `pretty` | 所有 JSON 端点 | bool | `false` | 真则 `JSON_PRETTY_PRINT`（**实测** `?pretty=1` 输出缩进 JSON） |
| `ui` | `/v1/health` | bool | `false` | 真则渲染 HTML 面板而非 JSON |

> **没有 `format=ndjson`**：`Request.php:252` 的文档注释提到 `ndjson`，但别名表里没有它，
> `Tle.php:200` 也只允许 `tle|json`。注释超前于代码。

---

## 3. `/v1` 端点逐条

### 3.1 `GET /v1/consent/status` — 同意 / PoW / cookie 现状

无参数（`consent` 豁免路径之一是它自己）。`Cache-Control: no-store`。

**实测**完整响应体：

```json
{
  "consent": {
    "required": ["privacy","terms"], "accepted": false, "state": "missing", "version": 1,
    "urls": {"privacy":"/legal/privacy.html","terms":"/legal/terms.html"},
    "hint": "Include ?consent=privacy,terms&consent_version=1 to acknowledge both documents.",
    "missing": ["privacy","terms"]
  },
  "pow": {"enabled": true, "provider": "cap", "endpoint": "/cap",
          "site_key": "e3c6ce384f", "state": "missing", "fail_open": true, "session_ttl": 43200},
  "cookies": {
    "choice": "", "decided": false, "version": 0, "current_version": 1,
    "categories": {"essential":["cookie_consent","announcement_dismissed","cap_session"],
                   "preferences":["satapi-theme","satapi-lang"], "analytics": []},
    "lifetime_days": 180
  }
}
```

**状态码**：`200`。`state` 取值：`ok` · `missing` · `invalid` · `stale`（`Consent.php:31-34`）。

### 3.2 `POST /v1/consent/verify-pow` — 用 PoW token 换 session

| 参数 | 位置 | 类型 | 必填 |
|---|---|---|---|
| `token` | body（JSON `{"token":…}`）**或** query | string | 是 |

成功 → `200` + `Set-Cookie: cap_session=…`（`session_ttl` 默认 43200 秒）+ `Cache-Control: no-store`：

```jsonc
{"ok":true, "pow":{"state":"…","provider":"cap"},
 "session":{"expires_in":43200},
 "consent":{ … }}
```

失败 → **`403`** + `application/problem+json`，`code: pow_failed`，
额外字段 `pow: {provider, state}`（`Consent.php:59-70`）。

### 3.3 `GET /v1/satellites` — SatNOGS 卫星目录

参数：`fields` `search` `since` `sort` `order` `norad_cat_id` `uuid` `sat_id` `mode` `status` `type` `alive` `operator`
`page` `per_page` `all` `envelope` `format`(`json`) `pretty` `consent*`（见 [2.1](#21-参数总表)）。

- `search` 的搜索范围：`name`、`names`、`operator`、`countries`、`sat_id`（`Catalog.php:38`）。
- **默认投影 9 字段**：`sat_id, norad_cat_id, name, names, status, launched, operator, countries, updated`
  （`Catalog.php:25-27`）。`?fields=all` 返回上游的全部字段。
- 数据源：`https://db.satnogs.org/api/satellites/`，归属 `SatNOGS DB (libre.space)`，TTL `21600`。

**实测**（`?per_page=2`）响应体：

```json
{"meta":{"service":"AzureOpenAPI for Satellite","version":"3.3.1+ra","api_version":"v1",
 "generated":"2026-10-11T14:29:30Z","request_id":"req_fcfaf392ab6225ea","elapsed_ms":122,
 "consent":{…},"count":2,"total":2832,
 "source":{"id":"satnogs_satellites","label":"SatNOGS Satellites",
           "url":"https://db.satnogs.org/api/satellites/","attribution":"SatNOGS DB (libre.space)",
           "cache":"HIT","age_seconds":16114,"ttl":21600},
 "fields":["sat_id","norad_cat_id","name","names","status","launched","operator","countries","updated"],
 "pagination":{"page":1,"per_page":2,"total":2832,"total_pages":1416,"has_more":true,
               "next":"/v1/satellites?per_page=2&page=2"}},
 "data":[{"sat_id":"SCHX-0895-2361-9925-0309","norad_cat_id":965,"name":"TRANSIT 5B-5", …}]}
```

| 状态码 | 条件 |
|---|---|
| 200 | 正常 |
| 400 | `format` 非 json / `fields` 非法或 >30 / `since` 非法 / `envelope` 未知 |
| 503 | 上游不可用**且**没有可用缓存 → `code: upstream_unavailable`，`Retry-After: 120` |
| — | 维护模式 → 503 `code: maintenance`（见 5.6） |

### 3.4 `GET /v1/transmitters` — 发射机目录

参数同 3.3。`search` 的范围是 `description`、`mode`、`type`、`uuid`（`Catalog.php:43`）。
**默认投影 11 字段**：`uuid, norad_cat_id, description, mode, baud, type, status, alive, uplink_low, downlink_low, updated`。

> 上游这份文档约 4 MB，所以 `/v1` 只给**投影 + 分页**视图，而旧路径 `/satnogs/transmitters`
> 保留完整文档（`Catalog.php:17-19`）。

**实测** `?norad_cat_id=25544&fields=uuid,description,uplink_low,downlink_low&per_page=2` → 200，
`meta.fields` 回显所请求的 4 个字段。非法 `since` → 400（见 [0.1](#01-两个信封一个错误格式) 的实测体）。

### 3.5 `GET /v1/tle` — 合并 TLE 源

| 参数 | 说明 |
|---|---|
| `group` | `all`（默认）· `weather` · `amateur` · `amsat` · `satnogs`。非 `all` 时**转发**到单源处理 |
| `norad` | 逗号分隔的 NORAD 编号，只保留这些；**全不匹配 → 404** `not_found` |
| `limit` | 0..20000，`0` = 不限 |
| `format` | `tle`（默认，`text/plain`）· `json` |
| `json` | **推荐的 JSON 开关**：`json=true` → JSON 信封；`json=false` → 强制 TLE 文本。**优先级高于 `format` 与 `Accept`**；不设时默认就是 TLE 文本 |
| `meta` / `include_meta` | 抑制注释头 |
| `envelope` `pretty` | 仅 JSON 输出时有意义（`json=true` 或 `format=json`） |

**实测**（`?limit=2`，`Content-Type: text/plain; charset=utf-8`）——正文就是**注释头 + 记录**：

```
# AzureOpenAPI for Satellite v3.3.1+ra
# Generated: 2026-10-11T10:01:01Z
# Satellites: 1557
# Cache TTL: 10800s
# Source weather   CelesTrak Weather         72 sats  cache=HIT
# Source amateur   CelesTrak Amateur         95 sats  cache=HIT
# Source amsat     AMSAT Daily TLE           97 sats  cache=HIT
# Source satnogs   SatNOGS DB TLE          1500 sats  cache=HIT
# Docs: /docs.html
#
OPS 6582 (TRANSIT 5B-5)
1 28054U 03048A   26283.96075493  .00000064  00000-0  56753-4 0  9998
2 28054  98.9833 308.5266 0007213 171.1981 254.0963 14.14494462185857
```

渲染规则（`lib/Tle/Parser.php:241-251`）：每条注释前加 `# `，注释块之后是**单独一行 `#`**，
然后是「名称 / line1 / line2」三行一组。`?meta=0` 时整块注释消失（含最后那行 `#`）。

| 状态码 | 条件 |
|---|---|
| 200 | 正常 |
| 400 | `group` 未知 / `format` 非 tle·json |
| 404 | `norad` 里的编号一个都不在数据里 |
| 503 | 所有上游都失败且无缓存 |

### 3.6 `GET /v1/tle/{source}` — 单源 TLE

`source` ∈ `weather` · `amateur` · `amsat` · `satnogs`。参数：`norad` `limit` `format` `meta` `envelope` `pretty`。

**实测**（`/v1/tle/weather?limit=1`）注释头与合并版不同：

```
# AzureOpenAPI for Satellite v3.3.1+ra - source: CelesTrak Weather
# Generated: 2026-10-11T10:00:30Z
# Satellites: 72
# Cache: HIT (ttl 21600s)
# Docs: /docs.html
#
DMSP 5D-3 F16 (USA 172)
1 28054U 03048A …
2 28054  98.9833 …
```

`source` 不在四值之内时**根本不会匹配路由**——`/v1/tle/notasource` 实测得到的是
web 服务器的静态 404 HTML（见 [6.3](#63--未知路径返回-html-而不是-problem-文档)）。

### 3.7 `GET /v1/amsat/status` — AMSAT 状态报告

| 参数 | 说明 |
|---|---|
| `satellite` `callsign` `status` | 逗号分隔列表，精确匹配 |
| `since` / `until` | ISO-8601 或 Unix；非法 → 400 |
| `page` `per_page` `all` | 同上（`per_page` 上限 1000） |
| `envelope` `pretty` | 同上 |

行字段：`status, callsign, grid, satellite, norad_id, timestamp, timestamp_unix`（`AmsatStatus.php:107-115`）。
TTL `3600`——**实测**该端点当时是 `x-cache-status: STALE`，`cache-control: max-age=30`
（陈旧数据的共享缓存寿命按 `Base.php:161-166` 降为 30 秒）。

### 3.8 `GET /v1/meta` — 自述文档

无参数。`Cache-Control: public, max-age=300, s-maxage=600` + `ETag`。

**实测** `meta.service = "AzureOpenAPI for Satellite"`、`version = "3.3.1+ra"`、`count: 7`（7 个数据源）。

`data` 的键：`service` `endpoints` `legacy_routes` `sources` `limits` `errors` `cache_backend` `maintenance` `links`。

⚠️ **`legacy_routes` 是字符串 id 数组**，不是对象（`System.php:128` 用 `array_keys()`）。
`endpoints` 是 `{path, methods, id}` 数组（`Router.php:424-427`）。

### 3.9 `GET /v1/health` · `/v1/health/live` · `/v1/health/ready`

- `/v1/health`：聚合健康。`?ui=1` **或**「浏览器且不要 JSON」时渲染 HTML 面板；否则 JSON。
  `overall=critical` → **503**，否则 200；带 `X-Health` 头，`Cache-Control: no-store`。
  **实测**：`overall: degraded` → HTTP **200** + `x-health: degraded`。
- `/v1/health/live`：**实测** `{"status":"ok","check":"liveness","timestamp":"…","uptime_seconds":1834267}`。
- `/v1/health/ready`：**实测** `{"status":"ok","check":"readiness",…,"overall":"degraded","redis":"connected","caches":{…}}`。
  **注意 `overall=degraded` 时它仍然返回 200**——就绪判定是 `in_array($overall, ['ok','degraded'])`（`System.php:59`），
  只有 `critical` 才是 503。

### 3.10 `GET /v1/status` — HTML 运维面板

无参数。**实测** `200 text/html`。

### 3.11 `GET /v1/maintenance` 与 `POST /v1/maintenance`

**GET** → `{meta, data}`，`data` 是 9 个端点的状态数组：

```jsonc
{"endpoint":"tle_merge","enabled":false,"message":"","source":""}
```

> ⚠️ `enabled` 的含义是「**维护已开启**」（`status !== null`），不是「端点可用」（`Maintenance.php:138-142`）。

**POST**（需要 Bearer token）：

| 字段 | 类型 | 默认 | 约束 |
|---|---|---|---|
| `endpoint` | string | `""` = **全部** | 必须是 `Maintenance::ENDPOINTS` 之一，否则 400 |
| `enabled` | bool | `true` | `filter_var(…, FILTER_VALIDATE_BOOLEAN)` |
| `message` | string | `""` → 用默认文案 | |
| `ttl` | int | `3600` | **60..86400**，否则 400 |

`Maintenance::ENDPOINTS`（`Maintenance.php:23-33`，共 **9** 个）：
`tle_merge` `tle_weather` `tle_amateur` `tle_amsat` `tle_satnogs` `satnogs_satellites`
`satnogs_transmitters` `amsat_status` `health`

成功 → `200 {meta, data:{endpoints, enabled, ttl, until, state}}`。

| 状态码 | 条件 |
|---|---|
| 401 | 未提供/错误的 token（带 `WWW-Authenticate: Bearer`）——**实测** |
| 400 | `endpoint` 未知 / `ttl` 越界 |
| 503 | **没有配置任何 admin token**（`System.php:174-176`） |

被置为维护的端点，请求它会得到（`Maintenance.php:92-127`）：**503** + `code: maintenance` +
`Retry-After`（有 TTL 则剩余秒数，否则 120）+ `Cache-Control: no-store` + **`X-Maintenance: <id>`**；
若是浏览器且不要 JSON，则返回 HTML 维护页。

---

## 4. 旧路径（deprecated）

全部带 `Deprecation: true` 与 `Link: <后继>; rel="successor-version"`（**实测**见 [1](#1-路由总览)）。

| 路径 | 与 `/v1` 的实质差别 |
|---|---|
| `/satnogs/satellites`、`/satnogs/transmitters` | **返回原始裸数组**（`Catalog.php:85-88`），带上游全部字段（**实测**含 `image`、`telemetries`、`citation`、`decayed` 等 `/v1` 投影里没有的字段）。**不分页、不接受任何过滤参数** |
| `/amsat/status` | 信封是 `{status:"success", generated:"Y-m-d H:i:s UTC", total_reports, data:[…]}`，时间字段名 `timestamp`（`Y-m-d H:i:s`），没有 `timestamp_unix`（`AmsatStatus.php:54-73`） |
| `/tle`、`/tle/{source}` | 注释头换成旧格式；`url`-预取失败时会插入 `Remote source unavailable, serving cached data (generated …)` |
| `/health`、`/status` | 与 `/v1` 相同 |

**旧路径不受 `fields`/`page`/`per_page`/`envelope` 影响**——它们走的是「原样返回」分支。

---

## 5. 全局约定

### 5.1 两种信封的精确形状

```jsonc
// full（= 默认；Base::envelope）
{ "meta": {…}, "data": <array> }

// none（Base::dataOnlyEnvelope）
{ "data": <array>, "generated": …, "count": … [, "total": …] }
```
`count` **总是**被重算为实际返回的行数（`Base.php:83`），所以即使调用方忘了设也错不了。

### 5.2 错误码全表（11 个）

来自 `Problem::catalog()`（`Problem.php:133-150`），也由 `/v1/meta → data.errors` 公开：

| code | 典型状态码 | 含义 |
|---|---|---|
| `bad_request` | 400 | 参数非法或互相矛盾 |
| `not_found` | 404 | 未知资源标识 |
| `method_not_allowed` | 405 | 该路径不支持该方法（带 `Allow` 头） |
| `maintenance` | 503 | 运营方临时关闭该端点 |
| `upstream_unavailable` | 503 | 上游全失败且无可用缓存 |
| `rate_limited` | 429 | 超出请求预算 |
| `payload_too_large` | 413 | 请求体超限 |
| `unauthorized` | 401 / 503 | 缺少或错误的 admin 凭证 |
| `consent_required` | **451** | 未声明同意（仅在强制模式下） |
| `pow_failed` | 403 | PoW token 缺失/无效/过期 |
| `internal_error` | 500 | 未预期的服务端失败 |

`type` 的构造：`https://satellite.api.authskin.xyz/errors/` + `code` 里的 `_` 换成 `-`
（`Problem.php:17,47`）。**实测** `bad_request` → `/errors/bad-request`。

### 5.3 响应头（完整清单）

| 头 | 何时 | 值 |
|---|---|---|
| `X-Service-Version` | 总是 | `3.3.1+ra` |
| `X-Request-Id` | 总是 | 入站 `X-Request-Id`（若合法）否则新生成 |
| `Content-Type` | 总是 | `application/json` / `application/problem+json` / `text/plain` / `text/html` |
| `Cache-Control` | 总是 | 数据端点见 0.3；无显式设置时 2xx = `public, max-age=60, s-maxage=300, stale-while-revalidate=600, stale-if-error=86400`，**≥400 = `no-store, no-cache, must-revalidate`** |
| `Vary` | 总是 | `Accept, Accept-Encoding`（+`envelope`，+`Origin` 若回显来源） |
| `ETag` | 多数数据端点 | 见 6.1 |
| `Last-Modified` | 有缓存戳时 | **HTTP 日期格式**（`Response.php:133` 会把时间戳转成 `D, d M Y H:i:s GMT`） |
| `X-Cache-Status` `X-Cache-Age` `X-Cache-TTL` `X-Cache-Backend` | 数据端点 | 见 0.3 |
| `X-Health` | health 端点 | `ok` / `degraded` / `critical` |
| `X-Maintenance` | 维护模式 | 端点 id |
| `Retry-After` | 503 / 429 | 秒数 |
| `X-Consent-Required` | 未声明同意时 | `privacy,terms;version=1;state=missing` |
| `X-RateLimit-Limit` `-Remaining` `-Reset` | **仅 429 响应** | 见 6.7 |
| `Deprecation` `Link` `Sunset` | 旧路径且调用了 `cacheHeaders()` | 见 [1](#1-路由总览) |
| `Access-Control-*` | 总是 | 见 0.5 |
| `Content-Encoding: gzip` | `Accept-Encoding: gzip` 且 body ≥1024 字节 | `compression.enabled` 默认 true |

### 5.4 条件请求

`Response::emit()`（`:112-144`）支持 `If-None-Match` 与 `If-Modified-Since`，命中返回 **304**
（清空 body 与 `Content-Type`）：

- **只在 `status === 200` 时评估**——错误响应从不返回 304。
- `If-None-Match` 支持 `*`、`W/` 前缀、逗号列表（`:344-364`）。
- **响应没有显式 `ETag` 时，会对 body 求 md5 作为 ETag**（`:115-117`）。

### 5.5 同意（consent）三态

| 模式 | 未声明的请求得到 |
|---|---|
| `consent.required=false`（**当前默认**） | 正常数据 + `meta.consent` + `X-Consent-Required` 头。**不阻断** |
| `consent.required=true` | **451** + `code: consent_required` + `consent` 对象 + `Cache-Control: no-store` |
| 声明格式非法**且**强制模式 | **400** `bad_request`，额外字段 `parameter: consent`、`allow: ["privacy","terms"]` |

**豁免路径**（`Config.php:195-201`，永不需要声明）：`/v1/health`、`/v1/health/live`、`/v1/health/ready`、
`/v1/status`、`/v1/meta`、`/v1/consent/status`、`/v1/consent/verify-pow`、`/docs.html`、
`/openapi.yaml`、`/legal/{privacy,terms,index}.html`。
（这是刻意的：客户端不能被要求声明一份它被禁止阅读的文件。）

### 5.6 维护模式

见 3.11。要点：9 个可控端点；redis key `maintenance:<id>`（带 TTL，下限 60 秒）；
**文件兜底永不自动过期**（`Maintenance.php:73-79`，`until: 0`）。

### 5.7 限流

- 三个桶：`tle`、`catalog`、`amsat`（`Router.php:179-192`）。
- 默认 **关闭**（`ratelimit.enabled=false`），`per_minute=120`，`trust_proxy=true`（取 `X-Forwarded-For` 第一段）。
- 超限 → **429** + `code: rate_limited` + 额外 `rate_limit: {limit, remaining, reset}` + `Retry-After`。

### 5.8 CORS

`cors.enabled` 默认 true，`allow_origin` 默认 `*`。**实测**响应头：

```
access-control-allow-origin: *
access-control-allow-methods: GET, HEAD, OPTIONS
access-control-allow-headers: Content-Type, Accept, If-None-Match, If-Modified-Since, X-Requested-With, Authorization
access-control-expose-headers: ETag, X-Request-Id, X-Cache-Status, X-Cache-Age, X-RateLimit-Limit,
                               X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, Deprecation, Sunset, Link
access-control-max-age: 86400
```

**`OPTIONS` 在同意门禁之前被处理**，直接返回 **204** + 空 body（`Router.php:36-38`，**实测**注释头都不带）。

---

## 6. 实测发现（9 条）

前四条是**接口语义**上的坑，后五条是**代码或文档与线上不一致**的地方。全部有实测或源码行号支撑。

### 6.1 ★ ETag 不覆盖 `meta.consent`，于是不同 body 共享同一个 ETag

`Base::etag()` 只对**数据**（+ 缓存戳 + 分页 + envelope 形状）求哈希，`meta` 里的
`consent` / `request_id` / `elapsed_ms` / `generated` 都不参与（`Base.php:193-196`；
`Catalog.php:163-170`；`Base.php:127-129`）。

**实测**：`/v1/satellites?per_page=23` 两次请求，**同一个 ETag `0c8ad13f59f846ab88330d892f3de7f6`**，
但 body 一个是 **6137 字节**（带 `meta.consent`），另一个是 **5901 字节**（不带）。

后果：带 `If-None-Match` 的客户端可能在表示已经变化时收到 **304**，
从而**永远看不到同意声明**（或看不到它已经消失）。`request_id`/`elapsed_ms` 被排除是**有意的**
（文档写明了「稳定校验子」的意图），但 `consent` 是后来加入的、会改变表示内容的字段，
被排除属于**遗漏而非设计**。

### 6.2 ★ 同意状态受缓存影响：未声明的调用方可能什么都看不到

同参数、不同同意声明的请求，实测返回**逐字节相同**的 body：

| 请求 | ETag | `X-Consent-Required` | `meta.consent` | 字节 |
|---|---|---|---|---|
| `?per_page=17&consent=privacy,terms&consent_version=1` | `bc9921b4…` | 无 | **无** | 4531 |
| `?per_page=17`（**未声明**） | `bc9921b4…` | 无 | **无** | 4531 |
| `?per_page=23`（未声明） | `0c8ad13f…` | `privacy,terms;version=1;state=missing` | **有** | 6137 |
| `?per_page=23&consent=…` | `0c8ad13f…` | 无 | 无 | 5901 |

第一行**先**发出（已声明），第二行**未声明**却拿到了完全相同的响应 —— 它**没有**被告知需要声明。
而第三行（同样是未声明，只是 `per_page` 不同）**收到**了声明。

结论：**`consent*` 不在缓存键里**（`per_page` 在），所以 `meta.consent` / `X-Consent-Required`
描述的是**填充该缓存条目的那次请求**，不是调用方。

**如果 `consent.required` 被打开**，一个已经预热的缓存条目在结构上就可能把 451 挡在外面——
**这一条我没有实测**（打开强制模式会破坏现存集成，属于运营方决定），
它是从上表这个已实测的缓存行为推出来的，不是观测到的 451 绕过。

### 6.3 ★ 未知路径返回 **HTML**，不是 problem 文档

> 这一条 `.ai-memory/handoff.md:173` 已作为**已知限制**记录在案，本文是**验证**它，不是发现它：
> 「宝塔 vhost 的 `error_page 404 /404.html` 会替换应用为「未知路径」生成的 problem+json（路由命中的错误不受影响）。」

**实测**：`/v1/nope` 与 `/v1/tle/notasource` 都返回 `404` + **`content-type: text/html`**
（带 `robots: noindex` 的样式化错误页，`ETag` 是静态文件的 `"6ac672d8-1bc4"`，且**没有** `X-Service-Version`/`X-Request-Id`）。

**本文补充的一点**：**加 `Accept: application/json` 也还是 HTML** ——
即这不是内容协商问题，响应**根本没有经过应用**（是 web 服务器的兜底 location 在处理，
见 `conf/bt-panel-rewrite.conf:205-217`）。`Router.php:69-70` 里那个会答
`{"code":"not_found","detail":"No route matches … See /v1/meta …"}` 的 404 在生产环境**不可达**。

对调用方的实际影响：**不能假设所有 404 都是 JSON**。路径拼错时你拿到的是 HTML。
（404 作为 problem+json 仍然可达，例如 `/v1/tle?norad=…` 全部不匹配时由端点自己发出的那个。）

### 6.4 `list()` 不接受重复参数

`?norad=1&norad=2` 会被 `parse_str` 解析成数组，而 `param()` 遇到数组就返回默认值 ⇒
**得到一个空列表**，而不是「两个编号」。只有 `?norad=1,2` 有效。同理 `?norad_cat_id`、`?uuid`、`?sat_id`、
`?callsign`、`?status`、`?satellite`。（`Request.php:160-217`）

### 6.5 `openapi.yaml` 完全没有同意/PoW 子系统

仓库里已有一份 `openapi.yaml`（**2188 行 / 115 KB**，`openapi: 3.1.0`）。逐项比对后：

| 检索词 | 在 openapi.yaml 里的出现次数 |
|---|---|
| `consent` / `X-Consent` / `consent_required` / `pow_failed` | **0 / 0 / 0 / 0** |
| `envelope` / `fields` / `since` / `sort` / `maintenance` / `norad_cat_id` / `until` / `format` | 42 / 7 / 4 / 4 / 87 / 20 / 12 / 19 |
| `/v1/consent/status`、`/v1/consent/verify-pow` | **两个路径都不存在** |

即：那份规格覆盖了其余一切，**唯独漏掉了整个 consent + PoW 子系统**——
而它改变了请求参数（`consent`/`consent_version`/`X-Consent`）、响应信封（`meta.consent`）、
响应头（`X-Consent-Required`）、新增两条路径，以及一个新状态码（451）。

**为什么会漏，`.ai-memory/handoff.md:139-147` 自己写着**：

```
| W2 | ?envelope=none 契约（Request::envelope() + Base::dataOnlyEnvelope() + 4 端点 + OpenAPI + 测试） | pending |
| W3 | 同意体系（lib/Support/Consent.php + Router 中间件 + 451 + meta.consent + /v1/consent/status） | pending |
| W4 | Cap PoW（… + verify-pow 端点 + 自托管 widget） | pending |
```

- **W2 的交付清单里明确列了 `OpenAPI`** —— 所以信封系统进了规格（`envelope` 出现 42 次）。
- **W3 的清单里没有 `OpenAPI`** —— 所以同意体系没进规格，一条都没有。
- W4 的 `verify-pow` 端点同样不在规格里。

⚠️ 顺带：这张表的 **`pending` 已经过时了**。W2、W3、W4 的代码**都已上线**——
本文的实测就是在验证这一点（`envelope=none` 生效、`/v1/consent/status` 返回真实数据、
`meta.consent` 与 `X-Consent-Required` 都在、`/v1/consent/verify-pow` 已注册、`pow.enabled: true` 且带真实 `site_key`）。
**记忆与实际状态相反，是比规格缺字段更值得先修的那件事。**

**建议**：把本文的 [0.4](#04-同意consent与工作量证明pow) 与 [5.5](#55-同意consent-三态) 补进 `openapi.yaml`，
并把那张 Wave 表的状态改成实际值。否则两份文档会开始互相漂移——而这次漂移的方向已经知道了：
**代码先走，规格留在原地。**

### 6.6 451 没有 reason phrase

`Response::reason()` 的映射表里**没有 451**（`Response.php:405-420`），
兜底返回字符串 `'Status'` ⇒ 状态行实际是 `HTTP/1.1 451 Status`。
纯装饰性问题，但会出现在任何抓包、日志或严格解析状态行的客户端里。

### 6.7 `X-RateLimit-*` 只在 429 时出现

`RateLimit::headers()` 在 `lib/` 下**只有一个调用点**——`Router.php:192`，即 429 分支。
所以**任何 2xx 响应都不告诉客户端剩余配额**（**实测**：200 响应里没有 `X-RateLimit-*`），
而 `Access-Control-Expose-Headers` 却把这三个头列出来给了浏览器。
限流当前还是关闭的，所以这一点暂时无害。

### 6.8 文件缓存目录对 web 用户不可写

`/v1/health` 的 `checks.storage` 实测为：

```json
{"backend":"redis+file","cache_dir":"/www/wwwroot/satellite.api.authskin.xyz/data/cache",
 "cache_dir_writable":false,"redis_ext":true}
```

`backend` 是 `redis+file`、`cache_dir_writable: false`：**文件后端实际写不进去**，
数据全靠 Redis。Redis 一旦不可用，`/v1/health/ready` 会转 503（`overall` 变 `critical`）。
这属于运维观察，不是接口契约，但它是 `/v1/health` 暴露出来、值得看一眼的信号。

### 6.9 旧路径 `/satnogs/*` 返回裸数组，`/v1` 的客户端代码不能复用

**实测** `/satnogs/satellites?per_page=1` 的 body 以 `[{"sat_id":…}]` 开头——
顶层是数组，没有 `meta`，**而且 `per_page` 被忽略**（`Catalog.php:85-88` 直接
`Response::json(array_values($result['data']))`）。任何按 `{meta,data}` 解析的通用客户端
在旧路径上都会失败。这正是 `envelope=none` 存在的理由，但两者并不等价：
`envelope=none` 仍带 `generated`/`count`/`total`。

---

## 7. 未验证与已知限制

| 项 | 状态 | 原因 |
|---|---|---|
| 强制模式（`consent.required=1`）下的 **451** 与 **400** | **未实测** | 打开它会立即破坏现存集成，属运营方决定。代码路径见 `Router.php:129-137` |
| **PoW 的完整往返**（`POST /v1/consent/verify-pow` 成功态） | **未实测** | 需要一个真实 Cap token；失败态（403 `pow_failed`）由代码与 `Consent.php:59-70` 确定 |
| **维护模式**的 503 响应 | **未实测** | 会把线上端点关掉。字段与头由 `Maintenance.php:92-127` 确定 |
| **限流 429** 的真实响应 | **未实测** | 当前 `ratelimit.enabled=false` |
| `/v1/tle/{source}` 四源的**全部**实际数据 | 部分实测 | 只实测了 `weather`；其余三源的注释头格式由 `Tle.php:184-189` 与 `Parser` 确定 |
| `/v1/health?ui=1` 的 HTML 面板、`/v1/status`、维护页、404 页的**视觉呈现** | 未验证 | 需要人眼或截图 |
| `payload_too_large`（413）、`internal_error`（500） | 未实测 | 前者需构造超限请求体（`http.max_bytes`），后者需制造服务端故障 |
| 1 个 `pages` 级别的形态：`meta.pagination.next` 的**相对**路径在 CDN 后是否可用 | 未验证 | 它是 `/v1/satellites?…` 这种站内相对路径（`Catalog.php:207-214`） |

**本文的验证方式**：所有 `/v1` 只读端点、旧路径、错误路径、CORS/缓存/条件请求头，
都在 `https://satellite.api.authskin.xyz` 上用真实 HTTP 请求跑过（从 maqsl 所在主机发出，
因为本文作者所在网络的代理无法到达该域名），标「实测」的输出是原样复制的。
参数语义、状态码、头部构造、维护清单、TTL、错误码表全部逐条取自
`lib/Api/Router.php`、`lib/Api/Endpoints/{Base,Catalog,Tle,AmsatStatus,System,Consent}.php`、
`lib/Http/{Request,Response}.php`、`lib/Support/{Problem,Consent,Maintenance,Legacy,RateLimit}.php`、
`lib/Data/Repository.php`、`lib/Config.php` 的实际代码行，行号已给出。

---

## 8. 双地址 / 预处理 / 热重载 / 预应答（2026-10-28 新增）

本章描述 2026-10-28 之后的实际行为。**每条都在线上用真实请求验证过**，验证方式写在每节末尾。

### 8.1 两个地址，一个应用

`lib/Config.php` 的 `endpoints.registry` 是唯一事实来源：

| id | host | CDN | 边缘缓存头 | status | testing | 顶部提示 |
|---|---|---|---|---|---|---|
| `primary` | `satellite.api.authskin.xyz` | `edgeone` | `eo-cache-status` | `stable` | `false` | `info`：EdgeOne 常态加速 |
| `scdn` | `satellite.db.authskin.xyz` | `scdn` | `x-cache-status` | `new` | `true` | `warning`：新地址测试中 |
| `origin` | `satellite.authskin.xyz` | `none` | —（无边缘） | `stable` | `false` | `warning`：直连，负载高时可能不可用 |

三者回源到**同一份**代码（同一个 `root`、同一套路由、同一套限流与缓存），差别只在边缘。

> **`origin` 是 2026-10-28 晚补上的**。在此之前 `satellite.authskin.xyz` 不在注册表里，
> 会落到 `default`（`primary`），于是**一个直连的访客会被告知自己在 EdgeOne 上** —— 而它前面
> 根本没有边缘。它的 `cdn` 是 `none`、`cdn_header` 为空，模板正是用"空 `cdn_header`"来决定
> 不渲染边缘缓存那一格（否则两个破折号是在假装信息）。

**`/v1/meta` 新增 `data.endpoint`**，让客户端知道"我刚才是跟哪个地址说话的"：

```jsonc
"endpoint": {
  "id": "scdn",
  "host": "satellite.db.authskin.xyz",
  "url": "https://satellite.db.authskin.xyz",
  "cdn": "scdn",
  "cdn_header": "x-cache-status",   // 该边缘把自己的缓存判定写进哪个响应头
  "status": "new",
  "testing": true,
  "since": "2026-10-28"
}
```

`primary` 返回 `testing: false`、`status: "stable"`，且不带 `since`。

**页面标记**：**三个地址的每一页都有一条顶部提示条**（`<div class="enote" data-endpoint-note="<id>"
data-level="info|warning">`），文字按地址不同、三种语言各写一份（`conf/i18n.php` 的
`endpoint.notice.*` / `endpoint.badge.*`）：

| 地址 | 徽标 | 提示（zh-Hans） |
|---|---|---|
| `origin` | 直连源站 | 当前直接连接主代理服务器，未经过任何加速线路；服务器负载较高时可能出现服务暂不可用。 |
| `primary` | EdgeOne 加速 | 当前连接经常态加速线路代理（EdgeOne），由于边缘节点同步存在延迟，可能出现数据略有滞后。 |
| `scdn` | 新地址 · 测试中 | 此地址使用全新的加速线路，目前仍在测试与观察中，行为可能随时调整。 |

`data-level` 有实际含义，不是装饰：`warning` = **访客可能失去服务**（直连、全新线路），
`info` = **风险只是数据可能略旧**（EdgeOne）。两者用同一套颜色会让人学会忽略这条提示。

**静态生成的 `error.html` 不带提示条**，因为它是**地址无关**的构建产物。这一点是踩出来的：
错误页由 CLI 生成，CLI 请求没有 `Host` 头，注册表于是回退到 `default`（`primary`），
把 **EdgeOne 那句话烤进了所有域名共用的 404 页** —— 实测 error.html 从 7093 字节涨到 8944。
现在模板的规则是"**没有 Host 头就没有提示条**"：一个静态页没有地址，就不该声称自己有。

> 实测（源站，正确带 `Host:`）：`origin` → `data-endpoint-note="origin" data-level="warning"`、
> 边缘缓存格=0；`primary` → `primary/info`、边缘缓存格=1；`scdn` → `scdn/warning`、边缘缓存格=1。
> 三个地址上 `/`、`/docs`、`/privacy`、`/terms`、`/v1/meta` 全部 200；`/zzz-nope` 三者皆 404 +
> 7093 字节且提示条=0。

### 8.2 缓存头：`X-Cache-Status` 会被 SCDN 覆盖，所以源站另起一个名字

这是本次修复的核心。"缓存模式显示错了"的真实原因不是显示层，而是**头名冲突**：

```http
# 实测：https://satellite.db.authskin.xyz/v1/tle?format=json&limit=1
X-Cache-Status: MISS from L1:6518    ← 这一行是 SCDN 的，源站判定被覆盖
X-Cache-Age: 5541                    ← 源站的
X-Cache-TTL: 10800                   ← 源站的
X-Cache-Backend: redis+file          ← 源站的
```

四个头描述同一个缓存、其中两个互相矛盾：源站说 HIT（age 5541 < ttl 10800），SCDN 说 MISS。
EdgeOne 不受影响，因为它用自己的 `EO-Cache-Status`，不碰 `X-Cache-Status`。

**现在每个缓存型响应同时带两套名字**（`lib/Api/Endpoints/Base.php`）：

| 头 | 含义 | 谁写 |
|---|---|---|
| `X-Cache-Status` | 源站判定（**可能被 SCDN 覆盖**，保留仅为兼容） | 源站 → 可能被边缘改写 |
| `X-Cache-Age` / `X-Cache-TTL` / `X-Cache-Backend` | 源站年龄 / 存活期 / 后端 | 源站 |
| `X-Origin-Cache-Status` | 源站判定（**无 CDN 会用这个名字**） | 源站，始终可靠 |
| `X-Origin-Cache-Age` / `-TTL` / `-Backend` | 同上 | 源站 |
| `EO-Cache-Status` | EdgeOne 边缘判定 | EdgeOne |
| `X-Cache-Status: … from L1:NNNN` | SCDN 边缘判定（带 `from L1:` 后缀即为边缘） | SCDN |

**客户端读取顺序**：边缘判定优先读 `meta.endpoint.cdn_header` 指定的那个头；源站判定永远读
`X-Origin-Cache-Status`。`app.js` 的 `edge` 模块就是这么实现的，且它**只发一次
`HEAD /v1/tle`**，把两个判定填进提示条的 `[data-edge-cache]` / `[data-origin-cache]`。

CORS 暴露头同步扩展（`cors.expose_headers`），否则跨域 `fetch()` 读不到这些值：
新增 `EO-Cache-Status`、`X-Origin-Cache-Status`、`X-Origin-Cache-Age`、`X-Origin-Cache-TTL`、
`X-Origin-Cache-Backend`、`X-Cache-TTL`、`X-Cache-Backend`、`Age`。

> 实测：同一次 `/v1/tle` 响应里 `x-cache-status: HIT` 与 `x-origin-cache-status: HIT` 同时出现。

### 8.3 信息预处理：字段级清洗与补全

`lib/Data/Prepared.php`。不再是「抓回来直接拼」，而是清洗、补全、去重、排序后落盘成快照，
请求路径直接读快照。

**清洗规则**（每条都独立计数，计数在 `/v1/tle` 的 `meta`、热重载报告和 CLI 输出里可见）：

| 计数键 | 含义 |
|---|---|
| `input_records` | 进入管道的记录数 |
| `dropped_invalid` | 两行不是一对合法 TLE / NORAD 不匹配被丢弃 |
| `dropped_norad` | 补全后仍无编目号被丢弃 |
| `name_normalised` | 去掉 3LE 前缀、合并空白 |
| `norad_completed` | 由名称经 `norad_fallback` 精确匹配补出编目号 |
| `checksum_failed` / `checksum_verified` | 校验位真伪计数 |
| `duplicates_merged` | 同一编目号多源合并 |
| `epoch_missing` | 历元不可解析 |
| `orbit_incomplete` | 轨道要素有字段缺失（缺失即省略，不补零） |
| `capped` | 超过 `preprocess.max_records` 被截断 |

**校验位只验不改**。重算末尾数字会把「数据损坏」洗成「数据正常」，那是预处理绝不该做的事。
损坏的记录照样返回（那是上游数据，不是我们造的），但 `checksum_ok=false`，并把应有的数字放在
`checksum_expected: {line1, line2}` 里。

**`/v1/tle?format=json` 的每条记录新增两个字段**（附加字段，旧客户端忽略即可）：

```jsonc
{
  "norad_id": "06582",
  "name": "OPS 6582 (TRANSIT 5B-5)",
  "epoch": "2026-10-28T03:14:11Z",
  "epoch_unix": 1793…,
  "source": "satnogs",
  "tle": ["1 06582U …", "2 06582  …"],
  "checksum_ok": true,
  "orbit": {
    "inclination_deg": 90.0893,
    "raan_deg": 204.1669,
    "eccentricity": 0.003973,      // 行 2 省略的 "0." 已补上
    "argp_deg": 249.5294,
    "mean_anomaly_deg": 164.1866,
    "mean_motion": 13.57022891,
    "rev_number": 6159,
    "bstar": 8.5846e-5,            // 行 1 的 " 85846-4" 隐含小数点已换算
    "element_set": 999,
    "classification": "U"
  },
  "sources": ["satnogs"]           // 携带该编目号的全部上游
}
```

`orbit` 与 `sources` **只在存在时输出**，所以直接调用 `Parser::merge()` 的老路径输出逐字节不变。

**快照键随源站时间戳变化**：`prepared:tle:<hash(各源 stored_at)>`。不用「固定键 + TTL」，
因为四个 TLE 源的 TTL 是 10800/10800/43200/10800，没有任何单一 TTL 既能覆盖最慢的源、
又能在最快的源刷新时失效；用派生键则「源一刷新 → 键就变」，旧快照自然过期。

> 实测（一次全量热重载）：`preprocessed: 1769 in, 1562 out, 207 merged, 0 dropped,
> 0 completed, 0 checksum failures`。

### 8.4 热重载：重新向所有源拉取并覆盖

**两处入口，语义一致：忽略 TTL、覆盖旧缓存、重建预处理快照。**

```bash
# CLI
php index.php reload                 # 全部 7 个数据集
php index.php reload --target=all    # 同上
php index.php reload --target=amsat  # 只刷一个
php index.php reload --target=bogus  # 立刻失败，不做任何网络请求
php index.php reload --json          # 完整报告
```

```http
POST /v1/admin/reload
X-Admin-Token: <admin.token>
# 或 Authorization: Bearer <admin.token>
# 或 ?token=<admin.token>（应急，会进访问日志）
# 可选 ?targets=weather,amsat ；缺省或 all = 全部
```

与既有 `refresh` / `warmup` 的区别，两条：`refresh` **在缓存还新鲜时直接跳过**（定时任务要的），
`reload` 不看 TTL；`reload` 覆盖**全部 7 个**数据集，而 `refresh --target=all` 曾经只刷 4 个 TLE 源
（`satnogs_satellites` / `satnogs_transmitters` / `amsat_status` 三个数据集被静默漏掉 —— 这是个
长期存在的假承诺，本次一并修好）。

**响应**（`envelope=full`）：

```jsonc
{
  "meta": { "endpoint": {…}, … },
  "data": {
    "reload": {
      "ok": true,
      "started_at": "2026-10-28T11:53:18Z",
      "finished_at": "…",
      "elapsed_ms": 75745,
      "failed": 0,
      "datasets": {
        "weather":            { "ok": true, "count": 72,   "ms": 28749, "written": "MISS", "stored_at": … },
        "amateur":            { "ok": true, "count": 95,   "ms": 28639, "written": "MISS", … },
        "amsat":              { "ok": true, "count": 97,   "ms": 431,   "written": "MISS", … },
        "satnogs":            { "ok": true, "count": 1505, "ms": 2037,  "written": "MISS", … },
        "satnogs_satellites": { "ok": true, "count": 2841, "ms": 2011,  "written": "MISS", … },
        "satnogs_transmitters": { "ok": true, "count": 5123, "ms": 2455, "written": "MISS", … },
        "amsat_status":       { "ok": true, "count": 1118, "ms": 1727,  "written": "MISS", … }
      },
      "prepared": { "input_records": 1769, "duplicates_merged": 207, … },
      "snapshot": { "key": "prepared:tle:…", "built_at": …, "cache": {…} },
      "merged_count": 1562
    }
  }
}
```

`written` 是这轮的关键字段：`MISS` 表示 producer 真的跑了并覆盖了旧副本；`STALE`/`EXPIRED`
表示抓取失败、旧副本仍在 —— **不能当成功**。

**状态码**：`200` 全部成功；`503` 有数据集失败（body 里 `failed > 0`，`Cache-Control: no-store`）；
`409 reload_in_progress` 已有一次重载在跑（带 `Retry-After: 30`）；`401` token 错/缺；
`400` 未知 `targets`；**`404` 未配置 `admin.token`**（此时该端点在部署上不存在）。

**并发**：文件锁串行化。两次重叠的重载会让上游请求量翻倍而结果完全相同，CelesTrak 会因此封 IP ——
自我造成的重载风暴是丢掉唯一主源的典型方式。

**超时**：`reload.time_limit`（默认 180s）。超时截断会**显式报告** `time_limit_reached` 与
`skipped` 列表，不会假装成功。

> 实测：`php index.php reload --target=all` → 7/7 ok，`written=MISS`，75.7s，exit 0。
> `--target=bogus` → 立即 `FAIL: no valid target.`，0s，无网络请求。

### 8.5 预应答：`/v1/sw` 的 ServiceWorker

**给「浏览器直接打开 API 地址」的场景**：地址栏敲 `/v1/tle` 时立刻出现「数据正在载入，请等待数据
传输完成…」，数据到达后原地换成数据本体。

| 项 | 值 |
|---|---|
| worker URL | `GET /v1/sw`（`lib/ServiceWorker.php`，正文取自 `assets/sw.js`） |
| 响应头 | `Content-Type: application/javascript; charset=utf-8`、`Service-Worker-Allowed: /`、`Cache-Control: public, max-age=300`、`ETag`（条件请求 `304`） |
| 注册 | `assets/app.js` → `navigator.serviceWorker.register('/v1/sw', { scope: '/' })` |
| 拦截范围 | **仅 `request.mode === 'navigate'` 且路径属于 API**（`/v1/*`、`/tle*`、`/satnogs/*`、`/amsat/*`、`/health`、`/status`、`*.php` 旧名） |
| 不拦截 | 一切 `fetch`/XHR、curl、监控、CDN 探测、其它页面导航 |

**为什么是 `/v1/sw` 而不是 `/sw.js`**：本 vhost 的伪静态是**白名单**，只有
`/v1|tle|satnogs|amsat|health|status`、`/docs`、`/privacy|terms|legal|error|verify`、`/` 这些
会被送到 `index.php`，其余一律静态查找。实测 `/sw.js` 与 `/sw` 都是 nginx 直接 404、PHP 从未运行。
`/v1/sw` 落在已路由的前缀下，再由 `Service-Worker-Allowed: /` 把作用域放宽到根。

**为什么可以安全地长期开着**：`mode !== 'navigate'` 这一条把机器客户端完全隔离在外 ——
curl 的字节、响应头、耗时都不受任何影响。worker 也**从不缓存 API 响应**：每次都是实时
`fetch(cache: 'no-store')`，所以它不可能返回陈旧数据或掩盖 5xx。

**加载页额外做的事**：它就是唯一能读到「这次调用真实的边缘缓存头」的地方（同源 fetch 不受
CORS 隐藏），所以加载完成后会显示

```
地址: https://satellite.db.authskin.xyz/v1/tle
边缘缓存: EXPIRED          ← eo-cache-status 或 x-cache-status
源站缓存: HIT (age 6496s)  ← x-origin-cache-status
状态: 200 · 耗时: 812ms
```

> 实测：`/v1/sw` → `200 application/javascript`，`service-worker-allowed: /`，
> 正文与 `assets/sw.js` md5 一致，条件请求 → `304`；经 CDN 也是 `200`。

### 8.6 授权状态「记不住」的修复

**根因不是 CDN 不认 `Vary`，而是授权状态被烤进了可缓存 HTML。** `partials/shell.php` 曾把服务端
从 `Cookie` 头算出的结论写进 `window.__SATAPI_BOOT__`，而 `/` 是 `public, s-maxage=600`：

```
# 实测（EdgeOne，同一请求同一 Cookie，连续两次）
cookie_consent=accept_all  try1 → "hasConsentCookie":true    EO: MISS
cookie_consent=accept_all  try2 → "hasConsentCookie":false   EO: HIT
cookie_consent=reject_all  try1 → "hasConsentCookie":false   EO: HIT   ← 更糟
```

后果不是美观问题：被分到「已决定」变体的**首次访客根本看不到弹窗**，而真正决定过的访客**每页都被
再问一次**。

**修法**：可缓存文档必须对所有人**逐字节相同**，决定权交给 `document.cookie`（`assets/consent.js`
的 `consentChoice()`）。

| 位置 | 变化 |
|---|---|
| `partials/shell.php` | 删除 payload 里的 `cookies.choice` / `cookies.decided` / `cookies.version`，只保留配置派生的 `current_version` / `categories` / `lifetime_days`；`hasConsentCookie` 恒为 `null`；新增 `consentSource: "cookie"` |
| `assets/consent.js` | 新增 `consentChoice()`，从 `document.cookie` 解析 `accept_all|essential_only|reject_all` 与 `;v=`；弹窗判定、语言 Cookie 写入都改用它 |
| `lib/HomePage.php` | `Vary` 从 `Accept-Language, Cookie, Accept-Encoding` 改为 **`Accept-Language, Accept-Encoding`** |

`Vary` 去掉 `Cookie` 不只是「因为不需要」：它同时把边缘的对象数从「每种语言 × 每种 Cookie 组合」
压回到「每种语言一个」，这本身就是在消除变体串台的土壤。

> 实测（源站，同一 URL，三种 Cookie 状态 × 4 轮）：12 次请求里 11 次成功返回，
> **只有 1 个不同的正文 md5**；第 12 次是主机级 `EMFILE` 故障导致的空响应（见 §8.7）。
> 经 CDN 访问 `https://satellite.db.authskin.xyz/` → `"hasConsentCookie":null`、提示条=1。

### 8.7 主机级 EMFILE：已定位根因（2026-10-28 20:20 更新）

**症状**：PHP-FPM 返回 500 空响应，nginx 日志里是
`require(bootstrap.php): Failed to open stream: Too many open files` —— 连引导文件都打不开。

**根因不是本项目。** 这台机器的 PHP-FPM 是**一个共享池**（`/tmp/php-cgi-84.sock`；
`php-fpm.d/` 下只有 `www.conf.default`，实际池配置在主 conf 里），同一批 28 个 worker 同时服务
**6 个站点**：satellite、maqsl、qrzdb、logbook、qsl、www。其中
**`logbook.bi3cff.authskin.xyz` 每请求泄漏约 6.6 个 fd**：

```
50 个请求 → logbook.bi3cff.authskin.xyz   → 池内总 fd  +329   （≈6.6/请求）
50 个请求 → satellite.db.authskin.xyz     → 池内总 fd    ±0
50 个请求 → maqsl / qrzdb / qsl / www     → 池内总 fd    ±0
```

泄漏的是**事件循环的一整套句柄**：`io_uring` + `eventpoll` + `eventfd` + `socketpair`(2)
+ `/dev/null` = **7 个**。`1017 ≈ 9（进程初始）+ 145 × 7` —— 那个 worker 在约 145 个此类请求后
正好顶到 1024 的软上限，之后它收到的每个请求都 500。

本机 PHP 8.4 装载了 `swow.so`（`extension = swow.so`、`swow.enable=On`，基于 libcat / io_uring），
而本项目代码**没有任何一处**会创建这类句柄（全仓只有 `curl_init`、`fopen`、`new Redis`，均已配对关闭）。

**八组对照实验，本项目全部 0 增长**（800 次受控请求）：

| 实验 | 请求数 | fd 增长 |
|---|---|---|
| 16 字节探针（零应用代码） | 200 | 0 |
| `/v1/health`（真实代码，Redis + 文件 I/O） | 200 | +26（≈4 个运行时，噪声级） |
| `file_get_contents` / `fopen+flock` / Redis / cURL 各一组 | 各 100 | 全部 0 |
| `fastcgi_finish_request()` 前后做 I/O 的四种变体 | 各 100 | 全部 0 |
| `php -S` 跑真实应用（5 个端点，单进程可观察） | 50 | 0 |
| CLI 里 25 次 `Swow\Coroutine::run` | 25 | 0 |
| **logbook（对照，同机同池）** | 50 | **+329** |

**已做的处置（未改任何配置文件）**：对 fd > 300 的 7 个 worker 逐个 `kill -TERM`，
FPM 主进程随即重启它们（新 worker 从 5~9 个 fd 起步）。结果：

```
max_fds 1017 → 261        池内总 fd 5396 → 1765
清理后 EMFILE 归零（日志最后 8 条全在 20:14:01–20:14:16，即旧 worker 退出前的残余）
fds 分布：5×10, 9×5, 16×2, 30, 58, 73, 93×2, 177, 207, 213, 254, 256, 261
```

**这是止损，不是根治**：按 6.6 fd/请求，一个全新 worker 约 **154 个 logbook 请求**就会再次触顶。
根治三选一（都要动 `php-fpm` 配置或另一个站点，属于运维决策）：

1. **给 `pm.max_requests` 设一个值**（如 300）。这是"worker 缓慢泄漏"的标准解法：到寿命就优雅
   重启。一处改动、不需改 `php.ini`、也不必先找出到底谁在漏。
2. **给 satellite 单开一个 pool**（独立 socket + 独立 worker）。logbook 的泄漏再也碰不到本服务，
   且该 pool 可单独关掉 `swow`。代价是 vhost 的 `include enable-php-84.conf` 要指向新 socket。
3. **修 logbook**（真正的泄漏源）。

**看起来更省事但更糟的选项**：把 `rlimit_files` 从 1024 提到 65535。能立刻消掉 500，但那只是把
"耗尽 fd"换成"更晚耗尽"，而且日志里每小时一条
`child ... exited on signal 6 (SIGABRT - core dumped)` 说明这个扩展本身也不稳 —— 掩盖不会让它消失。


