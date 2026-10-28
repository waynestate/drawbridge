#!/usr/bin/env node
/* ============================================================================
   DRAWBRIDGE README art kit.

   Renders the README images from this repo's own front page (site/), its
   source and its example config, and writes them under docs/readme/.
   Nothing is drawn by hand: the hero and the gallery are screenshots of the
   real page, the signature drawing (gatehouse.svg) reuses the page's own
   section A-A linework and takes every label from the source (the header
   policy, the Apache renderer, the assertion signer, the example config and
   the page's door schedule), the cast is the marks resized (WayneUI composed
   on its green tile), and the badges read their facts from composer.json and
   the source.

   Run from any folder (every path resolves from this file's own location):

     npm --prefix scripts/readme-art ci
     npx --prefix scripts/readme-art playwright install chromium
     node scripts/readme-art/build.mjs

   The page is opened as a file:// URL: no server, no network.
   ========================================================================== */

import { chromium } from "playwright";
import { makeBadge } from "badge-maker";
import sharp from "sharp";
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

/* ------------------------------------------------------------- paths, limits */

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, "..", "..");
const siteDir = path.join(repoRoot, "site");
const outDir = path.join(repoRoot, "docs", "readme");
const castDir = path.join(outDir, "cast");

const MAX_BYTES = 300 * 1024; // every file in docs/readme/ stays at or under this
// Internal names never ship in art: no internal hosts, and no *.wayne.edu host
// except www and git. The platform name and the gateway's own host are held
// back too, so the front page's mentions are hidden before any screenshot.
const FORBIDDEN =
  /GitLab|gitlab\.example|citadel|auth\.apps\.wayne\.edu|(?<![\w.-])(?!(?:www|git)\.wayne\.edu)(?:[a-z0-9-]+\.)+wayne\.edu/i;
const FORBIDDEN_SRC = FORBIDDEN.source;
const FORBIDDEN_FLAGS = FORBIDDEN.flags;

const outputs = [];

function record(rel, buf) {
  const abs = path.join(outDir, rel);
  mkdirSync(path.dirname(abs), { recursive: true });
  writeFileSync(abs, buf);
  outputs.push({ rel, bytes: buf.length });
  console.log(`  ${rel}  ${buf.length} bytes`);
}

function fail(msg) {
  console.error(`FAIL: ${msg}`);
  process.exit(1);
}

const read = (...p) => readFileSync(path.join(repoRoot, ...p), "utf8");

/* ------------------------------------------------------------- 1. cast strip */

// One 96x96 PNG per stack part (shown at 40 px in the README). The marks are
// copied and resized only, never redrawn. WayneUI is composed instead: its
// reverse mark (white frame, gold tab) set on a Wayne State green tile, so it
// reads as a sibling of the other thirteen.
const CAST = [
  "creed",
  "proctor",
  "sesame",
  "helmet",
  "sourdough",
  "squire",
  "preflight",
  "pinstripe",
  "booster",
  "rollcall",
  "drawbridge",
  "advisor",
  "weld",
];

function castSource(name) {
  if (name === "drawbridge") return path.join(siteDir, "brand", "drawbridge-avatar-256.png");
  return path.join(siteDir, "assets", "marks", `${name}.png`);
}

// The WayneUI tile: the reverse mark, traced from WayneUI's approved artwork,
// set on a green rounded tile. The traced paths are placed unchanged: no edits
// to any d= value, fill, or the mark's own viewBox. The visible ink spans
// 1546 x 1551 of the 1980 x 1989 viewBox and is centred in it, so this scale
// makes the mark 352 px tall on the 512 tile (about 69%), matching the square
// marks WELD and SESAME.
function wayneuiTileSvg() {
  const raw = readFileSync(path.join(scriptDir, "src", "wayneui-mark-reverse.svg"), "utf8");
  const open = raw.indexOf("<svg");
  const openEnd = raw.indexOf(">", open) + 1;
  const close = raw.lastIndexOf("</svg>");
  if (open < 0 || openEnd < 2 || close < openEnd) fail("wayneui-mark-reverse.svg: no <svg> element");
  // Everything between the opening tag and the final </svg>, verbatim (the
  // file's own line endings included, so the paths stay byte-identical).
  const inner = raw.slice(openEnd, close).replace(/^\r?\n/, "");
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="WayneUI">
  <rect width="512" height="512" rx="80" ry="80" fill="#0C5449"/>
  <svg x="31.3" y="30.3" width="449.4" height="451.4" viewBox="0 0 1980 1989">
${inner}</svg>
</svg>
`;
}

async function buildCast() {
  console.log("cast:");
  // WayneUI first: one source (the traced mark) for both the SVG and the PNG.
  const tile = Buffer.from(wayneuiTileSvg(), "utf8");
  record("cast/wayneui.svg", tile);
  const tilePng = await sharp(tile, { density: 300 })
    .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ palette: true, quality: 85, compressionLevel: 9 })
    .toBuffer();
  record("cast/wayneui.png", tilePng);
  // The old dark-variant tile is gone: the green tile works on every surface.
  const stale = path.join(castDir, "wayneui-dark.png");
  if (existsSync(stale)) {
    rmSync(stale);
    console.log("  removed cast/wayneui-dark.png (no longer generated)");
  }
  for (const name of CAST) {
    const src = castSource(name);
    if (!existsSync(src)) {
      console.log(`  skip cast/${name}.png: missing source ${path.relative(repoRoot, src)}`);
      continue;
    }
    const buf = readFileSync(src);
    const png = await sharp(buf)
      .resize(96, 96, { fit: "contain", background: { r: 0, g: 0, b: 0, alpha: 0 } })
      .png({ palette: true, quality: 85, compressionLevel: 9 })
      .toBuffer();
    record(`cast/${name}.png`, png);
  }
}

/* ---------------------------------------------------- 2. the source's facts */

// Everything the badges and the drawing say is read from the repo here, with
// a pattern that must match: if the source changes shape, the build fails
// instead of quietly printing a stale label.
function must(text, re, what) {
  const m = re.exec(text);
  if (!m) fail(`could not read ${what} (pattern ${re})`);
  return m;
}

function readFacts() {
  const composer = JSON.parse(read("composer.json"));
  const policy = read("src", "Http", "HeaderPolicy.php");
  const renderer = read("src", "Render", "ApacheRenderer.php");
  const signer = read("src", "Assertion", "AssertionSigner.php");
  const example = read("config", "drawbridge.example.yaml");

  // Owned header prefixes (the guard strips everything under each one).
  const prefixBody = must(policy, /private const PREFIXES = \[([^\]]*)\];/, "HeaderPolicy::PREFIXES")[1];
  const claimPrefix = must(policy, /private const OIDC_CLAIM_PREFIX = '([^']+)'/, "OIDC_CLAIM_PREFIX")[1];
  const prefixes = [...prefixBody.matchAll(/'([^']+)'|self::OIDC_CLAIM_PREFIX/g)].map((m) => m[1] ?? claimPrefix);
  if (prefixes.length < 4) fail("HeaderPolicy::PREFIXES has fewer than four entries");
  const prettyHeader = (p) =>
    p
      .replace(/-$/, "")
      .split("-")
      .map((w) => (w === "oidc" ? "OIDC" : w[0].toUpperCase() + w.slice(1)))
      .join("-") + "-*";

  const connection = [
    ...must(policy, /CONNECTION_TOKENS = \[([^\]]*)\]/, "CONNECTION_TOKENS")[1].matchAll(/'([^']+)'/g),
  ].map((m) => m[1]);

  // Apache directives the renderer really emits.
  must(renderer, /'OIDCResponseType code'/, "OIDCResponseType");
  must(renderer, /'OIDCPKCEMethod S256'/, "OIDCPKCEMethod");
  must(renderer, /OIDCPassClaimsAs environment none/, "OIDCPassClaimsAs");
  must(renderer, /Require drawbridge-stamp/, "drawbridge-stamp");
  const endpoint = must(renderer, /ENDPOINT_PATH = '([^']+)'/, "ENDPOINT_PATH")[1];
  const callback = must(renderer, /REDIRECT_PATH = self::ENDPOINT_PATH \. '([^']+)'/, "REDIRECT_PATH")[1];
  must(renderer, /mod_auth_openidc/, "mod_auth_openidc");
  const unauth = {
    public: must(renderer, /'OIDCUnAuthAction (pass)'/, "OIDCUnAuthAction pass")[1],
    login: must(renderer, /\(\$api \? '(?:\d+)' : '(\w+)'\)/, "OIDCUnAuthAction login")[1],
    api: must(renderer, /\(\$api \? '(\d+)' : '\w+'\)/, "OIDCUnAuthAction api")[1],
  };

  // The assertion.
  const typ = must(signer, /const TYPE = '([^']+)'/, "AssertionSigner::TYPE")[1];
  must(signer, /Ecdsa\\Sha256/, "ES256 signer");
  const ttl = must(example, /^\s*assertion_ttl_seconds:\s*(\d+)/m, "assertion_ttl_seconds")[1];
  const assertionHeader = must(example, /^\s*assertion_header:\s*(\S+)/m, "assertion_header")[1];
  const userHeader = must(example, /^\s*user_header:\s*(\S+)/m, "user_header")[1];
  const appId = must(example, /^\s*-\s*id:\s*(\S+)/m, "first app id")[1];

  return {
    composer,
    prefixes: prefixes.map(prettyHeader),
    connection,
    callback: endpoint + callback,
    unauth,
    typ,
    ttl,
    assertionHeader,
    userHeader,
    appId,
  };
}

/* ---------------------------------------------------------------- 3. badges */

// Facts are read from composer.json and the source, never typed: a badge that
// lies is worse than no badge at all.
function buildBadges(facts) {
  console.log("badges:");
  const { composer } = facts;
  const defs = [
    { key: "php", label: "php", message: composer.require.php },
    { key: "license", label: "licence", message: composer.license },
    { key: "assertion", label: "assertion", message: `ES256 · ${facts.ttl} s` },
  ];
  for (const d of defs) {
    if (!d.message) fail(`no fact to badge for "${d.key}"`);
    const svg = makeBadge({
      label: d.label,
      message: d.message,
      labelColor: "#0C5449",
      color: "#FFCC33",
      style: "flat-square",
    });
    record(`badges/${d.key}.svg`, svg);
  }
}

/* ------------------------------------------------------------- 4. the page */

async function openPage(browser) {
  const context = await browser.newContext({
    viewport: { width: 1280, height: 900 },
    deviceScaleFactor: 2, // crisp images
    reducedMotion: "reduce", // the finished drawing: bridge down, ink dry, nothing moving
  });
  const page = await context.newPage();
  // No network at all: file:// only. Anything else is aborted and reported.
  await page.route(/^(?!file:|data:|about:)/, (route) => {
    console.log(`  blocked non-file request: ${route.request().url()}`);
    return route.abort();
  });
  await page.goto(pathToFileURL(path.join(siteDir, "index.html")).href, {
    waitUntil: "load",
  });
  await page.evaluate(() => document.fonts.ready);
  return { context, page };
}

// Hide the smallest element around every text node that names something
// forbidden (visibility, so the layout holds), then check that no visible text
// still does. Screenshots are taken only after this.
async function hideForbidden(page) {
  const hidden = await page.evaluate(
    ([src, flags]) => {
      const re = new RegExp(src, flags);
      const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
      const els = new Set();
      for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        if (re.test(n.nodeValue) && n.parentElement && !["SCRIPT", "STYLE"].includes(n.parentElement.tagName)) {
          els.add(n.parentElement);
        }
      }
      for (const el of els) {
        el.style.setProperty("transition", "none", "important");
        el.style.setProperty("animation", "none", "important");
        el.style.setProperty("visibility", "hidden", "important");
      }
      return els.size;
    },
    [FORBIDDEN_SRC, FORBIDDEN_FLAGS],
  );
  console.log(`  hid ${hidden} element(s) naming a forbidden term`);
}

async function assertClean(page, selector, what) {
  const leaks = await page.evaluate(
    ([sel, src, flags]) => {
      const re = new RegExp(src, flags);
      const root = document.querySelector(sel);
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
      const out = [];
      for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        const el = n.parentElement;
        if (el && re.test(n.nodeValue) && getComputedStyle(el).visibility !== "hidden" && el.checkVisibility()) out.push(n.nodeValue.trim());
      }
      return out;
    },
    [selector, FORBIDDEN_SRC, FORBIDDEN_FLAGS],
  );
  if (leaks.length) fail(`forbidden text matched ${FORBIDDEN} in ${what}: ${leaks.join(" | ")}`);
}

/* ------------------------------------------------------------------ 5. hero */

async function buildHero(page) {
  console.log("hero:");
  // Hide the chrome that must not appear in the README art.
  await page.addStyleTag({
    content: ".site-header { display: none !important; }",
  });
  await hideForbidden(page);
  await assertClean(page, ".sheet--hero", "hero");
  const png = await page.locator(".sheet--hero").screenshot();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("hero.jpg", jpg);
}

/* ------------------------------------------------- 6. the signature drawing */

function esc(s) {
  return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

// Everything the drawing needs from the page: the section A-A linework (copied
// as attributes, never re-typed), the cut-marker and callout wording, the door
// schedule and the signer-down outcome.
async function readPage(page) {
  return page.evaluate(() => {
    const svg = document.querySelector("svg.gate");
    const attrs = (el) => {
      const o = {};
      for (const a of el.attributes) if (a.name !== "class" && a.name !== "style") o[a.name] = a.value;
      return o;
    };
    const list = (sel) =>
      [...svg.querySelectorAll(sel)].map((el) => ({
        tag: el.tagName.toLowerCase(),
        cls: el.getAttribute("class") || "",
        a: attrs(el),
        text: el.tagName.toLowerCase() === "text" ? el.textContent.trim() : "",
      }));
    const txt = (sel) => document.querySelector(sel)?.textContent.replace(/\s+/g, " ").trim() ?? "";
    return {
      patterns: [...svg.querySelectorAll("defs pattern, defs marker")].map((el) => el.outerHTML),
      ground: list(".ink--ground > *"),
      water: list(".water > *"),
      house: list(".ink--house > *"),
      deck: list(".bridge__deck"),
      planks: list(".bridge__planks"),
      chain: list("line.chain"),
      hinge: list(".hinge"),
            visitor: list(".visitor__body > *"),
      idpBox: list(".idp__box"),
      idpTrips: list(".idp__trip"),
      dims: list(".dims > *"),
      approach: txt(".anno--small"),
      idpName: txt(".idp__name"),
      idpNote: txt(".idp__note"),
      cut: txt(".cut text.anno"),
      enlarged: txt(".drawing__callout-label"),
      heroTag: txt(".sheet--hero .sheet-tag"),
      brandRev: txt(".brand__rev"),
      sectionTitle: txt("#section-title"),
      guardMod: txt(".passage__room:nth-child(2) .passage__mod"),
      signerMod: txt(".passage__room:nth-child(4) .passage__mod"),
      signerDown: txt(".outcome--warn .outcome__what"),
      doors: [...document.querySelectorAll(".door-table tbody tr")].map((tr) => {
        const td = tr.querySelectorAll("td");
        return { door: td[0].textContent.trim(), path: td[1].textContent.trim(), rule: td[2].textContent.trim(), needs: td[3].textContent.trim() };
      }),
    };
  });
}

// An element from {tag, a} plus extra attributes, in the page's own order.
function node(e, cls, extra = {}) {
  const attrs = { ...e.a, ...extra };
  if (cls) attrs.class = cls;
  const s = Object.entries(attrs)
    .map(([k, v]) => `${k}="${esc(v)}"`)
    .join(" ");
  return e.text ? `<${e.tag} ${s}>${esc(e.text)}</${e.tag}>` : `<${e.tag} ${s}/>`;
}

// Timeline: one shared loop, every element reveals at its own moment, the
// whole sheet holds, fades, and starts again. The still state is the default;
// motion is added only for people who have not asked for less of it.
const LOOP = 16; // seconds
const HOLD_UNTIL = 14.4;

function makeTimeline() {
  const frames = new Map();
  const pct = (t) => ((t / LOOP) * 100).toFixed(3);
  const reg = (kind, t, d) => {
    const key = `${kind}-${t}-${d}`.replace(/\./g, "_");
    if (!frames.has(key)) {
      const a = pct(t);
      const b = pct(Math.min(t + d, HOLD_UNTIL));
      const body =
        kind === "draw"
          ? `0%,${a}%{stroke-dashoffset:1.02}${b}%,100%{stroke-dashoffset:0}`
          : `0%,${a}%{opacity:0}${b}%,100%{opacity:1}`;
      frames.set(key, `.${key}{animation-name:${key}}@keyframes ${key}{${body}}`);
    }
    return key;
  };
  return {
    /** class for an element that fades in at t over d seconds */
    show: (t, d = 0.6) => `an ${reg("show", t, d)}`,
    /** class for a stroked element that draws itself from t over d seconds */
    draw: (t, d = 1) => `an dr ${reg("draw", t, d)}`,
    css: () => [...frames.values()].join("\n"),
    pct,
  };
}

function fontFace(family, weight, file) {
  const b64 = readFileSync(path.join(siteDir, "assets", "fonts", file)).toString("base64");
  return `@font-face{font-family:'${family}';font-weight:${weight};src:url(data:font/woff2;base64,${b64}) format('woff2');}`;
}

function buildGatehouseSvg(facts, pg) {
  const tl = makeTimeline();
  const W = 1280;
  const H = 780;
  const C = {
    paper: "#f4f1ea",
    sheet: "#fbfaf6",
    ink: "#0c5449",
    inkDeep: "#073a32",
    text: "#22302c",
    text2: "#4a5d57",
    slate: "#728780",
    gold: "#ffcc33",
    goldInk: "#7a5c00",
    red: "#a8362a",
  };
  const MONO = "ui-monospace, 'Cascadia Mono', Menlo, Consolas, 'DejaVu Sans Mono', monospace";
  const gx = 36; // where the section drawing sits on the sheet
  const gy = 96;

  // --- the gatehouse, from the page's own linework -------------------------
  const roleClass = (e) => e.cls || "";
  const grp = (items, t, d, only) =>
    items
      .map((e) => {
        const isStroke = /\bdraw\b/.test(e.cls);
        if (only && !only(e)) return "";
        const cls = `${roleClass(e)} ${isStroke ? tl.draw(t, d) : tl.show(t + d * 0.6, 0.8)}`;
        return node(e, cls, isStroke ? { pathLength: "1" } : {});
      })
      .join("\n      ");

  const ground = grp(pg.ground, 0.7, 1.6);
  const water = pg.water
    .map((e) => node(e, `${e.cls} ${/\bdraw\b/.test(e.cls) ? tl.draw(2.2, 0.8) : tl.show(2.3, 0.8)}`, /\bdraw\b/.test(e.cls) ? { pathLength: "1" } : {}))
    .join("\n      ");
  const house = grp(pg.house, 1.1, 1.5);
  const deck = pg.deck.map((e) => node(e, `${e.cls} ${tl.show(2.6, 0.6)}`)).join("");
  const planks = pg.planks.map((e) => node(e, `${e.cls} ${tl.show(2.8, 0.6)}`)).join("");
  const chain = pg.chain.map((e) => node(e, `chain ${tl.draw(3.0, 0.9)}`, { pathLength: "1" })).join("");
  const hinge = pg.hinge.map((e) => node(e, `hinge ${tl.show(3.0, 0.4)}`)).join("");
  const visitor = pg.visitor.map((e) => node(e, "visitor__ink")).join("");
  const idpBox = pg.idpBox.map((e) => node(e, `idp__box ${tl.show(1.6, 0.8)}`)).join("");
  const idpTrips = pg.idpTrips.map((e) => node(e, `${e.cls} ${tl.show(3.4, 0.8)}`)).join("");
  const dims = pg.dims
    .map((e) => node(e, `${e.cls} ${tl.show(3.4, 0.8)}`))
    .join("\n      ");

  // --- rooms: the three numbered stops, labelled from the source ------------
  const rooms = [
    { x: 474, n: 1, label: "guard" },
    { x: 518, n: 2, label: "OIDC" },
    { x: 562, n: 3, label: "sign" },
  ];
  const roomsSvg = rooms
    .map(
      (r, i) => `<g class="${tl.show(3.6 + i * 0.2, 0.4)}">
        <circle cx="${r.x}" cy="244" r="13" class="room__tag"/>
        <text x="${r.x}" y="249" class="room__no">${r.n}</text>
        <text x="${r.x}" y="276" class="anno anno--in" text-anchor="middle">${esc(r.label)}</text>
      </g>`,
    )
    .join("\n      ");

  // --- the forged header, thrown at the gate and stripped -------------------
  const forged = facts.userHeader;
  const chipW = Math.round(forged.length * 8.6 + 18);
  const forgedSvg = `<g transform="translate(150 308)">
        <g class="forged">
          <rect x="0" y="-12" width="${chipW}" height="24" rx="4" class="forged__box"/>
          <text x="9" y="5" class="mono forged__text">${esc(forged)}</text>
          <line x1="-3" y1="0" x2="${chipW + 3}" y2="0" class="forged__strike" pathLength="1"/>
        </g>
      </g>`;

  // --- notes column --------------------------------------------------------
  const NX = 752;
  const monoW = (s, size) => Math.round(s.length * size * 0.62);
  const bubble = (n, ny, t) =>
    `<g class="${tl.show(t, 0.5)}"><circle cx="${NX + 16}" cy="${ny - 9}" r="16" class="room__tag"/><text x="${NX + 16}" y="${ny - 2}" class="room__no room__no--big">${n}</text></g>`;
  const heading = (title, sub, ny, t) =>
    `<text x="${NX + 42}" y="${ny}" class="h ${tl.show(t, 0.5)}">${esc(title)}</text>
    <text x="${NX + 42}" y="${ny + 28}" class="hand sub ${tl.show(t + 0.1, 0.5)}">${esc(sub)}</text>`;

  // Up from the room, across above the roof, down a gutter, into the bubble.
  // Gutters run right to left as the notes run down, so no leader crosses another.
  const leader = (x, y1, y2, gutter, ny, t) => {
    const d = `M${x + gx} ${y1} V${y2} H${gutter} V${ny - 9} H${NX - 16}`;
    return `<path d="${d}" class="leader-halo ${tl.show(t, 0.9)}"/>
    <path d="${d}" class="leader ${tl.draw(t, 0.9)}" pathLength="1"/>
    <circle cx="${x + gx}" cy="${y1}" r="4" class="leader-dot ${tl.show(t, 0.3)}"/>`;
  };
  const rowY = gy + 244 - 13; // top edge of the room circles on the sheet

  // note 1: guard
  const n1 = 118;
  const strikeT = [5.6, 5.95, 6.3, 6.65];
  let cx0 = NX;
  let cy0 = n1 + 46;
  const chips = facts.prefixes
    .map((p, i) => {
      const w = monoW(p, 17) + 26;
      if (cx0 + w > 1244) {
        cx0 = NX;
        cy0 += 40;
      }
      const out = `<g class="${tl.show(5.2 + i * 0.1, 0.4)}">
      <rect x="${cx0}" y="${cy0}" width="${w}" height="30" rx="5" class="chip"/>
      <text x="${cx0 + 13}" y="${cy0 + 21}" class="mono chip__text">${esc(p)}</text>
      <line x1="${cx0 + 6}" y1="${cy0 + 12}" x2="${cx0 + w - 6}" y2="${cy0 + 12}" class="strike ${tl.draw(strikeT[i] ?? 6.9, 0.4)}" pathLength="1"/>
    </g>`;
      cx0 += w + 14;
      return out;
    })
    .join("\n    ");
  const connLine = `Connection: ${facts.connection.join(", ")} only`;
  const guardSvg = `${leader(474, rowY, 130, 732, n1, 4.4)}
    ${bubble(1, n1, 4.9)}
    ${heading("GUARD", `${pg.guardMod} · every request, before sign-in`, n1, 5.0)}
    ${chips}
    <text x="${NX}" y="${cy0 + 62}" class="mono line ${tl.show(7.0, 0.5)}">${esc(connLine)}</text>`;

  // note 2: the OpenID Connect client
  const n2 = 306;
  const oidcLines = [
    "OIDCResponseType code",
    "OIDCPKCEMethod S256",
    `redirect ${facts.callback}`,
    "OIDCPassClaimsAs environment none",
  ];
  const oidcSvg = `${leader(518, rowY, 150, 716, n2, 7.3)}
    ${bubble(2, n2, 7.8)}
    ${heading("OPENID CONNECT CLIENT", "mod_auth_openidc · one client, every app host", n2, 7.9)}
    ${oidcLines
      .map((l, i) => `<text x="${NX}" y="${n2 + 58 + i * 26}" class="mono line ${tl.show(8.4 + i * 0.25, 0.5)}">${esc(l)}</text>`)
      .join("\n    ")}`;

  // note 3: the signer
  const n3 = 496;
  const typW = monoW(`typ: ${facts.typ}`, 17) + 28;
  const signerSvg = `${leader(562, rowY, 170, 700, n3, 9.4)}
    ${bubble(3, n3, 9.9)}
    ${heading("SIGNER", `${pg.signerMod} · fresh each time`, n3, 10.0)}
    <g class="${tl.show(10.5, 0.5)}">
      <rect x="${NX}" y="${n3 + 42}" width="${typW}" height="30" rx="5" class="token"/>
      <text x="${NX + 14}" y="${n3 + 63}" class="mono token__text">typ: ${esc(facts.typ)}</text>
    </g>
    <text x="${NX}" y="${n3 + 98}" class="mono line ${tl.show(10.8, 0.5)}">alg ES256 · exp +${esc(facts.ttl)} s → ${esc(facts.assertionHeader)}</text>
    <text x="${NX}" y="${n3 + 126}" class="mono line line--red ${tl.show(11.2, 0.5)}">signer down → 503</text>`;

  // --- the door schedule, from the page's own table -------------------------
  const tableY = 672;
  const labelW = 96;
  const cellW = 112;
  const door = (r) => {
    const word = r.needs.toLowerCase();
    return { path: /anything/i.test(r.path) ? "anything else" : r.path, needs: word, act: facts.unauth[word] ?? "?" };
  };
  const doors = pg.doors.map(door);
  const tableW = labelW + cellW * doors.length;
  const schedule = `<text x="${gx}" y="${tableY - 10}" class="hand sub ${tl.show(11.6, 0.5)}">door schedule for ${esc(facts.appId)} · each cell is an OIDCUnAuthAction</text>
    <g class="${tl.show(11.8, 0.5)}">
      <rect x="${gx}" y="${tableY}" width="${tableW}" height="64" class="cell cell--label"/>
      <text x="${gx + labelW / 2}" y="${tableY + 40}" class="h h--small" text-anchor="middle">DOORS</text>
    </g>
    ${doors
      .map((d, i) => {
        const x = gx + labelW + i * cellW;
        const t = 12.0 + i * 0.2;
        const isPath = d.path !== "anything else";
        return `<g class="${tl.show(t, 0.5)}">
      <rect x="${x}" y="${tableY}" width="${cellW}" height="64" class="cell"/>
      <text x="${x + cellW / 2}" y="${tableY + 22}" class="${isPath ? "mono" : "hand"} cell__path" text-anchor="middle">${esc(d.path)}</text>
      <text x="${x + cellW / 2}" y="${tableY + 42}" class="hand cell__needs cell__needs--${d.needs}" text-anchor="middle">${esc(d.needs)}</text>
      <text x="${x + cellW / 2}" y="${tableY + 58}" class="mono cell__act" text-anchor="middle">${esc(d.act)}</text>
    </g>`;
      })
      .join("\n    ")}`;

  // --- title block ----------------------------------------------------------
  const tbX = NX;
  const tbW = 1244 - NX;
  const sheetNo = (pg.heroTag.match(/A-\d+/) || ["A-101"])[0];
  const sheetName = pg.heroTag.replace(sheetNo, "").trim();
  const titleBlock = `<g class="${tl.show(2.0, 0.8)}">
      <rect x="${tbX}" y="${tableY}" width="${tbW}" height="64" class="cell cell--title"/>
      <line x1="${tbX + 196}" y1="${tableY}" x2="${tbX + 196}" y2="${tableY + 64}" class="rule"/>
      <line x1="${tbX + 196}" y1="${tableY + 32}" x2="${tbX + tbW}" y2="${tableY + 32}" class="rule"/>
      <text x="${tbX + 14}" y="${tableY + 38}" class="h h--title">DRAWBRIDGE</text>
      <text x="${tbX + 15}" y="${tableY + 56}" class="hand tb tb--dim" style="font-size:15px">${esc(pg.brandRev)}</text>
      <text x="${tbX + 208}" y="${tableY + 22}" class="hand tb">${esc(sheetNo)} · ${esc(sheetName)}</text>
      <text x="${tbX + 208}" y="${tableY + 54}" class="hand tb" style="font-size:16.5px">${esc(pg.sectionTitle)}</text>
    </g>`;

  // --- header: section cut marker and the enlarged callout -------------------
  const header = `<g class="${tl.show(0.3, 0.6)}">
      <circle cx="52" cy="56" r="18" class="cut__bubble"/>
      <text x="52" y="63" class="cut__letter">A</text>
      <path d="M72 56 H112" class="cut__line"/>
      <text x="122" y="62" class="hand sub">${esc(pg.cut)}</text>
    </g>
    <g class="${tl.show(0.6, 0.6)}">
      <circle cx="628" cy="52" r="20" class="detail__ring"/>
      <text x="628" y="59" class="detail__no">1</text>
      <text x="658" y="60" class="hand enlarged">${esc(pg.enlarged.replace(/^1\s*\W\s*/, ""))}</text>
    </g>`;

  const reduced = `
@media (prefers-reduced-motion: reduce){
  .an{animation:none !important}
}`;

  const css = `
${fontFace("Big Shoulders Stencil Display", 800, "big-shoulders-stencil-display-latin-800-normal.woff2")}
${fontFace("Architects Daughter", 400, "architects-daughter-latin-400-normal.woff2")}
.h{font:800 34px 'Big Shoulders Stencil Display','Arial Narrow',Impact,sans-serif;letter-spacing:.04em;fill:${C.ink}}
.h--small{font-size:26px}
.h--title{font-size:33px}
.hand{font-family:'Architects Daughter','Segoe Print','Bradley Hand',cursive;fill:${C.ink}}
.sub{font-size:20px;fill:${C.text2}}
.enlarged{font-size:25px}
.mono{font-family:${MONO}}
.line{font-size:17px;fill:${C.text}}
.line--red{fill:${C.red};font-weight:700}
.tb{font-size:18px;fill:${C.text}}
.tb--dim{fill:${C.text2}}
.rule{stroke:${C.ink};stroke-width:1.5}
.paper{fill:${C.paper}}
.frame{fill:none;stroke:${C.ink};stroke-width:2.5}
.grid-line{stroke:${C.slate};stroke-width:1;opacity:.16}
.draw{fill:none;stroke:${C.ink};stroke-width:2;stroke-linejoin:round;stroke-linecap:round}
.draw.thin{stroke-width:1}
.fill-earth{fill:url(#earth);stroke:none}
.earth-dot{fill:${C.slate};opacity:.55}
.hatch-line{stroke:${C.ink};stroke-width:1;opacity:.45}
.poche{fill:url(#hatch);fill-rule:evenodd}
.pulley{fill:${C.sheet}}
.water__line{stroke:${C.slate}}
.water__wave{fill:none;stroke:${C.slate};stroke-width:1.5;stroke-linecap:round}
.bridge__deck{fill:${C.ink}}
.bridge__planks{stroke:${C.sheet};stroke-width:1;opacity:.55}
.chain{fill:none;stroke:${C.gold};stroke-width:3.5;stroke-linecap:round}
.hinge{fill:${C.gold};stroke:${C.ink};stroke-width:1.5}
.arrow-head,.tick{fill:none;stroke:${C.ink};stroke-width:1.4;stroke-linecap:round;stroke-linejoin:round}
.visitor__ink{fill:${C.paper};stroke:${C.ink};stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
path.visitor__ink{fill:none}
.dim{stroke:${C.slate};stroke-width:1.2;fill:none}
.anno{font:400 17px 'Architects Daughter','Segoe Print',cursive;fill:${C.text2}}
.anno--dim{text-anchor:middle}
.anno--in{font-size:17px;fill:${C.ink}}
.idp__box{fill:${C.sheet};stroke:${C.ink};stroke-width:1.5;stroke-dasharray:6 4}
.idp__trip{fill:none;stroke:${C.slate};stroke-width:1.6;stroke-dasharray:5 4}
.room__tag{fill:${C.sheet};stroke:${C.ink};stroke-width:1.5}
.room__no{font:700 15px 'Architects Daughter',sans-serif;fill:${C.ink};text-anchor:middle}
.room__no--big{font-size:20px}
.cut__bubble,.detail__ring{fill:${C.sheet};stroke:${C.ink};stroke-width:2}
.detail__ring{stroke-dasharray:5 3}
.cut__letter,.detail__no{font:800 24px 'Big Shoulders Stencil Display',Impact,sans-serif;fill:${C.ink};text-anchor:middle}
.cut__line{stroke:${C.ink};stroke-width:2}
.leader-halo{fill:none;stroke:${C.paper};stroke-width:7;stroke-linejoin:round}
.leader{fill:none;stroke:${C.ink};stroke-width:1.8;stroke-linejoin:round}
.leader-dot{fill:${C.ink}}
.chip{fill:${C.sheet};stroke:${C.slate};stroke-width:1.5}
.chip__text{font-size:17px;fill:${C.text2}}
.strike{stroke:${C.red};stroke-width:2.2;stroke-linecap:round}
.token{fill:${C.gold};stroke:${C.goldInk};stroke-width:1.5}
.token__text{font-size:17px;font-weight:700;fill:${C.inkDeep}}
.forged__box{fill:${C.sheet};stroke:${C.red};stroke-width:1.5}
.forged__text{font-size:14.5px;fill:${C.red}}
.forged__strike{stroke:${C.red};stroke-width:2.5;stroke-linecap:round}
.cell{fill:${C.sheet};stroke:${C.ink};stroke-width:1.5}
.cell--label,.cell--title{fill:${C.paper};stroke-width:2.5}
.cell__path{font-size:17px;fill:${C.text};font-weight:700}
.cell__path.hand{font-weight:400;font-size:17px}
.cell__needs{font-size:17px;fill:${C.ink}}
.cell__needs--public{fill:${C.text2}}
.cell__needs--api{fill:${C.goldInk}}
.cell__act{font-size:14px;fill:${C.text2}}
.an{animation-duration:${LOOP}s;animation-iteration-count:infinite;animation-timing-function:ease-out}
.dr{stroke-dasharray:1.02;stroke-dashoffset:0}
${tl.css()}
@keyframes forge{
  0%,${tl.pct(3.7)}%{opacity:0;transform:translateX(0)}
  ${tl.pct(3.9)}%{opacity:1;transform:translateX(0)}
  ${tl.pct(5.0)}%{opacity:1;transform:translateX(100px)}
  ${tl.pct(5.9)}%{opacity:1;transform:translateX(100px)}
  ${tl.pct(6.4)}%{opacity:0;transform:translateX(100px)}
  100%{opacity:0;transform:translateX(100px)}
}
@keyframes forge-strike{
  0%,${tl.pct(5.0)}%{stroke-dashoffset:1.02}
  ${tl.pct(5.5)}%,100%{stroke-dashoffset:0}
}
@keyframes sheet-life{
  0%,${tl.pct(HOLD_UNTIL)}%{opacity:1}
  ${tl.pct(HOLD_UNTIL + 1.1)}%,100%{opacity:0}
}
/* still state: the chip is not part of the finished drawing */
.forged{opacity:0}
@media (prefers-reduced-motion: no-preference){
  .forged{animation:forge ${LOOP}s infinite ease-in-out;opacity:1}
  .forged__strike{stroke-dasharray:1.02;animation:forge-strike ${LOOP}s infinite ease-out}
  .sheet{animation:sheet-life ${LOOP}s infinite linear}
}
${reduced}`;

  const body = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-labelledby="gh-title gh-desc">
  <title id="gh-title">DRAWBRIDGE, section A–A, detail 1 enlarged</title>
  <desc id="gh-desc">${esc(
    `An architect's section through the gatehouse, labelled from the source. 1, the guard, removes ${facts.prefixes.join(", ")} and trims Connection to ${facts.connection.join(", ")}. 2, the OpenID Connect client: response type code, PKCE S256, callback ${facts.callback}, claims kept in the environment. 3, the signer: ${facts.typ}, ES256, ${facts.ttl} seconds, sent as ${facts.assertionHeader}; if it cannot sign the answer is 503. A door schedule shows /up and /assets/ public, /admin/ and anything else login, /api/ api.`,
  )}</desc>
  <defs>
    ${pg.patterns.join("\n    ")}
  </defs>
  <style>${css}</style>
  <rect width="${W}" height="${H}" rx="22" class="paper"/>
  <g class="${tl.show(0, 0.8)}">
    ${Array.from({ length: Math.floor(W / 40) }, (_, i) => `<line x1="${(i + 1) * 40}" y1="0" x2="${(i + 1) * 40}" y2="${H}" class="grid-line"/>`).join("")}
    ${Array.from({ length: Math.floor(H / 40) }, (_, i) => `<line x1="0" y1="${(i + 1) * 40}" x2="${W}" y2="${(i + 1) * 40}" class="grid-line"/>`).join("")}
  </g>
  <rect x="14" y="14" width="${W - 28}" height="${H - 28}" rx="12" class="frame ${tl.draw(0, 1.6)}" pathLength="1"/>
  <g class="sheet">
    ${header}
    <g transform="translate(${gx} ${gy})">
      ${ground}
      ${water}
      ${house}
      ${deck}
      ${planks}
      ${chain}
      ${hinge}
      ${roomsSvg}
      <g transform="${esc(pg.visitorAt)}" class="${tl.show(2.8, 0.6)}">${visitor}</g>
      ${idpBox}
      <text x="235" y="119" class="anno idp-name ${tl.show(1.8, 0.6)}" text-anchor="middle" style="font-size:19px;fill:${C.ink}">${esc(pg.idpName.replace(/\(.*\)/, "(OIDC)"))}</text>
      <text x="235" y="140" class="anno ${tl.show(1.9, 0.6)}" text-anchor="middle" style="font-size:15px">${esc(pg.idpNote)}</text>
      ${idpTrips}
      <text x="128" y="404" class="anno ${tl.show(3.4, 0.6)}">${esc(pg.approach)}</text>
      ${dims}
      ${forgedSvg}
    </g>
    ${guardSvg}
    ${oidcSvg}
    ${signerSvg}
    ${schedule}
    ${titleBlock}
  </g>
</svg>
`;

  // Strict check on everything composed here, before the (base64) fonts go in.
  const plain = body.replace(/url\(data:[^)]*\)/g, "");
  if (FORBIDDEN.test(plain)) fail(`gatehouse.svg text matches ${FORBIDDEN}`);
  return body;
}

async function buildGatehouse(page, facts) {
  console.log("gatehouse:");
  const pg = await readPage(page);
  // The page's script takes the visitor's transform off the element, so read
  // where the visitor stands from the markup itself.
  const at = /<g class="visitor"[^>]*? transform="([^"]+)"/.exec(read("site", "index.html"));
  if (!at) fail("could not find the visitor's position in site/index.html");
  pg.visitorAt = at[1];
  if (pg.doors.length < 4) fail(`expected the door schedule, found ${pg.doors.length} rows`);
  if (!/503/.test(pg.signerDown)) fail("page no longer says signer down is a 503");
  if (!pg.ground.length || !pg.house.length) fail("section A-A linework not found on the page");
  record("gatehouse.svg", Buffer.from(buildGatehouseSvg(facts, pg), "utf8"));
}

/* -------------------------------------------------- 7. gallery and the board */

// A contact sheet of three live mobile windows onto the page (hero, the
// longitudinal section, the general notes), screenshotted at phone width
// after the forbidden mentions are hidden, then laid out on a gridded sheet.
async function buildGallery(browser) {
  console.log("gallery:");
  const ctx = await browser.newContext({
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    reducedMotion: "reduce",
  });
  const mp = await ctx.newPage();
  await mp.route(/^(?!file:|data:|about:)/, (route) => route.abort());
  await mp.goto(pathToFileURL(path.join(siteDir, "index.html")).href, { waitUntil: "load" });
  await mp.evaluate(() => document.fonts.ready);
  await mp.addStyleTag({ content: ".site-header { display: none !important; }" });
  await hideForbidden(mp);
  const shots = [];
  for (const sel of ["section[aria-labelledby=section-title]", ".sheet--notes", "section[aria-labelledby=build-title]"]) {
    await assertClean(mp, sel, `gallery ${sel}`);
    await mp.locator(sel).scrollIntoViewIfNeeded();
    shots.push((await mp.locator(sel).screenshot()).toString("base64"));
  }
  await ctx.close();

  const page = await browser.newPage({ viewport: { width: 1400, height: 900 }, deviceScaleFactor: 2 });
  const html = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
  html, body { margin: 0; padding: 0; }
  /* The sheet is exactly as wide as its content: 3x360 + 2x32 + 2x40 = 1224. */
  .sheet { width: fit-content; background: #f4f1ea; padding: 40px; display: flex; gap: 32px;
    background-image: linear-gradient(rgba(114,135,128,.16) 1px, transparent 1px), linear-gradient(90deg, rgba(114,135,128,.16) 1px, transparent 1px);
    background-size: 40px 40px; }
  img {
    box-sizing: border-box; /* the 2px border sits inside the 360x640 */
    display: block;
    width: 360px;
    height: 640px;
    object-fit: cover;
    object-position: top;
    background: #fbfaf6;
    border: 2px solid #0c5449;
    border-radius: 6px;
  }
</style>
</head>
<body>
<div class="sheet">
${shots.map((b) => `  <img src="data:image/png;base64,${b}" alt="">`).join("\n")}
</div>
</body>
</html>`;
  await page.setContent(html, { waitUntil: "load" });
  const png = await page.locator(".sheet").screenshot();
  await page.close();
  const jpg = await sharp(png).resize({ width: 1280 }).jpeg({ quality: 82, mozjpeg: true }).toBuffer();
  record("gallery.jpg", jpg);
}

async function buildBoard() {
  console.log("board:");
  const src = path.join(siteDir, "brand", "drawbridge-board.png");
  if (!existsSync(src)) fail("missing site/brand/drawbridge-board.png");
  let q = 82;
  let jpg;
  for (;;) {
    jpg = await sharp(src).resize({ width: 1600 }).jpeg({ quality: q, mozjpeg: true }).toBuffer();
    if (jpg.length <= 300 * 1024 || q <= 40) break;
    q -= 6;
  }
  record("board.jpg", jpg);
}

/* -------------------------------------------------------------------- main */

async function main() {
  const facts = readFacts();
  await buildCast();
  buildBadges(facts);
  await buildBoard();

  const browser = await chromium.launch();
  try {
    const { context, page } = await openPage(browser);
    await buildGatehouse(page, facts);
    await buildHero(page);
    await context.close();
    await buildGallery(browser);
  } finally {
    await browser.close();
  }

  // The size gate: print what we have, fail if anything grew past the limit.
  let over = false;
  for (const { rel, bytes } of outputs) {
    if (bytes > MAX_BYTES) {
      console.error(`FAIL: ${rel} is ${bytes} bytes (limit ${MAX_BYTES})`);
      over = true;
    }
  }
  if (over) process.exit(1);
  console.log(`done: ${outputs.length} files, all at or under ${MAX_BYTES} bytes`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
