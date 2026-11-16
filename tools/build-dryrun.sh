#!/bin/sh
# Arma tools/content-dryrun.build.php = includes/content.php + tools/content-dryrun.php
# (sin el segundo "<?php"), para mandarlo por stdin a `wp eval-file -`.
#
# Las funciones (llamadas y definiciones: nombre seguido de "(") y constantes del
# detector se renombran a ztgrp_dryrun_content_* / ZTGRP_DRYRUN_CONTENT_*, así el
# script corre también en sitios que ya tienen el plugin >= 1.2.0 sin redeclarar
# nada. Los nombres entre comillas (options, filtros, hooks) no se tocan: la copia
# lee la misma allowlist y los mismos revisados que el plugin del sitio.
#
# Uso (desde la raíz del repo): sh tools/build-dryrun.sh
set -e
cd "$(dirname "$0")/.."
OUT=tools/content-dryrun.build.php
{
	cat includes/content.php
	printf '\n'
	tail -n +2 tools/content-dryrun.php
} | sed -E \
	-e 's/\bztgrp_monitor_content_([a-z_]+)[[:space:]]*\(/ztgrp_dryrun_content_\1(/g' \
	-e 's/\bZTGRP_MONITOR_CONTENT_/ZTGRP_DRYRUN_CONTENT_/g' > "$OUT"
echo "$OUT"
