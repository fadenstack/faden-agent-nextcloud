# Pilot network deployment — 13 October 2026

The app still connects directly through the published SDK to
`https://10.250.10.61`. No certificate-validation bypass, credential proxy or
cross-origin cookie support was added. The live deployment was 0.4.4rc2 during
the first connection and 0.4.4 after the host came back online. The edge is nginx
1.31.6. The host's hardware interruption was independent of Nextcloud.

## Exact-origin CORS

The installed edge policy permits these origins:

- `http://localhost:8090` (Nextcloud 35)
- `http://localhost:8088` and `http://127.0.0.1:8088` (Nextcloud 31)

It permits SDK routes `/api/auth/jwt/login`, `/api/agent-tokens`,
`/api/agent-tokens/refresh` and `/v1/agents/nextcloud-agent/...`. Administrative
and general user routes remain excluded. Requests omit cookies and carry memory
bearer tokens. Authorization and the SDK's mode/event/conversation/turn headers
are permitted; preflights return 204. Actual and error responses also carry the
scoped allowed-origin header. Existing authentication and cookie CSRF protection
remain active. This follows the [browser CORS model](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CORS).

`scripts/configure-pilot-cors.py` is an explicit deployment helper, never run by
the app. On this host the installed copy is
`/home/sachi/fadenstack/nginx/configure-nextcloud-cors.py`. Preview first:

```sh
python3 configure-nextcloud-cors.py \
  --nginx-dir /home/sachi/fadenstack/nginx \
  --agent nextcloud-agent \
  --origin http://localhost:8090 \
  --origin http://localhost:8088 \
  --origin http://127.0.0.1:8088
```

Add `--apply` to create dated backups, select the active TLS configuration,
validate it with `nginx -t`, and gracefully reload. On validation failure the
helper restores the previous files. It requires nginx 1.29.3+ because
[`add_header_inherit merge`](https://nginx.org/en/docs/http/ngx_http_headers_module.html)
preserves inherited security headers in locations that add CORS headers.

Fadenstack upgrades regenerate the edge templates. **Reapply after an upgrade**
and run the network test; a normal restart retains the policy. This was verified
when the move to 0.4.4 replaced the entries and the helper restored them.
Backups preceding that second application end in
`.before-nextcloud-20261013T062522Z`. To undo it, restore the three original
templates from the same backup timestamp, remove their CORS includes, run the
configuration selector, validate, then reload. Retain backups until validation
is complete. No container/backend restart is needed.

## Verified private CA

The public CA identifies `O=Fadenstack, CN=Fadenstack CA on llm-port`, is valid
from 11 October 2026 to 8 October 2036, and has SHA-256 fingerprint:

```text
E1:BC:B5:28:B0:A2:06:B0:D7:E3:4C:88:3E:90:89:F8:DF:D7:D8:AD:7B:0E:BE:15:9F:13:57:3C:73:B7:F1:6C
```

It was copied through an existing SSH key with strict known-host checking. The
leaf's signature, validity and IP subject-alternative name were verified against
it. Only the public CA was installed, using `certutil -user -addstore Root` in the
current Windows user's store. Other clients need their own verified trust setup;
the app does not install trust or bundle the CA. Organizations can instead use
their normal trusted HTTPS deployment.

Windows curl's Schannel backend still reports unavailable revocation information
for this private CA. Browser sign-in and Node HTTPS validation succeeded; no
revocation/certificate checks were disabled to make curl pass.

For the repeatable read-only network check, configure `NODE_EXTRA_CA_CERTS` with
the independently verified public CA, then run:

```sh
node tests/integration/network.mjs
```

The test covers 22 live endpoint/CORS/CSRF checks and sends no user credentials.
The private CA and real credentials are not committed or packaged.
