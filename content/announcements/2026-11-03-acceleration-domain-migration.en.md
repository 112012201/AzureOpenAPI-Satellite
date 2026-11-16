---
# One announcement per .md file. After editing, run: php index.php announcements
# Fields: id / lang / title / date / author / level(urgent|important|normal|minor) /
#         refusable (allow "decline", which covers the page) / expires / draft
#
# `lang` decides who reads it: zh-Hans / zh-Hant / en, default all (everybody).
# The language versions of one notice share the same id -- the id is also the
# "read" key, so acknowledging the Chinese version acknowledges the notice rather
# than one of its translations. Every version is rendered into the document and
# assets/announce.js shows the one that matches the reader's language.
id: acceleration-domain-migration-2026-11
lang: en
title: Accelerated address migration complete — move to the new address early
date: 2026-11-03
author: Announcement system
level: important
refusable: false
expires: 2027-01-06
---

## The new accelerated address has passed testing

`https://satellite.db.authskin.xyz` has finished testing and is now in regular accelerated operation. Paths, parameters and response formats are identical to the old address, so migrating is a hostname change only — no code changes.

Migrate the clients, scripts, bookmarks and monitoring configurations early.

## The maintenance window on the old address

`https://satellite.api.authskin.xyz` (EdgeOne) enters a temporary maintenance window on **6 November 2026**. It is expected to run for a long time — **possibly until 5 January 2027** — and the address will be unavailable while it is closed.

- Regularly accelerated: `https://satellite.db.authskin.xyz`
- Direct origin (real-time, no edge cache): `https://satellite.authskin.xyz` — use this when you need the freshest data
