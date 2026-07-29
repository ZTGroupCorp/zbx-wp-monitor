# Changelog

## 1.0.4 — 2026-07-29

Dos falsos positivos de `checksums_ok=0` que dejaron 4 triggers High de Zabbix
activos entre 15 y 50 días (elimpulso, hispanicprwire, asipi, staging.asipi.org).
Ninguno era un hallazgo real. Diagnóstico:
`zabbix/handoffs/2026-07-28-checksums-core-4-sitios.md`.

- **Fix (bug A):** la exclusión de `readme.html` / `license.txt` vivía **dentro** de
  la rama `! file_exists()`, así que solo cubría la ausencia. Un `license.txt`
  presente pero **modificado** caía al `md5_file()` y se marcaba como bad. Ahora la
  exclusión aplica a ambos casos (son texto sin rol ejecutable).
- **Fix (bug B):** los checksums se pedían con `get_locale()` (opción `WPLANG`, o sea
  el idioma del **sitio**), pero el paquete del core realmente instalado lo
  identifica `$wp_local_package` en `wp-includes/version.php`. En un sitio con
  `WPLANG=es_ES` y archivos del paquete `en_US`, se comparaba en_US contra checksums
  es_ES y fallaba **exactamente** `wp-includes/version.php` — el único archivo que
  difiere entre paquetes fuera de `wp-content/`. De ahí que el plugin reportara 1 y
  `wp core verify-checksums` saliera limpio. Ahora el locale se deriva de
  `$wp_local_package`, la misma fuente que usa wp-cli. El locale entra además en la
  validación del cache de 24h, así que un cache viejo con el locale equivocado se
  descarta al actualizar.
- **Nuevo `checksums_bad`** en el endpoint: array con las rutas que fallan (tope 20,
  ya existente; los ausentes marcados `" (ausente)"`). El dato ya se persistía pero
  no se exponía, y esa es la razón por la que las 4 alertas se quedaron sin
  diagnóstico: con solo el conteo, cada triage obligaba a entrar por SSH. Clave
  nueva y aditiva: los items de Zabbix actuales no cambian.
- El barrido en curso se reinicia si cambia la versión del plugin (además de la
  versión de WP o el locale del paquete), para no arrastrar hallazgos marcados con
  el criterio viejo tras un auto-update.
- Settings: el bloque de integridad muestra contra qué locale de paquete se comparó
  y el locale del sitio, para detectar la desalineación de un vistazo.

## 1.0.3 — 2026-06-08

- Fix migración Multisite: ahora arrastra el último resultado de integridad del
  sitio principal a la option de red si esta está vacía, para no reportar un
  **falso OK** (`checksums_ok=1` por default) en la ventana hasta el próximo cron.
- El arrastre de integridad es **independiente del guard del token**: cubre también
  una red que ya actualizó a 1.0.2 (token de red seteado) pero quedó con la
  integridad sin migrar.
- Renombrado a **"ZT Zabbix WP Monitor"** (antes "ZT Group — Zabbix WP Monitor").
- Settings: botón **"Copiar al portapapeles"** a la derecha del campo de token;
  el botón **"Regenerar token"** pasó debajo del campo.

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
