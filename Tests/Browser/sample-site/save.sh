#!/usr/bin/env bash
# Keeps the sample site made by seed.php: the whole development database and the
# files of the images it made. DDEV host; run from the root of the Thelia install:
#
#     local/modules/TheliaCMS/Tests/Browser/sample-site/save.sh [directory]
set -euo pipefail
directory="${1:-var/theliacms-sample-site}"
[ -f "$directory/manifest.json" ] || { echo "No $directory/manifest.json: run seed.php first." >&2; exit 1; }
ddev exec "mysqldump db | gzip > $directory/db.sql.gz"
python3 -c "import json,sys;print('\n'.join('local/library/images/'+f for f in json.load(open(sys.argv[1]))['image_files']))" "$directory/manifest.json" > "$directory/images.list"
tar czf "$directory/images.tar.gz" -T "$directory/images.list"
ls -la "$directory/db.sql.gz" "$directory/images.tar.gz"
