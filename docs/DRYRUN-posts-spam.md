# Ejecución en seco del detector de posts spam en un sitio real

Corre el mismo código de puntaje del plugin (`includes/content.php`) sobre los posts
de un sitio y muestra qué marcaría. **Solo lectura:** no copia archivos al server,
no escribe options, no agenda cron y no toca posts. No hace falta tener instalado
el plugin 1.2.0 (con 1.0.x activo funciona; con ≥ 1.2.0 instalado NO, porque
redeclararía las funciones).

**Target:** el server del sitio, por SSH. **Precondición:** wp-cli en el server y un
usuario con lectura del docroot (alcanza un usuario read-only como `wpaudit`).

## Paso 0: armar el script (en munich, desde la raíz del repo)

```bash
sh tools/build-dryrun.sh        # genera tools/content-dryrun.build.php (gitignored)
```

Hay que volver a armarlo después de cualquier cambio en `includes/content.php`.

## Paso 1: preflight (read-only)

```bash
ssh <host> 'cd <docroot> && whoami && wp --version \
  && wp plugin list --name=zbx-wp-monitor --fields=name,status,version \
  && wp post list --post_type=post,page --post_status=publish,future,draft,pending,private --format=count'
```

Confirmar que el plugin no es ≥ 1.2.0 y anotar cuántos posts hay (para estimar el tiempo).

## Paso 2: corrida acotada (los 2.000 posts más nuevos)

```bash
ssh <host> 'cd <docroot> && wp eval-file - 2000 0.2' < tools/content-dryrun.build.php
```

Args: `límite` (posts por sitio, del más nuevo al más viejo; `0` = todos) y
`pausa` en segundos entre lotes de 200 (cuida la base de Prod). Mirar el tiempo y
la memoria de la última línea antes de ir por todo.

## Paso 3: barrido completo

```bash
ssh <host> 'cd <docroot> && wp eval-file - 0 0.2' < tools/content-dryrun.build.php > /tmp/<sitio>-dryrun.txt
```

Referencia: hispanicprwire, 30.843 posts en 130 s, 51 MB, sin carga visible.

## Cómo leer la salida

- `SOSPECHOSOS`: lo que el plugin reportaría a Zabbix. Cada uno: `[blog] #ID tipo/estado score | motivos | título`.
- `CASI` (score 3-4): lo que está cerca del umbral; sirve para ver si un ajuste lo empujaría arriba.
- `Señales disparadas`: en cuántos posts saltó cada señal. Una señal que salta en
  cientos de posts legítimos está mal calibrada para ese tipo de sitio.

## Variantes

- elimpulso: `ssh audit-elimp 'cd /usr/share/nginx/html && wp --allow-root eval-file - 2000 0.2' < ...`
- Multisite (usabox): `wp --url=https://www.usabox.com/ eval-file - ...` (recorre todos los subsitios).
