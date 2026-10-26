# Vulnerable dependencies

Checked against OSV.dev on 2026-10-26T21:25: 231 packages, 6 advisories, gate **PASS** — No open vulnerability in the dependencies.

## By package

| Package | Version | Severity | Advisories | Fixed in | Fix |
| --- | --- | --- | --- | --- | --- |
| vite (npm, direct) | 8.2.2 | High | GHSA-587h-7wmj-h5pm, GHSA-698v-4r3x-3vcv, GHSA-9jrq-w75r-8gcw, GHSA-r825-w8mm-5pxw, GHSA-rq7h-c2jc-7f22, GHSA-vfpm-58rq-9qcg | 8.2.4 | `npm update vite` |

## GHSA-587h-7wmj-h5pm — vite 8.2.2

- Severity: High
- Also known as: CVE-2026-112942
- Fixed in: 8.2.4
- Decision: waived — vite 8.2.2 build-time dev server only (launch-editor, Windows); the host serves pre-built assets and never runs vite. Owner waived for v3.0.0 on 2026-10-26; bump to 8.2.4 in 3.0.1.
- Advisory: https://osv.dev/vulnerability/GHSA-587h-7wmj-h5pm

launch-editor: `fs.existsSync()` existence check can be bypassed on Windows

## GHSA-698v-4r3x-3vcv — vite 8.2.2

- Severity: Medium
- Also known as: CVE-2026-112943
- Fixed in: 8.2.4
- Decision: waived — vite 8.2.2 build-time dev server only (launch-editor, Windows); the host serves pre-built assets and never runs vite. Owner waived for v3.0.0 on 2026-10-26; bump to 8.2.4 in 3.0.1.
- Advisory: https://osv.dev/vulnerability/GHSA-698v-4r3x-3vcv

launch-editor: escapeCmdArgs() omits % escaping on Windows

## GHSA-r825-w8mm-5pxw — vite 8.2.2

- Severity: Medium
- Also known as: CVE-2026-112944
- Fixed in: 8.2.4
- Decision: waived — vite 8.2.2 build-time dev server only (launch-editor, Windows); the host serves pre-built assets and never runs vite. Owner waived for v3.0.0 on 2026-10-26; bump to 8.2.4 in 3.0.1.
- Advisory: https://osv.dev/vulnerability/GHSA-r825-w8mm-5pxw

launch-editor: Editor CLI flag misinterpretation causing arbitrary command execution for some editors

## GHSA-rq7h-c2jc-7f22 — vite 8.2.2

- Severity: Medium
- Also known as: CVE-2026-112946
- Fixed in: 8.2.4
- Decision: waived — vite 8.2.2 build-time dev server only (launch-editor, Windows); the host serves pre-built assets and never runs vite. Owner waived for v3.0.0 on 2026-10-26; bump to 8.2.4 in 3.0.1.
- Advisory: https://osv.dev/vulnerability/GHSA-rq7h-c2jc-7f22

Vite: Files outside `server.fs.allow` could be served when project paths match system paths on non-Windows machine

## GHSA-vfpm-58rq-9qcg — vite 8.2.2

- Severity: Medium
- Also known as: CVE-2026-112947
- Fixed in: 8.2.4
- Decision: waived — vite 8.2.2 build-time dev server only (launch-editor, Windows); the host serves pre-built assets and never runs vite. Owner waived for v3.0.0 on 2026-10-26; bump to 8.2.4 in 3.0.1.
- Advisory: https://osv.dev/vulnerability/GHSA-vfpm-58rq-9qcg

Vite: First 4 bytes of files outside of `server.fs` setting is exposed by `?vite-wasm-instance`

## GHSA-9jrq-w75r-8gcw — vite 8.2.2

- Severity: Low
- Also known as: CVE-2026-112945
- Fixed in: 8.2.4
- Decision: waived — vite 8.2.2 build-time dev server only (launch-editor, Windows); the host serves pre-built assets and never runs vite. Owner waived for v3.0.0 on 2026-10-26; bump to 8.2.4 in 3.0.1.
- Advisory: https://osv.dev/vulnerability/GHSA-9jrq-w75r-8gcw

Vite: Cross origin script execution through Vite custom middleware
