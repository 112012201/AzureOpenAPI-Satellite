---
# 一条公告 = 一个 .md 文件。改完运行：php index.php announcements
# 字段：id / lang / title / date / author / level(urgent|important|normal|minor) /
#       refusable(允许"拒绝"→隐藏正文) / expires(可选，到期自动下线) / draft
#
# lang 决定这条公告给谁看：zh-Hans / zh-Hant / en，缺省 all（所有人）。
# 同一条公告的多个语言版本共用同一个 id —— id 同时是"已读"记录的键，
# 所以读者确认了中文版就等于确认了这条公告，不会换语言后又被问一次。
# 每个版本都会渲染进页面，由 assets/announce.js 按当前语言挑一个显示。
id: acceleration-domain-migration-2026-11
lang: zh-Hans
title: 加速域名切换完成：请尽早迁移至新地址
date: 2026-11-03
author: Announcement system
level: important
refusable: false
expires: 2027-01-06
---

## 新的加速域名已通过测试

`https://satellite.db.authskin.xyz` 已完成测试并转入常态化加速运行。路径、参数与响应格式和旧地址完全一致——迁移只是换一个域名，不需要改代码。

建议尽早把客户端、脚本、书签和巡检配置里的地址迁移过来。

## 旧地址的维护安排

`https://satellite.api.authskin.xyz`（EdgeOne 加速）将于 **2026 年 11 月 6 日** 起临时暂停维护。本次维护时间较长，**可能持续到 2027 年 1 月 5 日**，期间该地址将不可用。

- 常态化加速地址：`https://satellite.db.authskin.xyz`
- 直连源站（实时地址，无边缘缓存）：`https://satellite.authskin.xyz` —— 需要最新数据时用它
