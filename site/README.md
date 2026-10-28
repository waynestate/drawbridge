# DRAWBRIDGE front page

A static page: `index.html`, `assets/drawbridge.css`, `assets/drawbridge.js`. No build step. Serve the `site/` folder
from anywhere (Citadel, any web server, or `python -m http.server` inside `site/` for a look; fonts are blocked over
`file://` in some browsers).

- type: **Big Shoulders Stencil Display** 600/800/900 for display and **Architects Daughter** for the hand-lettered
  annotations (both SIL OFL 1.1, self-hosted in `assets/fonts/` from `@fontsource`, licences beside them); Lato from
  WayneUI for body text; the system monospace for commands.
- world: an architect's sheet set for one gatehouse. Drafting vellum with a fine grid, green ink linework, slate
  construction lines, gold only for the chain and for signed things. Every section is a numbered sheet (A-101 to
  A-801) and the footer is the drawing's title block.
- base: WayneUI 2.0.8, copied from its static path into `assets/wayne-ui/` (tokens, Lato, container, buttons, tabs,
  table wrapper, skip link, sr-only), then re-dressed in `assets/drawbridge.css`.
- magic: the hero is section A–A through the gatehouse, with the mark in a detail callout ("1 · enlarged"). The
  sheet inks itself in, forged identity headers are thrown at the raised bridge and bounce off into the moat, then a
  visitor signs in (an OpenID Connect round trip to Entra ID, off this sheet, by others), the bridge comes down on its gold chain,
  and the visitor walks through the guard, the OpenID Connect client and the signer, picking up a `drawbridge+jwt`. **Sign in / Sign
  out** and **Forge a header** replay it; with the bridge down, a forgery walks in and is stripped by the guard.
- door schedule: the path box runs the same matching as `src/Http/PathNormalizer.php` and `PathRule.php` in the
  browser, for the example config, and prints the real `drawbridge explain` wording (checked against the command).
- marks: `brand/` is DRAWBRIDGE's (generated with ChatGPT, never redrawn). `assets/marks/` holds the other stack
  parts' marks, copied from the sibling sites; WayneUI uses its own reversed mark on green.
- reduced motion: the page is the finished drawing, every word present at first paint: bridge down, visitor inside
  with a fresh assertion. The buttons still work, instantly, and the status line says what happened.

Screenshots are in `screenshots/` (desktop 1440, mobile 390, reduced motion, a motion GIF, and per-section mobile
crops in `screenshots/sections/`).

Drawn, not quartered.

## Links between the stack sites

Links to the other parts are committed as production URLs (`https://<project>.apps.wayne.edu/`), so crawlers and
visitors without JavaScript always get production. `assets/stack-env.js` (the same file on every stack site) rewrites
them to `http://127.0.0.1:18432/<project>/site/` in development, for a static server whose root is the
folder that holds all the projects side by side. Dev is picked automatically on `localhost` or `127.0.0.1`; `?env=dev` or `?env=production` forces
one (remembered until `?env=auto`), `<meta name="stack-env">` sets the page default, and `?devBase=http://127.0.0.1:<port>`
points at a server on another port. WayneUI links stay on production.
