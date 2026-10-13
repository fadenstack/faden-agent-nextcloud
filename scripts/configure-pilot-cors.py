"""Opt in the Nextcloud pilot's browser SDK routes at Fadenstack's nginx edge.

Run on the Fadenstack host. Preview is the default; --apply validates and reloads
nginx, with dated backups and rollback on a configuration error. No secrets read.
Requires nginx >= 1.29.3 (add_header_inherit merge preserves security headers).
"""
from __future__ import annotations

import argparse
import difflib
import re
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlsplit


def origin(value: str) -> str:
    url = urlsplit(value)
    if url.scheme not in {"http", "https"} or not url.hostname or url.username or url.password:
        raise ValueError("Use an explicit HTTP(S) origin without credentials")
    if url.path not in {"", "/"} or url.query or url.fragment:
        raise ValueError("An origin must not contain a path, query or fragment")
    if url.scheme == "http" and url.hostname not in {"localhost", "127.0.0.1", "::1"}:
        raise ValueError("HTTP is permitted only for a localhost development origin")
    if not re.fullmatch(r"[a-zA-Z0-9.:[\]-]+", url.netloc) or (url.port and not 1 <= url.port <= 65535):
        raise ValueError("Invalid origin")
    return f"{url.scheme}://{url.netloc.lower()}"


def command(*args: str) -> str:
    return subprocess.check_output(args, text=True, stderr=subprocess.STDOUT)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--nginx-dir", type=Path, required=True)
    parser.add_argument("--agent", required=True)
    parser.add_argument("--origin", action="append", required=True)
    parser.add_argument("--container", default="faden-nginx")
    parser.add_argument("--apply", action="store_true")
    args = parser.parse_args()
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]{0,127}", args.agent):
        raise ValueError("Invalid agent identifier")
    allowed = sorted({origin(value) for value in args.origin})
    root = args.nginx_dir.resolve(strict=True)
    files = ["nginx.conf", "nginx-tls.conf", "locations.conf"]
    old = {name: (root / name).read_text() for name in files}
    changes = dict(old)
    map_include = "include /etc/nginx/faden/nextcloud-cors-map.conf;\n"
    for name in files[:2]:
        if map_include not in changes[name]:
            changes[name] = map_include + changes[name]
    headers_include = "    include /etc/nginx/faden/nextcloud-cors-headers.conf;\n"
    for location in ["/api/", "/v1/", "@faden_maintenance_api", "@faden_maintenance_openai", "@faden_too_many"]:
        marker = f"location {location} {{\n"
        if marker not in changes["locations.conf"]:
            raise ValueError(f"Expected nginx location missing: {location}")
        if marker + headers_include not in changes["locations.conf"]:
            changes["locations.conf"] = changes["locations.conf"].replace(marker, marker + headers_include, 1)
    changes["nextcloud-cors-map.conf"] = """# Nextcloud browser SDK only. No console/admin cookie CORS.
map $uri $faden_nextcloud_sdk_path {
    default 0;
    /api/auth/jwt/login 1;
    /api/agent-tokens 1;
    /api/agent-tokens/refresh 1;
    ~^/v1/agents/AGENT(?:/|$) 1;
}
map "$faden_nextcloud_sdk_path:$http_origin" $faden_nextcloud_origin {
    default "";
ORIGINS
}
map "$request_method:$faden_nextcloud_origin" $faden_nextcloud_preflight {
    default 0;
    ~^OPTIONS:.+ 1;
}
map $faden_nextcloud_origin $faden_nextcloud_methods {
    default "";
    ~.+ "GET, POST, PUT, DELETE, OPTIONS";
}
map $faden_nextcloud_origin $faden_nextcloud_headers {
    default "";
    ~.+ "Authorization, Content-Type, X-Faden-Tool-Mode, X-Faden-Events, X-Faden-Conversation-Id, X-Faden-Turn-Id";
}
map $faden_nextcloud_origin $faden_nextcloud_cache {
    default "";
    ~.+ 600;
}
""".replace("AGENT", re.escape(args.agent)).replace("ORIGINS", "\n".join(f'    "1:{item}" "{item}";' for item in allowed))
    changes["nextcloud-cors-headers.conf"] = """# Merge preserves inherited HSTS, CSP and other security headers.
add_header_inherit merge;
add_header Access-Control-Allow-Origin $faden_nextcloud_origin always;
add_header Access-Control-Allow-Methods $faden_nextcloud_methods always;
add_header Access-Control-Allow-Headers $faden_nextcloud_headers always;
add_header Access-Control-Max-Age $faden_nextcloud_cache always;
add_header Vary "Origin" always;
# Deliberately no Access-Control-Allow-Credentials: the SDK omits cookies.
if ($faden_nextcloud_preflight) {
    return 204;
}
"""
    for name, content in changes.items():
        target = root / name
        if target.parent.resolve() != root or target.is_symlink():
            raise ValueError("Configuration target escaped nginx directory")
        before = target.read_text() if target.exists() else ""
        print("".join(difflib.unified_diff(before.splitlines(True), content.splitlines(True), fromfile=name, tofile=name)), end="")
    if not args.apply:
        print("Preview only; no server changes. Add --apply to validate and reload.")
        return
    version = command("docker", "exec", args.container, "nginx", "-v")
    number = re.search(r"nginx/(\d+)\.(\d+)\.(\d+)", version)
    if not number or tuple(map(int, number.groups())) < (1, 29, 3):
        raise ValueError("nginx >= 1.29.3 is required")
    selector = "/etc/nginx/faden/select-config.sh"
    command("docker", "exec", args.container, "test", "-f", selector)
    select_config = ("docker", "exec", args.container, "/bin/sh", selector)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    original = {}
    for name in changes:
        target = root / name
        original[name] = target.read_bytes() if target.exists() else None
        if target.exists():
            shutil.copy2(target, root / f"{name}.before-nextcloud-{stamp}")
    try:
        for name, content in changes.items():
            (root / name).write_text(content)
        print(command(*select_config))
        print(command("docker", "exec", args.container, "nginx", "-t"))
        print(command("docker", "exec", args.container, "nginx", "-s", "reload"))
    except Exception:
        for name, content in original.items():
            if content is None:
                (root / name).unlink(missing_ok=True)
            else:
                (root / name).write_bytes(content)
        command(*select_config)
        raise
    print(f"CORS applied for agent {args.agent} and origins: {', '.join(allowed)}. Backups: *.before-nextcloud-{stamp}")


if __name__ == "__main__":
    main()
