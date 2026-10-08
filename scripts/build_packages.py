#!/usr/bin/env python3
"""Build CHIM - Promise Engine release files.

Outputs (in dist/):
  chim_promise_engine-<version>.dwpkg   server package (schema 4) that CHIM installs automatically
  chim_promise_engine.tar.gz   server archive for the Server Plugins "Update" button (legacy manifest channel)
  chim-plugin.tar.gz          server files at the archive root, for CHIM Plugin Manager (GitHub topic chim-plugin)
  CHIM-Promise-Engine-<version>.zip  MO2/Vortex archive (embedded .dwpkg, CHIM installs it on game start)

Usage:
  python scripts/build_packages.py [--check-only]
"""
from __future__ import annotations

import argparse
import hashlib
import io
import json
import re
import sys
import tarfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / "dist"
PLUGIN_NAME = "chim_promise_engine"
MOD_NAME = "CHIM-Promise-Engine"

# Files that make up the server extension (installed into HerikaServer/ext/chim_promise_engine/).
SERVER_FILES = ["manifest.json", "README.md", "ROADMAP.md", "AGENTS.md", "LICENSE", "CHANGELOG.md", "index.php", "preprocessing.php", "postrequest.php"]
SERVER_DIRS = ["lib", "migrations"]
HOOK_NAMES = {
    "globals.php", "preprocessing.php", "prerequest.php", "context_pre.php", "context.php",
    "context_building.php", "prompts.php", "dialogue_prompt.php", "json_response_custom.php",
    "prepostrequest.php", "postrequest.php", "functions.php",
}


def read_versions() -> dict[str, str]:
    versions = {
        "manifest.json": json.loads((ROOT / "manifest.json").read_text(encoding="utf-8"))["version"],
        "dwemer-package.json": json.loads((ROOT / "dwemer-package.json").read_text(encoding="utf-8"))["version"],
    }
    lib = (ROOT / "lib" / "promise_engine.php").read_text(encoding="utf-8")
    versions["lib/promise_engine.php"] = re.search(r"define\('CPE_VERSION',\s*'([^']+)'\)", lib).group(1)
    return versions


def server_payload() -> list[tuple[str, bytes]]:
    files: list[tuple[str, bytes]] = []
    for name in SERVER_FILES:
        files.append((name, (ROOT / name).read_bytes()))
    for directory in SERVER_DIRS:
        for path in sorted((ROOT / directory).rglob("*")):
            if path.is_file():
                rel = path.relative_to(ROOT).as_posix()
                if path.name in HOOK_NAMES:
                    sys.exit(f"error: {rel} has a HerikaServer hook name and would be loaded as a hook")
                files.append((rel, path.read_bytes()))
    return files


def zip_bytes(entries: list[tuple[str, bytes]]) -> bytes:
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        for name, data in entries:
            info = zipfile.ZipInfo(name, date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o644 << 16
            archive.writestr(info, data)
    return buffer.getvalue()


def build_dwpkg(version: str) -> bytes:
    manifest = json.loads((ROOT / "dwemer-package.json").read_text(encoding="utf-8"))
    manifest["version"] = version
    entries = [("manifest.json", (json.dumps(manifest, indent=2) + "\n").encode("utf-8"))]
    entries += [(f"server/{name}", data) for name, data in server_payload()]
    checksums = "".join(f"{hashlib.sha256(data).hexdigest()}  {name}\n" for name, data in entries)
    entries.append(("checksums.sha256", checksums.encode("utf-8")))
    return zip_bytes(entries)


def build_server_tar(prefix: str = f"{PLUGIN_NAME}/") -> bytes:
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz") as archive:
        for name, data in server_payload():
            info = tarfile.TarInfo(f"{prefix}{name}")
            info.size = len(data)
            info.mode = 0o644
            info.mtime = 1767225600
            archive.addfile(info, io.BytesIO(data))
    return buffer.getvalue()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check-only", action="store_true")
    args = parser.parse_args()

    versions = read_versions()
    unique = set(versions.values())
    if len(unique) != 1:
        for where, value in versions.items():
            print(f"  {where}: {value}")
        sys.exit("error: version numbers differ")
    version = unique.pop()
    print(f"CHIM - Promise Engine {version}")
    if args.check_only:
        server_payload()
        return 0

    DIST.mkdir(exist_ok=True)
    dwpkg = build_dwpkg(version)
    (DIST / f"{PLUGIN_NAME}-{version}.dwpkg").write_bytes(dwpkg)
    (DIST / f"{PLUGIN_NAME}.tar.gz").write_bytes(build_server_tar())
    (DIST / "chim-plugin.tar.gz").write_bytes(build_server_tar(prefix=""))  # CHIM Plugin Manager: files at the root
    archive = zip_bytes([(f"CHIM/server-plugins/{PLUGIN_NAME}/{version}.dwpkg", dwpkg)])
    for name in (f"{MOD_NAME}-{version}.zip", f"{MOD_NAME}.zip"):  # versioned + stable "latest" name
        (DIST / name).write_bytes(archive)
    for path in sorted(DIST.iterdir()):
        print(f"  dist/{path.name}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
