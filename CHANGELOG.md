# Changelog

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
