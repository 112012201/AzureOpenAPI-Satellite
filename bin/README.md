# bin/ — 构建与运维脚本

| 文件 | 用途 | 何时运行 |
| --- | --- | --- |
| `warmup.sh` | 缓存预热 + 日志轮转 + 限流桶清理（`flock` 防重入，上游失败返回非 0） | cron，每 10 分钟（见 `DEPLOY.md`） |
| `build-favicon.js` | 由 `favicon-16x16.png` / `favicon-32x32.png` 生成根目录 `favicon.ico`（PNG-in-ICO，多尺寸） | 更换站点图标后，本地运行一次 |
| `build-icons.js` | 生成 `apple-touch-icon.png` / `icon-192.png` / `icon-512.png`（用无头 Edge/Chrome 渲染内联 SVG，无需 sharp/canvas 等依赖） | 更换品牌视觉后，本地运行一次 |

## 图标构建

```bash
# 需要 Node.js（仅用于读取 PNG 头 / 编排无头浏览器），无需 npm install。
node bin/build-favicon.js
node bin/build-icons.js          # Windows 自动探测 msedge.exe / chrome.exe
BROWSER=/usr/bin/chromium node bin/build-icons.js   # Linux 显式指定
```

生成结果直接写入站点根目录，随其余源码一起部署：

| 产物 | 尺寸 | 被谁引用 |
| --- | --- | --- |
| `favicon.ico` | 16 + 32 | `<link rel="alternate icon">`（三处页面）、浏览器默认 `/favicon.ico` 请求 |
| `favicon-16x16.png` / `favicon-32x32.png` | 16 / 32 | 同上（历史资源，保留） |
| `apple-touch-icon.png` | 180 | iOS 主屏 |
| `icon-192.png` / `icon-512.png` | 192 / 512 | `site.webmanifest`（`any` + `maskable` 双用途） |

### 为什么用无头浏览器而不是图像库

`icon-*.png` 需要矢量级的清晰度，把 32×32 位图放大到 512×512 会糊。
引入 `sharp` 或 `canvas` 会给这个零依赖项目增加原生编译负担，因此品牌图形以
内联 SVG 描述在 `build-icons.js` 里，交由无头浏览器栅格化后截图。
`maskable` 变体保留 40% 安全区，避免 Android 自适应图标裁切。

## 部署

部署与备份**不在本目录**：本项目的源码在本地维护、通过 SSH 推送到服务器
（服务器上没有 git 仓库）。推送脚本是仓库外的
`D:\DSH\work\push-deliverables.ps1`，职责为：

1. 路径白名单校验（拒绝推送未受管文件）；
2. 服务器侧 `cp` 生成 `.bak` 到 `/www/wwwroot/satbak/<相对路径>.bak`；
3. `scp` 上传后逐文件 sha256 校验，不一致立即自动回滚该文件。

改 nginx 配置（`conf/bt-panel-rewrite.conf` → 面板伪静态）另走
`DEPLOY.md` 中记录的步骤，同样先备份到 `/www/wwwroot/satbak/_nginx/`。
