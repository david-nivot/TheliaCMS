#!/usr/bin/env bash
# Puts back the sample site as save.sh kept it. It replaces the WHOLE development
# database (pages, settings, administrators); the database replaced is kept next
# to the save first. DDEV host; run from the root of the Thelia install:
#
#     local/modules/TheliaCMS/Tests/Browser/sample-site/restore.sh [directory]
set -euo pipefail
directory="${1:-var/theliacms-sample-site}"
[ -f "$directory/db.sql.gz" ] || { echo "No $directory/db.sql.gz: run save.sh first." >&2; exit 1; }
ddev exec "mysqldump db | gzip > $directory/db-before-restore-\$(date +%Y%m%d-%H%M%S).sql.gz"
ddev import-db --file="$directory/db.sql.gz"
tar xzf "$directory/images.tar.gz"
ddev exec "php Thelia cache:clear >/dev/null && php Thelia cache:pool:clear cache.app >/dev/null"
echo "Sample site restored. If the module changed version since the save: ddev exec php Thelia module:refresh"
