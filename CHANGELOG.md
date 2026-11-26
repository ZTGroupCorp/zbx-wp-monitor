# Changelog

## 1.2.2 — 2026-11-25

`content_suspect` trae título y URL de cada sospechoso, para que la alerta de Zabbix sea
accionable sin buscar el post (pedido del proyecto Zabbix, 2026-11-25).

- **`url`** en cada elemento: permalink si el post está publicado; si no (borrador,
  pendiente, privado, programado), la URL de edición en wp-admin. En multisite se resuelve
  en el blog del post, así que lleva el dominio real del subsitio. La URL de edición se arma
  a mano: `get_edit_post_link()` devuelve vacío sin usuario logueado (el endpoint lo llama
  Zabbix con el token).
- **`title`** solo para posts publicados (decisión de JAZ: los títulos de borradores y
  privados no salen del sitio); vacío en los demás. Texto plano de una línea (sin HTML, sin
  entidades, sin caracteres de control), tope 80 caracteres sin cortar UTF-8.
- Se resuelven en el endpoint para los hasta 20 de la lista, no en el barrido: siempre al
  día y sin migrar los sospechosos ya guardados.
- Aditivo: los campos existentes no cambian. **Aviso para Zabbix:** el título lo escribe
  quien publicó el post; si el preprocesado lo agrega a la línea sobre la que corre la regex
  del trigger (`code:|score ...`), un título como "Promo code: 50%" lo dispararía. La regex
  tiene que evaluarse solo sobre `score` y `reasons`.

## 1.2.1 — 2026-11-16

Ajustes del detector de posts spam con datos de la red multisite de hdn (wordpress2).

- **`author_missing` ignora autores "heredados":** un autor inexistente con 20 o más
  posts en el sitio es un resto de migración o de usuarios borrados en bloque, no una
  inserción por SQL. En hdn eran 3.545 de 3.575 sospechosos (3.288 del autor 40 en
  rosemarynews). Se calcula con una consulta agrupada por `post_author`, una vez por
  sitio y por corrida.
- **Spam de casinos en ruso:** "казино" como palabra de tema y "официальное/рабочее
  зеркало" ("espejo oficial") como frase de spam.
- **Alfabeto ajeno en dos niveles:** más del 50% de las letras en otro alfabeto alcanza
  solo (+5); entre 20% y 50% (nota con citas) sigue en +4. En miciudadtv (blog 45) había
  64 posts en cirílico (casinos y apuestas): la 1.2.0 marcaba 25, la 1.2.1 marca 62,
  sin ningún post normal del sitio.
- Prueba en seco en hdn (miciudadtv, prensamexicana, rosemarynews; 87.954 posts): de
  3.559 sospechosos a 46 antes del ajuste de alfabeto.
- Palabras clave normalizadas en UTF-8 cuando hay `mbstring` ("Казино" y "казино"
  cuentan una vez).
- **Barrido completo al cambiar de versión:** el resultado guarda la versión del plugin
  que lo produjo; si no coincide con la instalada, la siguiente corrida descarta las
  marcas y revisa todos los posts con el criterio nuevo. Sin esto, los posts viejos que
  la 1.2.0 dejó pasar (los 39 de casinos en ruso) nunca se volvían a mirar. Ese día
  `content_new_24h` da 0, como en cualquier barrido completo.
- `tools/`: la prueba en seco funciona también en sitios con el plugin ≥ 1.2.0 instalado
  (renombra sus funciones al armarse) y acepta una lista de blogs.

## 1.2.0 — 2026-10-15

Detección diaria de **posts spam** (contenido inyectado en un sitio comprometido:
casino, farmacia, "slot gacor", links ocultos). Solo lectura: detecta y reporta,
nunca toca los posts. Se salta la 1.1.0 (Fase 1: DB/cache/Wordfence, sin empezar)
para no mezclar los dos cambios en un mismo release.

- **Job diario** `ztgrp_monitor_content_run` (`includes/content.php`), por lotes con
  cursor como la integridad. Revisa posts nuevos **por ID** (no por fecha: el spam
  inyectado suele venir con fecha vieja), posts viejos modificados desde la corrida
  anterior y re-evalúa los sospechosos ya registrados. La primera corrida barre todo.
- **Puntaje** (umbral 5): frases que solo usa el spam ("slot gacor", "buy cialis",
  "viagra sin receta") y código ofuscado alcanzan solos; palabras de tema (casino,
  viagra, betting) suman +1 con tope 3 y nunca alcanzan solas; link oculto por CSS,
  autor inexistente (inserción directa por SQL), alfabeto ajeno al locale,
  `<script>` ajeno a embeds conocidos y ≥10 dominios externos suman. Calibrado en
  seco sobre hispanicprwire (de 8 falsos positivos a 0 en 30.843 posts) y elimpulso
  (274.480 posts: 6 sospechosos, los 6 reales: un post spam y 5 páginas con JS
  malicioso inyectado).
- **Registro persistente**: el sospechoso alerta hasta que se revisa, se borra o se
  limpia, no solo el día que apareció.
- **Claves nuevas** (aditivas): `content_suspect_count`, `content_suspect` (tope 20:
  blog, id, tipo, estado, puntaje, motivos; sin títulos ni contenido),
  `content_new_24h`, `content_new_avg_30d`, `content_checked_at`.
- **Settings**: lista de sospechosos con link de edición y botón "Marcar revisado"
  (deja de alertar mientras el post no se vuelva a editar), allowlist de dominios y
  palabras por sitio (guardarla re-barre todo), "Ejecutar revisión ahora".
- **Multisite**: el sitio principal recorre todos los subsitios; cada sospechoso
  lleva su `blog`.
- El job se agenda también en `init` si falta: el auto-update de la flota no dispara
  el hook de activación.

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
