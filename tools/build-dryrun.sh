#!/bin/sh
# Arma tools/content-dryrun.build.php = includes/content.php + tools/content-dryrun.php
# (sin el segundo "<?php"), para mandarlo por stdin a `wp eval-file -`.
# Uso (desde la raíz del repo): sh tools/build-dryrun.sh
set -e
cd "$(dirname "$0")/.."
OUT=tools/content-dryrun.build.php
{
	cat includes/content.php
	printf '\n'
	tail -n +2 tools/content-dryrun.php
} > "$OUT"
echo "$OUT"
