# Handoff Zabbix: zbx-wp-monitor 1.2.0 (posts spam)

**Target:** Zabbix server, template **"WordPress site by plugin"**.
**Precondición:** sitios con el plugin ≥ 1.2.0 (auto-update tras publicar el release).
Las claves son aditivas: los items actuales no cambian.

## Campos nuevos del endpoint

Items dependientes del item maestro HTTP que ya consulta `/wp-json/ztgrp-monitor/v1/status`.

| Clave JSON | JSONPath | Tipo | Significado |
|---|---|---|---|
| `content_suspect_count` | `$.content_suspect_count` | Numeric (unsigned) | Posts sospechosos registrados y sin revisar (toda la red en multisite) |
| `content_suspect` | `$.content_suspect` | Text | Hasta 20 sospechosos: `blog`, `id`, `type`, `status`, `score`, `reasons` |
| `content_new_24h` | `$.content_new_24h` | Numeric (unsigned) | Posts creados desde la corrida diaria anterior (0 en un barrido completo) |
| `content_new_avg_30d` | `$.content_new_avg_30d` | Numeric (float) | Promedio de `content_new_24h` de las corridas anteriores (hasta 30) |
| `content_checked_at` | `$.content_checked_at` | Numeric (unsigned), unixtime | Última corrida completa; 0 = todavía no corrió |

## Triggers sugeridos

| Trigger | Expresión (orientativa) | Severidad |
|---|---|---|
| Posts spam detectados | `last(/T/content_suspect_count)>0` | High (Average durante el período de calibración) |
| Pico de posts nuevos | `last(/T/content_new_24h)>max(10, 5*last(/T/content_new_avg_30d))` | Average |
| Revisión de contenido sin correr | `last(/T/content_checked_at)>0 and now()-last(/T/content_checked_at)>2d` | Warning |

- En la descripción del trigger de spam, incluir `{ITEM.LASTVALUE}` del item
  `content_suspect` (texto): trae ID y motivos, para hacer el triage sin entrar al sitio.
- El post se abre en `https://<sitio>/wp-admin/post.php?post=<id>&action=edit`
  (en multisite, en el subsitio del `blog` indicado).
- La alerta se cierra sola cuando el post se borra, se limpia o se marca revisado
  en **Ajustes → ZT Zabbix Monitor** (en multisite: Network Admin → Settings).

## Rollout recomendado

1. Publicar 1.2.0 y crear **solo los items** (sin triggers) durante 1 a 2 semanas.
2. Revisar `content_suspect` en toda la flota: los falsos positivos se ajustan con la
   allowlist del sitio (palabras/dominios) o marcándolos revisados.
3. Activar los triggers.
