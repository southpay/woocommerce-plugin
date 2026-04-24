#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_NAME="southpay-gateway-for-woocommerce"
OUT="${PLUGIN_DIR}/../${PLUGIN_NAME}.zip"

rm -f "$OUT"

python3 - "$PLUGIN_DIR" "$PLUGIN_NAME" "$OUT" <<'EOF'
import sys, os, zipfile

plugin_dir, plugin_name, out = sys.argv[1], sys.argv[2], sys.argv[3]
parent = os.path.dirname(plugin_dir)

with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(plugin_dir):
        # Skip dot directories in-place so os.walk doesn't descend into them
        dirs[:] = [d for d in dirs if not d.startswith('.')]
        for file in files:
            if file.startswith('.') or file == 'build-zip.sh':
                continue
            abs_path = os.path.join(root, file)
            arc_path = os.path.join(plugin_name, os.path.relpath(abs_path, plugin_dir))
            zf.write(abs_path, arc_path)

print(f"Created: {out}")
EOF
