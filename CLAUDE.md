# zbx-wp-monitor
WordPress plugin "ZT Zabbix WP Monitor" (repo `ZTGroupCorp/zbx-wp-monitor`, public) for ZT Group: a token-protected REST health endpoint read by Zabbix. Installed on 10 client sites (2026-10-16).

## Where it runs
- Endpoint `GET /wp-json/ztgrp-monitor/v1/status` with header `X-ZTGRP-Token`; no token gives 401.
- Read-only: counts, versions, core checksum result, and (since 1.2.0) a daily spam-post scan (`content_*` keys, no titles or content). Never users or credentials. Current version 1.2.0 (header, `ZTGRP_MONITOR_VERSION`, tag `v1.2.0`); whether the GitHub release asset is published is not verified here.
- Zabbix side: template "WordPress site by plugin" (10716), macro `{$WP.MON.TOKEN}`, managed from the `zabbix.ztgrp.net` project.
- Verification hosts with SSH: elimpulso (`audit-elimp`, docroot `/usr/share/nginx/html`) and hispanicprwire (`audit-hprw`, docroot `/home/hispanicwp/public_html`, read-only user).

## Run and test (always in Docker, never on the host)
No test suite in the repo. Syntax: `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php -l zbx-wp-monitor.php includes/*.php`. Behavior needs WordPress: use a disposable WordPress + wp-cli container, or the WP stub harness mentioned in `tasks/todo.md` (its files are not in the repo). Minimum PHP is 7.0.

## Deploy
- Release = tag `vX.Y.Z` + GitHub asset `zbx-wp-monitor.zip`, built with `git archive --prefix=zbx-wp-monitor/` (the folder name inside the zip matters). The header `Version`, the `ZTGRP_MONITOR_VERSION` constant and the tag must match. `.gitattributes` keeps `.gitattributes` and `.gitignore` out of the zip.
- Fleet update: Plugin Update Checker (vendored) polls GitHub releases, and WP auto-update is forced for this plugin. To force it on a site: `wp option delete external_updates-zbx-wp-monitor` and `wp cron event schedule wp_maybe_auto_update now`.
- First install on a site: upload the zip in wp-admin, activate (a token is generated), copy it from Settings, paste into the Zabbix macro. Multisite: Network Activate, one host per network.

## Project rules
- Plugin stays 100% read-only; new fields are additive and keep existing keys.
- Releases are announced to JAZ first; he authorized merging PRs here (2026-10-15), not unreviewed releases.
- `.gitattributes` already export-ignores `tasks/`, `docs/` and `tools/` from the zip.

## Gotchas
- `vendor/plugin-update-checker/` is tracked and present; if it were ever missing the code silently skips the updater, so check it before building a zip.
- `zbx-wp-monitor.zip` in the root is gitignored and dates from 2026-07-29.
- Site REST blocked (AIOS) returns 403, not the plugin's 401.
- After a release the published integrity result can take up to 24 h to refresh (candidate fix 1.0.5, still pending in `tasks/todo.md`).
- On multisite upgrades from before 1.0.2 a false `checksums_ok=1` was possible (fixed in 1.0.3).
- Checksums must use the package locale (`$wp_local_package`), not `WPLANG` (fixed in 1.0.4).

## Pointers
- `readme.md`, `CHANGELOG.md`, `docs/HANDOFF-zabbix-1.2.0.md`, `tasks/todo.md` (untracked; plan for 1.1.0, not started) and `tasks/todo-content.md` (tracked).
- Memory: `/home/jaz/.claude/projects/-home-jaz-projects-zabbix-wp-plugin/memory/MEMORY.md` and `-home-jaz-projects-zabbix-ztgrp-net/memory/zbx-wp-monitor-plugin.md`.
- Worktree `.claude/worktrees/content-spam-scan` (branch `worktree-content-spam-scan`); do not remove without asking JAZ.

Last reviewed: 2026-11-13
