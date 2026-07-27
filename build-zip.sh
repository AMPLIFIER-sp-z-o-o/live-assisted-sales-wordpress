#!/bin/sh
# Builds the installable WordPress plugin ZIPs into dist/:
#   amper-live-assisted-sales.zip  - the product plugin
#   amper-demo-language.zip        - the demo store's PL/EN switcher
# Used locally and by the Jenkins pipeline. Uses `zip` when present and falls back to Python's
# zipfile (WSL images ship Python but often not zip - and a missing zip used to leave dist/ empty
# because the old script deleted the previous artifact before failing).
set -eu
cd "$(dirname "$0")"
mkdir -p dist

pack() { # <source-parent-dir> <plugin-dir-name>
  parent="$1"
  name="$2"
  out="dist/$name.zip"
  tmp="$out.tmp"
  rm -f "$tmp"
  if command -v zip >/dev/null 2>&1; then
    ( cd "$parent" && zip -rq "$OLDPWD/$tmp" "$name" )
  else
    python3 - "$parent" "$name" "$tmp" <<'PY'
import os, sys, zipfile
parent, name, out = sys.argv[1], sys.argv[2], sys.argv[3]
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk(os.path.join(parent, name)):
        dirs.sort()
        for f in sorted(files):
            p = os.path.join(root, f)
            z.write(p, os.path.relpath(p, parent))
PY
  fi
  mv "$tmp" "$out"
  echo "Built: $out ($(wc -c < "$out") bytes)"
}

pack plugin amper-live-assisted-sales
pack demo-plugins amper-demo-language
