# zbx-wp-monitor

Plugin WordPress de **ZT Group** que expone la salud interna del sitio a Zabbix
vía un endpoint REST autenticado por token. Pensado para monitoreo server-side
(HTTP agent de Zabbix), sin agente en el servidor del sitio.

## Qué reporta

`GET /wp-json/ztgrp-monitor/v1/status` con header `X-ZTGRP-Token: <token>`:

```json
{
  "plugin_version": "1.0.2",
  "wp_version": "6.9.4",
  "php_version": "8.2.20",
  "core_updates": 0,
  "plugin_updates": 3,
  "theme_updates": 1,
  "admins": 3,
  "cron_overdue": 0,
  "autoload_kb": 257,
  "checksums_ok": 1,
  "checksums_bad_count": 0,
  "checksums_checked_at": 1780000000
}
```

- Solo conteos y versiones — nunca rutas, usuarios ni datos sensibles.
- Sin token válido → 401.
- `checksums_*`: integridad del core contra los checksums oficiales de wp.org,
  calculada a diario por WP-Cron en lotes (no carga el request). Se ignora
  `wp-content/` y la ausencia de `readme.html`/`license.txt` (hardening común).

## Instalación

1. Subir el zip desde **wp-admin → Plugins → Añadir nuevo → Subir** (o `wp plugin install zbx-wp-monitor.zip --activate`).
2. Activar: se genera un token único para el sitio.
3. Copiar el token desde **Ajustes → Zabbix Monitor**.
4. En Zabbix: pegar el token en la macro secreta `{$WP.MON.TOKEN}` del host
   virtual del sitio y linkear el template **"WordPress site by plugin"**.

## Multisite (red)

En una red WordPress Multisite el modelo es **un host por red**: se monitorea la
red entera como un único host de Zabbix.

1. **Activar en red** (Network Activate), no site por site.
2. Configurar desde **Network Admin → Settings → Zabbix Monitor**: hay un único
   token compartido por toda la red.
3. El endpoint a sondear es el del **sitio principal** de la red.

Semántica de las métricas en multisite:

- `core_updates`, `plugin_updates`, `theme_updates`, `checksums_*`: a nivel red
  (core, plugins y temas son compartidos). La integridad del core corre **una
  sola vez** en el sitio principal, no por subsitio.
- `admins`: cantidad de **super admins** de la red (control total), no admins de
  un subsitio.
- `autoload_kb`, `cron_overdue`: reflejan el **sitio principal** (donde Zabbix
  sondea); no se agregan en vivo sobre toda la red para no recargar el endpoint.

En instalaciones single-site el comportamiento es el de siempre (token y datos
por sitio, página en **Ajustes → Zabbix Monitor**).

## Updates

El plugin se actualiza solo desde los releases de este repo
([Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
+ auto-update de WP forzado para este plugin). Publicar release = la flota se
actualiza sola.

**Release:** tag `vX.Y.Z` + asset `zbx-wp-monitor.zip` (carpeta
`zbx-wp-monitor/` adentro). La versión del header del plugin debe coincidir
con el tag.

## Hardening

- Solo lectura: el plugin no modifica nada del sitio.
- Nada en el front, sin AJAX público, sin assets encolados.
- Token comparado con `hash_equals()`; viaja por header, nunca en query string.
- Endpoint fuera del índice REST (`show_in_index: false`).

## Changelog

Ver [CHANGELOG.md](CHANGELOG.md).
