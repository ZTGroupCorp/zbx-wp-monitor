# TODO — Fase 1: Observabilidad extendida (read-only)

> Alcance: ampliar el JSON de `ztgrp-monitor/v1/status` con métricas de DB, cache y latencia
> (v1.3.0) y después el puente Wordfence (v1.4.0). **100% read-only**, aditivo (no romper
> items Zabbix existentes). El plan original (`~/.claude/plans/playful-puzzling-hennessy.md`)
> ya no existe: este archivo es la fuente. Se saltó 1.1.0 (salieron 1.2.x de posts spam).
> Decisión 2026-11-25: Wordfence se separa en su propia release (propuesta, validar con JAZ).

## v1.3.0: DB, cache, latencia

- [x] **0.** Fechar `1.2.2 — sin publicar` en el CHANGELOG (release publicada 2026-11-25 ET)
- [x] **1. `ztgrp_monitor_db_metrics()`** (`includes/perf.php`): `db_size_mb`, `db_overhead_mb`,
  `db_revisions`, `db_expired_transients`, `db_options_rows`, `db_checked_at`
  - Tamaño y overhead desde `information_schema.TABLES` filtrado por `base_prefix` (esc_like:
    una tabla `wpxajena` no entra). Con `innodb_file_per_table=0` el overhead solo cuenta
    MyISAM/Aria (cada InnoDB reportaría el libre de todo ibdata).
  - **Cambio de diseño:** la medición corre en WP-Cron (`ztgrp_monitor_db_refresh`), no en el
    endpoint. En elimpulso tarda 2,4 s (17,5 GB, 116k revisiones) y el timeout del HTTP agent
    de Zabbix es 3 s por defecto. El endpoint lee la option `ztgrp_monitor_db` y agenda el
    refresco si tiene > 1h o es de otra versión. Sin dato: null y `db_checked_at=0`.
- [x] **2. `ztgrp_monitor_cache_metrics()`**: `cache_object`, `cache_opcache`, `cache_opcache_hit_rate`,
  `cache_opcache_used_pct`, `cache_php_mem_limit_mb`, `cache_php_max_exec`. OPcache con
  `restrict_api` → null; en CLI → null.
- [x] **3. Latencia**: `lat_db_ms`, `lat_req_ms` (calculado al final del colector).
- [x] **4. Merge** en `ztgrp_monitor_collect_metrics()` (claves existentes intactas); bump a
  1.3.0; readme con ejemplo y sección; uninstall borra `ztgrp_monitor_db`; deactivate limpia
  el hook. **CHANGELOG sigue "1.3.0 — sin publicar": fechar en el PR justo antes del merge.**
- [x] **5. Handoff** `docs/HANDOFF-zabbix-1.3.0.md` (umbrales propuestos por ZT, OK de JAZ)
- [x] **6. Verificado** 2026-11-25:
  - Lint PHP 7.0 y 8.4 de todos los archivos.
  - Docker (MariaDB 11 + WordPress php8.3-apache, endpoint HTTP real): 401 sin token; 1.3.0
    con las 18 claves de 1.2.2 intactas + 14 nuevas; valores = datos sembrados (3 revisiones,
    1 transient vencido, el vigente no cuenta); OPcache 1; `memory_limit=-1` → -1.
  - Multisite (red con blog 2 de 7 revisiones y 2 vencidos): conteos del principal (5 y 1),
    incluso llamado desde el contexto del blog 2; tamaño de las 28 tablas de la red.
  - Flujo cron: 1a llamada null + 1 evento; tras el cron valores y 0 eventos; versión vieja
    sirve el dato y agenda refresco. 0 warnings PHP en todo.
  - Prod, solo lectura (`ztgrp_monitor_db_measure()` por stdin, sin escribir): hprw 2,6 ms,
    elimpulso 2.434 ms (motivo del cambio a cron).
- [ ] **7. Release**: PR, fechar CHANGELOG, merge (JAZ), `gh release create v1.3.0` con el zip,
  verificar flota (hprw y elimpulso estaban en 1.2.1 el 25-nov a la noche)

### Hallazgos para otros proyectos
- **hispanicprwire (hdn):** 1,58 GB recuperables de 3,2 GB (`db_overhead_mb`); probablemente
  restos de `wfLiveTrafficHuman`. Y el scan de Wordfence falla (ver abajo).

## v1.4.0: puente Wordfence (descubrimiento hecho, código diferido)

### Descubrimiento 2026-11-25 (hispanicprwire, ontario, Wordfence 9.1.1 free, single site)
Read-only con `wp eval-file -` como `wpaudit`, más lectura del código del plugin en el server.

- **Activo/versión:** `WORDFENCE_VERSION` (9.1.1), `class_exists('wfConfig')`. Licencia:
  `wfLicense::current()->isPaid()/getType()` ("free").
- **Último scan:** `wfConfig::get('scanTime')` = inicio del último scan (epoch float).
  Resultado en `wfConfig::get('lastScanCompleted')`: `'ok'` si terminó bien
  (`wfScanEngine.php:218`), si no el mensaje de error; tipo en `lastScanFailureType`.
  `wfScanner::shared()->isRunning()`, `isEnabled()`, `nextScheduledScanTime()`.
  `wfIssues::hasScanFailed()` detecta scans colgados (umbral 300 s sin status).
- **Hallazgo real:** en hprw el scan programado del 25-nov (15:15 UTC) **terminó en error** a
  las 20:08: "posible bucle infinito" en `uploads/2017/06/...-150x150.jpg`, 2,9M archivos
  indexados, `scanType=highsensitivity`. Nadie lo ve hoy. Pasar a hdn.
- **Issues:** `wfIssues::shared()->getIssueCount()` (status new), `getIssueCounts()` por status.
  Severidad en tabla `wfIssues.severity` (100 crítico, 75 alto...). hprw: 0 nuevos, 4 ignorados.
- **Bloqueos:** `wfBlockedIPLog` agregada por día (`unixday`, `blockType`, `blockCount`); API
  `(new wfActivityReport())->getBlockedCount($dias)` (granularidad diaria, no 24h exactas).
  `wfHits` tiene índice en `ctime` y permite 24h exactas por `action` (hprw: 5.598
  `blocked:wfsn*` en 24h). Preferir `wfBlockedIPLog` (tabla chica) o `wfHits` con índice.
- **Firewall:** `wfFirewall::firewallMode()` lee `wafStatus` del archivo `wflogs/config.php`;
  `protectionMode()` = extended si `WFWAF_AUTO_PREPEND`. **En CLI no sirve:** `.user.ini` no
  aplica (auto_prepend vacío) y `wpaudit` no puede leer `wflogs/` (da "disabled" en falso y
  escribe "Wordfence WAF file read failed" por error_log). Solo vale dentro de la petición web
  del endpoint (FPM como dueño). Verificar en Docker con Wordfence real, no en Prod.
- **Tablas:** usar `wfDB::networkTable('wfIssues')`: prefijo de red (`base_prefix`) en
  multisite y puede estar en minúsculas (`wfSchema::usingLowercase()`).
- **Aparte (hdn):** `wfIssues`/`wfPendingIssues`/`wfSNIPCache` tienen índices duplicados
  (`status_2`...`status_16`, `type_2`...`type_61`): bug de upgrades de Wordfence.

### Tareas
- [ ] `ztgrp_monitor_wordfence_metrics()`: `wf_active`, `wf_missing`, `wf_version`,
  `wf_last_scan_at`, `wf_scan_ok`, `wf_scan_running`, `wf_scan_issues`, `wf_scan_critical`,
  `wf_firewall_mode`, `wf_protection`, `wf_blocked_24h`; null lo no disponible, try/catch
  `Throwable`, sin warnings ni salida extra en el JSON
- [ ] Docker: WP + Wordfence free, WAF basic y extended, sin Wordfence → `wf_active=0, wf_missing=1`
- [ ] Handoff + release v1.4.0

## Notas / decisiones
- Multisite: db/cache/latencia reflejan el sitio principal (donde pega Zabbix), igual que
  `autoload_kb`; Wordfence es de red.
- Claves nuevas planas con prefijo (`db_*`, `cache_*`, `lat_*`, `wf_*`).
- `tasks/` ya tiene `export-ignore` en `.gitattributes`.

## Review (completar al cerrar la fase)
- _pendiente_

---

# Fuera de fase — 1.0.4 (2026-07-29): falsos positivos de checksums

Origen: `zabbix/handoffs/2026-07-28-checksums-core-4-sitios.md` y
`...-plugin-exponer-archivos-checksum.md`. 4 triggers High activos 15-50 días
(elimpulso, hispanicprwire, asipi, staging.asipi.org), ninguno un hallazgo real.

- [x] Bug A — exclusión de `readme.html`/`license.txt` fuera de la rama `! file_exists()`
- [x] Bug B — locale de los checksums desde `$wp_local_package` (no `get_locale()`/WPLANG),
      con el locale dentro de la validación del cache de 24h
- [x] Barrido en curso se reinicia si cambia la versión del plugin (no arrastra
      hallazgos marcados con el criterio viejo tras el auto-update)
- [x] `checksums_bad` expuesto en el endpoint (aditivo; 1462 bytes con las 20 rutas)
- [x] Verificado con arné de stubs de WP (`scratchpad/harness.php`, `edge.php`):
      elimpulso 1→0, hispanicprwire 1→0 (API pasa de es_ES a en_US), sitio
      legítimamente es_ES sigue pidiendo es_ES, y un `wp-load.php` con webshell
      sigue detectándose (la exclusión no aflojó la detección)
- [x] Release v1.0.4 (`3c7c3f3`, tag `v1.0.4`, zip por `git archive`)
- [x] Verificado en los dos sitios de los bugs, endpoint HTTP incluido:
      - **elimpulso** (`audit-elimp`, docroot `/usr/share/nginx/html`): antes
        `bad=["license.txt"]` con paquete es_ES legítimo → ahora `checksums_ok=1`,
        `checksums_bad_count=0`, `checksums_bad=[]`.
      - **hispanicprwire** (`audit-hprw` = ontario, `/home/hispanicwp/public_html`):
        antes `bad=["wp-includes/version.php"]` con `get_locale()=es_ES` y
        `$wp_local_package` sin definir → ahora compara contra `en_US` y da
        `checksums_ok=1`, `bad_count=0`. La propagación se validó con el
        **auto-update real** (PUC + `wp_maybe_auto_update` por el cron del sistema),
        no con instalación manual.
- [ ] Confirmar en Zabbix que los 4 triggers se recuperan (asipi y staging.asipi.org
      no tienen SSH acá: se recuperan cuando tomen el auto-update y corra el job diario)

## Cierre del lado Zabbix (verificado desde el proyecto `zabbix/` el 2026-07-29)
- [x] Los 2 triggers de los sitios con SSH **se recuperaron**: wp-elimpulso y
      wp-hispanicprwire pasaron a `checksums_bad_count=0` con plugin 1.0.4.
- [x] asipi y staging.asipi.org **ya tomaron el 1.0.4** y su `checksums_bad` ahora dice
      `["wp-includes/version.php"]`, o sea que confirman la predicción del bug B sin
      necesidad de SSH. Siguen en PROBLEM solo porque su `checked_at` (10:22 y 15:04) es
      anterior al update: reportan el barrido viejo. Se cierran al correr
      `wp cron event run ztgrp_monitor_integrity_run` o con el cron diario.
- [x] Consumido en Zabbix: item dependiente `wp.checksums_bad` (itemid 59459 en el template
      `WordPress site by plugin` 10716), heredado a los 11 hosts. El paso JSONPath va con
      "on fail → set value to vacío" para que los sitios que aún corren 1.0.3, donde la clave
      no existe, no queden unsupported. Verificado: 1.0.4 da la ruta, 1.0.3 da vacío.
- Rollout de 1.0.4 al 2026-07-29: **4 de 11** sitios. El auto-update no se aplica solo.

## Pendiente para 1.0.5 — el resultado no se re-publica tras un upgrade
Un release que cambia el criterio de qué cuenta como bad tarda **hasta 24h** en reflejarse:
`integrity.php` reinicia el **barrido en curso** cuando cambia `ZTGRP_MONITOR_VERSION`, pero el
resultado publicado en `ztgrp_monitor_integrity` sigue siendo el viejo hasta que un barrido nuevo
termine, y el job es diario. Eso obliga a forzar el cron sitio por sitio (11 sitios) en cada fix
del instrumento, que es exactamente lo que pasó hoy con asipi y staging.asipi.org.
- [ ] En el upgrade (o al detectar cambio de versión en `plugins_loaded`), agendar
      `ztgrp_monitor_integrity_run` de inmediato en lugar de esperar el tick diario.
