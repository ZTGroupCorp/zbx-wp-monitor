# Handoff Zabbix: zbx-wp-monitor 1.3.0 (rendimiento: DB, caches, latencia)

**Target:** Zabbix server, template **"WordPress site by plugin"** (10716).
**Precondición:** sitios con el plugin ≥ 1.3.0 (auto-update tras publicar el release).
Las claves son aditivas: los items actuales no cambian.

## Campos nuevos del endpoint

Items dependientes del item maestro HTTP que ya consulta `/wp-json/ztgrp-monitor/v1/status`.

**Valores `null`:** lo que el sitio no puede medir sale `null` (no 0). Agregar a cada item,
después del JSONPath, el paso **"Matches regular expression"** `^-?[0-9.]+$` con
**Custom on fail → Discard value**, para que un `null` no deje el item unsupported ni se
grabe como 0. Lo mismo para los sitios que aún corren < 1.3.0 (la clave no existe):
JSONPath con **Custom on fail → Discard value**.

| Clave JSON | Tipo | Unidad | Significado |
|---|---|---|---|
| `db_size_mb` | Numeric (float) | MB | Datos + índices de las tablas de la instalación (en multisite, toda la red) |
| `db_overhead_mb` | Numeric (float) | MB | Espacio libre recuperable (`DATA_FREE`); con InnoDB en tablespace compartido solo cuenta MyISAM/Aria |
| `db_revisions` | Numeric (unsigned) | | Revisiones de posts del sitio principal |
| `db_expired_transients` | Numeric (unsigned) | | Transients vencidos sin borrar en `options` del sitio principal |
| `db_options_rows` | Numeric (unsigned) | | Filas de `options` del sitio principal |
| `db_checked_at` | Numeric (unsigned) | unixtime | Cuándo se midieron los `db_*`; 0 = todavía no |
| `cache_object` | Numeric (unsigned) | 0/1 | Object cache persistente (Redis, Memcached) |
| `cache_opcache` | Numeric (unsigned) | 0/1 | OPcache activo en PHP web; `null` si la API está restringida |
| `cache_opcache_hit_rate` | Numeric (float) | % | Tasa de aciertos de OPcache desde el último reinicio de PHP |
| `cache_opcache_used_pct` | Numeric (float) | % | Memoria de OPcache usada (usada / usada + libre + desperdiciada) |
| `cache_php_mem_limit_mb` | Numeric (float) | MB | `memory_limit` de PHP; -1 = sin límite |
| `cache_php_max_exec` | Numeric (unsigned) | s | `max_execution_time`; 0 = sin límite |
| `lat_db_ms` | Numeric (float) | ms | Ida y vuelta de un `SELECT 1` a la base |
| `lat_req_ms` | Numeric (float) | ms | Tiempo de PHP desde que recibe la petición hasta armar la respuesta (carga de WordPress incluida; sin red ni Cloudflare) |

**Cómo se miden los `db_*`:** los mide WP-Cron como mucho una vez por hora, nunca dentro
del endpoint (en elimpulso, 17 GB, la medición tarda ~2,4 s y se comería el timeout del
HTTP agent). Recién instalado o actualizado, salen `null` con `db_checked_at = 0` hasta el
primer cron. Si el WP-Cron del sitio está roto, `db_checked_at` envejece: eso es una alerta.

## Valores reales de referencia (medidos el 2026-11-25, solo lectura)

| Sitio | db_size_mb | db_overhead_mb | db_revisions | db_options_rows | lat_db_ms |
|---|---|---|---|---|---|
| hispanicprwire | 3213.5 | 1580 | 6419 | 601 | 0.04 |
| elimpulso | 17493.5 | 382 | 116635 | 3884 | 0.16 |

## Triggers sugeridos

`T` = template. Umbrales propuestos por ZT; ajustar después de ver 1-2 semanas de datos.
El item maestro consulta cada 1h: las ventanas van por cantidad de lecturas (`#3` = últimas 3,
unas 3 horas), no por minutos, que con un dato por hora evaluarían un solo valor.

| Trigger | Expresión (orientativa) | Severidad |
|---|---|---|
| Métricas de DB sin actualizar (WP-Cron no corre) | `last(/T/db_checked_at)>0 and now()-last(/T/db_checked_at)>6h` | Warning |
| La base creció más de 20% en un día | `last(/T/db_size_mb)>1.2*last(/T/db_size_mb,#1:now-1d)` | Average |
| Mucho espacio recuperable en la base | `last(/T/db_overhead_mb)>1024 and last(/T/db_overhead_mb)>0.3*last(/T/db_size_mb)` | Info (hoy saltaría en hispanicprwire) |
| Transients vencidos acumulados | `last(/T/db_expired_transients)>1000` | Warning |
| Tabla options inflada | `last(/T/db_options_rows)>10000` | Info |
| Revisiones acumuladas | `last(/T/db_revisions)>100000` | Info (hoy saltaría en elimpulso) |
| Object cache se cayó | `last(/T/cache_object)=0 and last(/T/cache_object,#2)=1` | Average |
| OPcache apagado | `last(/T/cache_opcache)=0` | Warning |
| OPcache casi lleno | `min(/T/cache_opcache_used_pct,#3)>90` | Warning |
| OPcache con pocos aciertos | `max(/T/cache_opcache_hit_rate,#3)<90` | Warning |
| Base lenta | `avg(/T/lat_db_ms,#3)>50` | Average |
| Endpoint lento | `avg(/T/lat_req_ms,#3)>3000` | Warning |

- `cache_opcache_hit_rate` baja justo después de reiniciar PHP-FPM o vaciar OPcache: por
  eso el trigger mira el máximo de las últimas 3 lecturas, no el último valor.
- `db_checked_at`: el refresco lo agenda la consulta de Zabbix cuando el dato tiene más de
  1h y lo ejecuta WP-Cron después; en un sitio sin visitas puede correr recién con la
  consulta siguiente. Lo normal es que el dato tenga entre 1 y 3 horas: de ahí las 6h.
- `lat_req_ms` incluye todo lo que hace el endpoint (también leer los resultados de
  integridad y spam): sirve como tendencia de "qué tan pesado está WordPress" en el sitio.
- Sitios multisite: `db_revisions`, `db_expired_transients` y `db_options_rows` son del
  sitio principal, no de los subsitios.

## Rollout recomendado

1. Publicar 1.3.0 y crear **solo los items** (sin triggers) durante 1 a 2 semanas.
2. Revisar los valores de la flota y ajustar umbrales (sobre todo revisiones y overhead,
   que dependen del tamaño de cada sitio).
3. Activar los triggers.
