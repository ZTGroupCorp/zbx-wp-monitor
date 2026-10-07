# TODO — v1.2.0: detección diaria de posts spam (read-only)

> Rama `worktree-content-spam-scan`. Solo detecta y reporta; nunca toca posts.
> NO se publica release (la flota se auto-actualiza) sin validación de JAZ.

## Diseño
- Job diario WP-Cron `ztgrp_monitor_content_run` (`includes/content.php`), por lotes
  con deadline de 10s y re-agendado cada 1 min, igual que la integridad.
- Qué revisa (post types públicos menos attachment; estados publish/future/draft/pending/private):
  1. `ID > última marca` por sitio (no por fecha: el spam inyectado suele venir backdated).
     Primera corrida: barrido completo desde ID 0 (encuentra spam preexistente).
  2. Posts viejos con `post_modified_gmt` desde la corrida anterior (−1h de margen).
  3. Re-evaluación de los sospechosos ya registrados (se caen si se borraron, se
     limpiaron o se marcaron revisados).
- Puntaje (umbral 5): keyword fuerte +3 / media +2 (+2 si está en el título),
  link oculto por CSS +4, código (`<script>`, `eval(`, `atob(`...) +3, autor
  inexistente +4, dominios externos ≥10 +2 / ≥25 +3, alfabeto ajeno al locale +3.
- Registro persistente de sospechosos: alerta hasta revisión, borrado o limpieza.
- Ack por post ("Marcar revisado"): guarda `post_modified_gmt`; si el post se vuelve
  a editar, se re-evalúa. Solo escribe en options del plugin.
- Allowlist por sitio: dominios y palabras a ignorar.
- Multisite: corre en el sitio principal y recorre los subsitios (`switch_to_blog`).
- Programación: en activación y también en `init` si falta (el auto-update no
  dispara el hook de activación).

## Claves nuevas del endpoint (aditivas)
`content_suspect_count`, `content_suspect` (tope 20: blog, id, type, status, score,
reasons; sin títulos ni contenido), `content_new_24h`, `content_new_avg_30d`,
`content_checked_at`.

## Tareas
- [x] `includes/content.php`: job, scoring, registro, ack, allowlist
- [x] Métricas en `ztgrp_monitor_collect_metrics()`
- [x] Settings: sección "Contenido sospechoso" (lista, revisar, allowlist, ejecutar ahora)
- [x] Programación en activación / `init`; limpieza en desactivación y `uninstall.php`
- [x] Bump 1.2.0, CHANGELOG, readme, `tasks/` y `docs/` export-ignore
- [x] Verificar en WordPress real en Docker (single + multisite): spam detectado,
      legítimos no marcados, backdated detectado, ack, re-edición, endpoint HTTP
- [x] Handoff Zabbix (`docs/HANDOFF-zabbix-1.2.0.md`)
- [ ] Release: **pendiente de validación de JAZ** (modo solo-métrica primero)
- [ ] Calibración en flota real (read-only, `wp eval` en 2-3 sitios) antes del release

## Review (2026-10-07)
Verificado en WP 7.1.2 / PHP 8.2 en Docker (wordpress:php8.2-apache + MariaDB 11),
plugin montado RO; `php -l` limpio en PHP 7.0 y 8.3.
- Escenario 1 (barrido completo, 464 posts, 450 de relleno): 6/6 spam detectados
  (slot gacor, pharma backdated 2015, link oculto, autor inexistente por SQL,
  cirílico, draft ofuscado); 0/4 legítimos marcados (viaje con "casino" + embed
  Instagram, 12 links, página, nota de salud con "viagra"); 0 de relleno.
- Reanudación: con lotes lentos forzados corre en 2 ticks y da el mismo resultado.
- Escenario 2 (incremental): ack quita al instante; borrado sale del registro;
  spam nuevo con fecha 2012 detectado por ID; post viejo editado con link oculto
  detectado por la fase de modificados; `new_24h=2` (revisiones no cuentan).
- Escenario 3: ack re-editado vuelve a alertar; `new_24h=3`, `avg_30d=2`.
- Allowlist por el handler real (nonce): guardar "casino" → 5; vaciarla → 7
  (re-barrido completo). Ajuste surgido acá: guardar la allowlist re-barre todo.
- HTTP: sin token 401; con token claves viejas intactas + `content_*`; sin
  warnings en el log de Apache ni en la página de ajustes.
- Multisite (convert + network activate + subsitio): subsitio no corre ni agenda;
  principal recorre la red (spam del subsitio con `blog: 2`); incremental cuenta el
  post nuevo del subsitio; página de red con link de edición al subsitio.
- Upgrade: sin eventos agendados, el siguiente request los recrea (init).
Pendiente: falsos positivos reales solo se miden en la flota (por eso el rollout
en modo solo-métrica).

## Calibración en flota (dry-run read-only, `docs/DRYRUN-posts-spam.md`)

### hispanicprwire (2026-10-07, plugin 1.0.4 activo, usuario wpaudit)
- 30.843 posts, 130 s, 51 MB. **8 sospechosos, 8 falsos positivos, 0 spam real.**
- Todos por keywords temáticas en comunicados legítimos: fármacos (FDA, opioides,
  clínicas de disfunción eréctil), apuestas (sponsor de LaLiga), "online casino".
  40 casi (score 3-4): sobre todo hoteles casino con "casino" en el título.
- Señales estructurales limpias: hidden_link 0, code 0, foreign_script 0,
  author_missing 1 (un borrador). ext_domains en 160 posts (comunicados con muchos links).
- Conclusión: en sitios de prensa/noticias las keywords temáticas solas no pueden
  alcanzar el umbral.

### Ajuste de puntaje (aprobado por JAZ, 2026-10-07)
- Frases de spam (+5, alcanzan solas) separadas de palabras de tema (+1, tope 3).
  Código ofuscado +5, alfabeto ajeno +4, ext_domains +1/+2, sin bonus por título.
- Docker: suite completa OK (E1/E2/E3, allowlist) + 5 réplicas de los falsos
  positivos de hispanicprwire sin marcar. Lint PHP 7.0 OK.
- hispanicprwire re-corrido: **0 sospechosos** (antes 8), casi 4 (antes 40),
  peor caso 15 → 3. 30.843 posts en 149 s, 51 MB.
- [ ] Siguiente: elimpulso (noticias, otro perfil) con el mismo dry-run.
