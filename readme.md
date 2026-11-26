# zbx-wp-monitor

Plugin WordPress de **ZT Group** que expone la salud interna del sitio a Zabbix
vía un endpoint REST autenticado por token. Pensado para monitoreo server-side
(HTTP agent de Zabbix), sin agente en el servidor del sitio.

## Qué reporta

`GET /wp-json/ztgrp-monitor/v1/status` con header `X-ZTGRP-Token: <token>`:

```json
{
  "plugin_version": "1.3.0",
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
  "checksums_bad": [],
  "checksums_checked_at": 1780000000,
  "content_suspect_count": 1,
  "content_suspect": [
    {"blog": 1, "id": 459, "type": "post", "status": "publish", "score": 15,
     "reasons": ["spam:slot gacor", "spam:maxwin", "spam:situs slot"],
     "title": "Slot Gacor Maxwin Hari Ini", "url": "https://ejemplo.com/slot-gacor-maxwin-hari-ini/"}
  ],
  "content_new_24h": 3,
  "content_new_avg_30d": 2.4,
  "content_checked_at": 1780000000,
  "db_size_mb": 412.7,
  "db_overhead_mb": 3.1,
  "db_revisions": 18234,
  "db_expired_transients": 12,
  "db_options_rows": 1544,
  "db_checked_at": 1780000000,
  "cache_object": 1,
  "cache_opcache": 1,
  "cache_opcache_hit_rate": 99.42,
  "cache_opcache_used_pct": 61.3,
  "cache_php_mem_limit_mb": 256,
  "cache_php_max_exec": 30,
  "lat_db_ms": 0.41,
  "lat_req_ms": 187.5
}
```

- Conteos, versiones, las rutas del core que fallan el checksum y, por cada post
  sospechoso, su URL y (solo si está publicado) su título — nunca usuarios,
  credenciales, contenido de posts ni títulos de borradores o privados.
- Sin token válido → 401.
- `checksums_*`: integridad del core contra los checksums oficiales de wp.org,
  calculada a diario por WP-Cron en lotes (no carga el request). Se ignora
  `wp-content/` y los archivos `readme.html`/`license.txt` (ausentes por
  hardening o editados: texto sin rol ejecutable). Los checksums se piden para el
  locale del **paquete** instalado (`$wp_local_package` de `wp-includes/version.php`),
  igual que `wp core verify-checksums`, no para el locale del sitio (`WPLANG`).
- `checksums_bad`: array con las rutas que fallan (tope 20), los ausentes
  marcados `" (ausente)"`. Sirve para triage directo desde la alerta de Zabbix,
  sin entrar por SSH al sitio.
- `content_*`: posts spam, revisados a diario por WP-Cron (ver abajo).
- `db_*`, `cache_*`, `lat_*`: rendimiento (ver abajo).

## Rendimiento (`db_*`, `cache_*`, `lat_*`)

Solo lectura. Lo que no se puede medir sale `null` (distinto de 0). CPU, RAM y disco
no están: los mide el agente Zabbix del host.

- `db_size_mb` / `db_overhead_mb`: tamaño y espacio libre recuperable de las tablas con
  el prefijo de la instalación (en multisite, toda la red). Con InnoDB en tablespace
  compartido (`innodb_file_per_table=0`) el overhead solo cuenta tablas MyISAM/Aria.
- `db_revisions`, `db_expired_transients`, `db_options_rows`: del sitio principal.
- Los `db_*` los mide WP-Cron como mucho una vez por hora (en bases grandes tarda segundos,
  no se hace dentro del endpoint); `db_checked_at` dice cuándo. Recién instalado o
  actualizado, salen `null` con `db_checked_at` = 0 hasta que corre el cron.
- `cache_object`: 1 si hay object cache persistente (Redis, Memcached).
- `cache_opcache*`: OPcache de la petición web; `null` si la API está restringida
  (`opcache.restrict_api`). `cache_php_mem_limit_mb` = -1 si no hay límite.
- `lat_db_ms`: ida y vuelta de un `SELECT 1`. `lat_req_ms`: tiempo de PHP desde que
  recibe la petición hasta armar la respuesta (carga de WordPress incluida).

## Posts spam (`content_*`)

Detecta contenido inyectado típico de un sitio comprometido (casino, farmacia,
"slot gacor", links ocultos). Solo lectura: nunca modifica ni borra posts.

- **Qué revisa:** posts nuevos por ID (el spam inyectado suele venir con fecha
  vieja, así que no se filtra por fecha), posts viejos modificados desde la corrida
  anterior y los sospechosos ya registrados. La primera corrida revisa todo.
  Alcance: post types públicos (menos adjuntos), estados publish/future/draft/pending/private.
- **Puntaje** (sospechoso desde 5):

  | Señal | Puntos | Motivo en `reasons` |
  |---|---|---|
  | Frase de spam (slot gacor, togel, "buy cialis", "viagra sin receta", replica watches...) | +5: alcanza sola | `spam:<frase>` |
  | Palabra de tema (casino, betting, viagra, oxycodone, porn...) | +1 c/u, tope 3: nunca alcanza sola | `kw:<palabra>` |
  | Link oculto por CSS | +4 | `hidden_link` |
  | Código ofuscado (`eval(`, `atob(`...) | +5: alcanza solo | `code:obfuscated` |
  | `<script>` que no es un embed conocido | +3 | `code:script` |
  | Autor inexistente (inserción directa por SQL); no cuenta si ese autor tiene 20+ posts en el sitio (resto de migración) | +4 | `author_missing` |
  | ≥10 / ≥25 dominios externos distintos | +1 / +2 | `ext_domains:<n>` |
  | Alfabeto ajeno al locale: 20-50% de las letras / más del 50% | +4 / +5 (alcanza solo) | `foreign_script` |

  Calibrado con hispanicprwire (sitio de prensa, 30.843 posts): las palabras de tema
  aparecen en comunicados legítimos (FDA, hoteles casino, sponsors de apuestas), por
  eso solo suman a una señal técnica o a una frase de spam.

- **Registro persistente:** un sospechoso alerta hasta que se marca revisado, se
  borra o se limpia. "Marcar revisado" vale mientras el post no se vuelva a editar.
- **Allowlist por sitio** (dominios y palabras): en la página de ajustes. Guardarla
  re-revisa todos los posts.
- `content_new_24h`: posts creados desde la corrida anterior (0 en un barrido
  completo); `content_new_avg_30d`: promedio de las corridas anteriores, para
  detectar picos de volumen.
- Las keywords y los dominios permitidos por defecto se ajustan con los filtros
  `ztgrp_monitor_content_keywords` y `ztgrp_monitor_content_default_domains`.

## Instalación

1. Subir el zip desde **wp-admin → Plugins → Añadir nuevo → Subir** (o `wp plugin install zbx-wp-monitor.zip --activate`).
2. Activar: se genera un token único para el sitio.
3. Copiar el token desde **Ajustes → ZT Zabbix Monitor**.
4. En Zabbix: pegar el token en la macro secreta `{$WP.MON.TOKEN}` del host
   virtual del sitio y linkear el template **"WordPress site by plugin"**.

## Multisite (red)

En una red WordPress Multisite el modelo es **un host por red**: se monitorea la
red entera como un único host de Zabbix.

1. **Activar en red** (Network Activate), no site por site.
2. Configurar desde **Network Admin → Settings → ZT Zabbix Monitor**: hay un único
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
- `content_*`: **toda la red**. El job corre en el sitio principal y recorre los
  subsitios; cada sospechoso indica su `blog`.

En instalaciones single-site el comportamiento es el de siempre (token y datos
por sitio, página en **Ajustes → ZT Zabbix Monitor**).

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
