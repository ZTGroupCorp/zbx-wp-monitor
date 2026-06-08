# Changelog

## 1.0.2 — 2026-06-08

- Soporte **WP Multisite**: el plugin se **activa en red** (Network Activate) y se
  comporta como "un host por red".
  - Token único de red (`get/update_site_option`) en lugar de uno por subsitio.
  - Integridad del core corre **una sola vez** en el sitio principal (guard
    `is_main_site()`), no una vez por subsitio.
  - `admins` reporta los **super admins** de la red.
  - Página de configuración bajo **Network Admin → Settings**; endpoint del sitio
    principal.
  - `uninstall.php` limpia también las options de red.
  - **Migración en upgrade** (`plugins_loaded`): una red que ya venía operando
    mueve su token al storage de red **preservándolo** (lo lee del sitio
    principal), sin re-cargar la macro en Zabbix. Idempotente y sin costo en
    single-site.
- Single-site: comportamiento sin cambios (fallback transparente a `get_option`).

## 1.0.1 — 2026-06-07

- Release de prueba para validar el auto-update por GitHub releases (sin cambios funcionales).

## 1.0.0 — 2026-06-07

- Versión inicial.
- Endpoint REST `ztgrp-monitor/v1/status` con token por header (`hash_equals`).
- Métricas: versiones (plugin/WP/PHP), updates (core/plugins/themes), admins,
  cron atrasado, autoload KB.
- Integridad del core diaria por WP-Cron, en lotes con cursor (checksums
  oficiales wp.org, cacheados 24h).
- Página de ajustes: ver/copiar/regenerar token, forzar check de integridad.
- Auto-update vía releases de GitHub (Plugin Update Checker v5.7, release assets).
