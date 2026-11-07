#!/usr/bin/env python3
"""Resolve the WordPress.org archive URL for the locked Estatik release."""
import json
import pathlib
import re
import sys
from urllib.parse import urlparse

if len(sys.argv) != 4:
    raise SystemExit("usage: resolve-estatik-download.py API_JSON EXPECTED_VERSION URL_OUTPUT")
metadata_path = pathlib.Path(sys.argv[1])
expected_version = sys.argv[2]
url_output = pathlib.Path(sys.argv[3])
if not re.fullmatch(r"[0-9A-Za-z.+_-]+", expected_version):
    raise SystemExit("locked Estatik version is malformed")

try:
    metadata = json.loads(metadata_path.read_text(encoding="utf-8"))
except (OSError, json.JSONDecodeError) as exc:
    raise SystemExit(f"WordPress.org Estatik metadata is unreadable: {type(exc).__name__}")

if metadata.get("slug") != "estatik":
    raise SystemExit("WordPress.org returned metadata for a different plugin slug")
versions = metadata.get("versions")
url = versions.get(expected_version) if isinstance(versions, dict) else None
if not isinstance(url, str) or not url:
    if str(metadata.get("version", "")) == expected_version:
        url = metadata.get("download_link")
if not isinstance(url, str) or not url:
    raise SystemExit(f"WordPress.org did not provide the locked Estatik archive version {expected_version}")

parsed = urlparse(url)
if parsed.scheme != "https" or parsed.hostname != "downloads.wordpress.org" or parsed.username or parsed.password:
    raise SystemExit("Estatik archive URL is outside the approved HTTPS WordPress.org download host")
url_output.write_text(url, encoding="utf-8")
print(f"Resolved WordPress.org archive for locked Estatik {expected_version}.")
