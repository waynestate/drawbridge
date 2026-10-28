# DRAWBRIDGE README art kit

Regenerates the README images under `docs/readme/` from this repo's own front
page (`site/`), its source and its example config. Nothing is drawn by hand:
the hero and the gallery are screenshots of the real page, `gatehouse.svg`
reuses the page's own section A-A linework and takes every label from the
source (`src/Http/HeaderPolicy.php`, `src/Render/ApacheRenderer.php`,
`src/Assertion/AssertionSigner.php`, `config/drawbridge.example.yaml` and the
page's door schedule), the cast is the marks resized, and the badges read
their facts from `composer.json` and the source. If the source changes shape,
the build fails rather than draw a stale label.

## Run

Windows PowerShell and Linux/macOS sh, side by side:

| Windows PowerShell | Linux / macOS sh |
|---|---|
| `npm --prefix scripts/readme-art ci` | `npm --prefix scripts/readme-art ci` |
| `npx --prefix scripts/readme-art playwright install chromium` | `npx --prefix scripts/readme-art playwright install chromium` |
| `node scripts/readme-art/build.mjs` | `node scripts/readme-art/build.mjs` |

The script resolves every path from its own location, so it works from any
folder on any OS. It opens `site/index.html` as a `file://` URL: no server, no
network. Same inputs, same outputs.

## What it makes

- `docs/readme/hero.jpg`: the hero sheet, with the site header hidden.
- `docs/readme/gatehouse.svg`: section A-A, detail 1 enlarged. It inks itself
  in, strikes out a forged header and labels the guard, the OpenID Connect
  client and the signer. It holds still for `prefers-reduced-motion: reduce`.
  Fonts are embedded; it sits on its own opaque sheet so it reads on light and
  dark pages.
- `docs/readme/gallery.jpg`: a contact sheet of three existing mobile crops.
- `docs/readme/board.jpg`: the brand board, re-encoded.
- `docs/readme/cast/*.png`: one 96x96 mark per stack part.
- `docs/readme/badges/*.svg`: `php`, `licence` and `assertion`, made locally by
  `badge-maker`.

Every file must stay at or under 300 KB, and no captured text may name an
internal host; the script exits 1 otherwise. A drawbridge that lets the
internal names across is just a ramp.
