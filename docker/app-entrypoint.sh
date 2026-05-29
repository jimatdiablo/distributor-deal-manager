#!/bin/sh
set -eu

if [ "${DDM_RUN_MIGRATIONS:-true}" != "false" ]; then
  php /app/tools/migrate.php
fi

exec "$@"
