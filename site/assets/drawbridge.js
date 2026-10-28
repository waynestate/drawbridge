/* DRAWBRIDGE front page. Small, vanilla, no build.

   1. ink: measure each drawn line so CSS can draw it in
   2. the gate: the bridge, the chain, the visitor and the forgeries
   3. explain: the door schedule, matched the way `drawbridge explain` does
   4. the assertion: a sample token that is re-minted every 60 seconds
   5. reveals, tabs, copy buttons

   With reduced motion the page arrives finished (bridge down, visitor signed
   in) and every button still works, instantly. No trolls live under this
   bridge; only tests. */
(function () {
  "use strict";

  var motion = document.documentElement.classList.contains("motion");
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ------------------------------------------------------------- 1. ink */
  if (motion) {
    $$(".gate .draw").forEach(function (el, i) {
      var len = 1200;
      try { len = Math.ceil(el.getTotalLength()) + 2; } catch (e) { /* keep the default */ }
      el.style.setProperty("--len", len);
      el.style.setProperty("--d", (0.25 + i * 0.09).toFixed(2) + "s");
    });
  }

  /* -------------------------------------------------------- 2. the gate */
  var fig = $("[data-gate]");
  if (fig) gate(fig);

  function gate(fig) {
    var svg = $("svg", fig);
    var bridge = $("[data-bridge]", fig);
    var shake = $(".bridge__shake", fig);
    var chain = $("[data-chain]", fig);
    var visitor = $("[data-visitor]", fig);
    var forgeries = $("[data-forgeries]", fig);
    var status = $("[data-status]", fig);
    var signBtn = $("[data-signin]", fig);
    var forgeBtn = $("[data-forge]", fig);
    var NS = "http://www.w3.org/2000/svg";

    var HINGE = { x: 440, y: 380 };
    var TIP = { x: 298, y: 385 };       // where the chain meets the deck, lowered
    var PULLEY = { x: 452, y: 150 };
    var WALL = 428;                       // the raised bridge's outer face
    var GUARD = 462;                      // room 1, just inside the gate

    var angle = 0;                        // 0 = down, 90 = raised
    var down = true;
    var busy = false;
    var forged = 0;

    var FORGERIES = [
      "X-Authenticated-User: admin",
      "Remote-User: root",
      "X-Sesame-Assertion: trust-me",
      "X-Forwarded-For: 127.0.0.1",
      "X_Authenticated_User: admin",
      "X-Drawbridge-Is-Admin: yes",
      "X-Auth-Request-Email: dean@"
    ];

    // On a phone, frame the sheet tighter so the lettering stays readable.
    var narrow = window.matchMedia("(max-width: 600px)");
    var frame = function () { svg.setAttribute("viewBox", narrow.matches ? "112 76 528 424" : "0 0 640 500"); };
    frame();
    if (narrow.addEventListener) narrow.addEventListener("change", frame);

    visitor.removeAttribute("transform");
    placeVisitor(150, true);
    setAngle(0);

    function setAngle(a) {
      angle = a;
      bridge.style.transform = "rotate(" + a + "deg)";
      var r = a * Math.PI / 180;
      var dx = TIP.x - HINGE.x, dy = TIP.y - HINGE.y;
      chain.setAttribute("x2", (HINGE.x + dx * Math.cos(r) - dy * Math.sin(r)).toFixed(1));
      chain.setAttribute("y2", (HINGE.y + dx * Math.sin(r) + dy * Math.cos(r)).toFixed(1));
    }

    function swing(to, ms) {
      return new Promise(function (done) {
        if (!motion) { setAngle(to); done(); return; }
        var from = angle, t0 = performance.now();
        (function step(now) {
          var t = Math.min(1, (now - t0) / ms);
          // Lowering eases out with a small settle at the bottom (the thud);
          // raising is a steady haul on the chain.
          var e = to < from ? easeOutBack(t) : easeInOut(t);
          setAngle(from + (to - from) * e);
          if (t < 1) requestAnimationFrame(step); else { setAngle(to); done(); }
        })(t0);
      });
    }
    function easeInOut(t) { return t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; }
    function easeOutBack(t) { var c = 0.9, c3 = c + 1; return 1 + c3 * Math.pow(t - 1, 3) + c * Math.pow(t - 1, 2); }

    function placeVisitor(x, instant) {
      if (instant) {
        visitor.style.transition = "none";
        visitor.style.transform = "translate(" + x + "px, 380px)";
        void visitor.getBoundingClientRect();
        visitor.style.transition = "";
      } else {
        visitor.style.transform = "translate(" + x + "px, 380px)";
      }
    }
    function wait(ms) { return new Promise(function (r) { setTimeout(r, motion ? ms : 0); }); }
    function say(text) { status.textContent = text; }

    function setDown(isDown) {
      down = isDown;
      fig.classList.toggle("is-down", isDown);
      signBtn.setAttribute("aria-pressed", isDown ? "true" : "false");
      $(".gate-btn__label", signBtn).textContent = isDown ? "Sign out" : "Sign in";
    }

    async function signIn() {
      busy = true; signBtn.disabled = true;
      say("Off to sign in at the IdP over SAML, and back with a session.");
      fig.classList.add("is-saml");
      await wait(1500);
      fig.classList.remove("is-saml");
      setDown(true);
      say("Signed in. The drawbridge comes down.");
      await swing(0, 1900);
      if (motion) fig.classList.add("is-walking");
      say("Across the bridge, past the guard and the SP. The signer mints a 60-second assertion for this request.");
      placeVisitor(GUARD + 30);
      await wait(1300);
      fig.classList.add("is-inside");
      placeVisitor(548);
      await wait(1000);
      fig.classList.remove("is-walking");
      say("In. The app gets one signed X-Sesame-Assertion header, and SESAME's gateway driver verifies it.");
      busy = false; signBtn.disabled = false;
    }

    async function signOut() {
      busy = true; signBtn.disabled = true;
      fig.classList.remove("is-inside");
      placeVisitor(150, true);
      setDown(false);
      say("Signed out. The bridge goes back up.");
      await swing(90, 1500);
      say("The bridge is up. Only a signed-in visitor brings it down.");
      busy = false; signBtn.disabled = false;
    }

    signBtn.addEventListener("click", function () {
      if (busy) return;
      if (down) signOut(); else signIn();
    });
    forgeBtn.addEventListener("click", function () { throwForgery(); });

    function makeTag(text) {
      var g = document.createElementNS(NS, "g");
      g.setAttribute("class", "forgery");
      var rect = document.createElementNS(NS, "rect");
      var label = document.createElementNS(NS, "text");
      label.textContent = text;
      label.setAttribute("x", "7");
      label.setAttribute("y", "12.5");
      g.appendChild(rect); g.appendChild(label);
      forgeries.appendChild(g);
      var w = Math.ceil(label.getComputedTextLength ? label.getComputedTextLength() : text.length * 6) + 14;
      rect.setAttribute("width", w); rect.setAttribute("height", "18"); rect.setAttribute("rx", "2");
      return { g: g, w: w };
    }
    function stamp(x, y, text) {
      var t = document.createElementNS(NS, "text");
      t.setAttribute("class", "forgery__stamp");
      t.setAttribute("x", x); t.setAttribute("y", y);
      t.setAttribute("text-anchor", "middle");
      t.textContent = text;
      forgeries.appendChild(t);
      if (motion && t.animate) {
        t.animate([{ opacity: 0, transform: "translateY(6px)" }, { opacity: 1, transform: "none", offset: 0.2 }, { opacity: 1, offset: 0.75 }, { opacity: 0 }],
          { duration: 1900, easing: "ease-out", fill: "forwards" });
        setTimeout(function () { t.remove(); }, 2000);
      } else {
        setTimeout(function () { t.remove(); }, 2500);
      }
    }

    function throwForgery() {
      var text = FORGERIES[forged++ % FORGERIES.length];
      var name = text.split(":")[0];
      var tag = makeTag(text);
      var y = 268 + (forged % 3) * 14;
      var start = 6;

      if (!down) {
        // Bridge up: it bounces off the deck and falls in the moat.
        var hit = WALL - tag.w - 2;
        say("Forged " + name + " header bounced off the raised bridge. No session, no way in.");
        if (!motion || !tag.g.animate) {
          tag.g.setAttribute("transform", "translate(" + Math.max(start, hit) + " " + y + ")");
          stamp(360, 430, "splash");
          setTimeout(function () { tag.g.remove(); }, 2500);
          return;
        }
        var a = tag.g.animate([
          { transform: "translate(" + (start - 40) + "px," + y + "px)", opacity: 0 },
          { transform: "translate(" + start + "px," + y + "px)", opacity: 1, offset: 0.08 },
          { transform: "translate(" + hit + "px," + y + "px)", offset: 0.42, easing: "cubic-bezier(.2,.7,.4,1)" },
          { transform: "translate(" + (hit - 56) + "px," + (y - 34) + "px) rotate(-18deg)", offset: 0.62, easing: "ease-in" },
          { transform: "translate(" + (330 - tag.w / 2) + "px,450px) rotate(-70deg)", opacity: 1, offset: 0.92 },
          { transform: "translate(" + (330 - tag.w / 2) + "px,470px) rotate(-80deg)", opacity: 0 }
        ], { duration: 2300, easing: "ease-in", fill: "forwards" });
        setTimeout(function () { knock(); }, 2300 * 0.42);
        setTimeout(function () { splash(); }, 2300 * 0.9);
        a.onfinish = function () { tag.g.remove(); };
      } else {
        // Bridge down: it walks in, and the guard strips it before sign-in runs.
        say("Forged " + name + " header stripped by the guard, before authentication. The app never sees it.");
        var at = GUARD - tag.w / 2;
        if (!motion || !tag.g.animate) {
          tag.g.setAttribute("transform", "translate(" + Math.max(start, 300 - tag.w / 2) + " " + y + ")");
          stamp(330, y - 8, "stripped");
          setTimeout(function () { tag.g.remove(); }, 2500);
          return;
        }
        var b = tag.g.animate([
          { transform: "translate(" + (start - 40) + "px," + y + "px)", opacity: 0 },
          { transform: "translate(" + start + "px," + y + "px)", opacity: 1, offset: 0.08 },
          { transform: "translate(" + at + "px," + y + "px)", offset: 0.5, easing: "ease-in-out" },
          { transform: "translate(" + at + "px," + y + "px) scale(1.04)", offset: 0.62 },
          { transform: "translate(" + at + "px," + (y + 60) + "px) rotate(12deg)", opacity: 0 }
        ], { duration: 2400, fill: "forwards" });
        setTimeout(function () { stamp(GUARD, y - 8, "stripped"); }, 2400 * 0.5);
        b.onfinish = function () { tag.g.remove(); };
      }
    }

    function knock() {
      if (!shake.animate) return;
      shake.animate([{ transform: "rotate(0)" }, { transform: "rotate(-2deg)" }, { transform: "rotate(1deg)" }, { transform: "rotate(0)" }],
        { duration: 360, easing: "ease-out" });
    }
    function splash() {
      var c = document.createElementNS(NS, "ellipse");
      c.setAttribute("cx", "330"); c.setAttribute("cy", "426"); c.setAttribute("rx", "4"); c.setAttribute("ry", "1.5");
      c.setAttribute("fill", "none"); c.setAttribute("stroke", "#0c5449"); c.setAttribute("stroke-width", "1.5");
      forgeries.appendChild(c);
      var start = performance.now();
      (function grow(now) {
        var t = Math.min(1, (now - start) / 900);
        c.setAttribute("rx", (4 + t * 34).toFixed(1));
        c.setAttribute("ry", (1.5 + t * 5).toFixed(1));
        c.setAttribute("opacity", (1 - t).toFixed(2));
        if (t < 1) requestAnimationFrame(grow); else c.remove();
      })(start);
    }

    // The opening scene, for people happy with motion: the bridge is up,
    // two forgeries bounce off it, then a visitor signs in and it comes down.
    if (motion) {
      setDown(false);
      setAngle(90);
      say("The bridge is up. Only a signed-in visitor brings it down.");
      var played = false;
      var play = async function () {
        if (played) return; played = true;
        await wait(1900);
        if (down || busy) return;
        throwForgery();
        await wait(1500);
        if (down || busy) return;
        throwForgery();
        await wait(2600);
        if (down || busy) return;
        signIn();
      };
      if ("IntersectionObserver" in window) {
        var io = new IntersectionObserver(function (es) {
          if (es.some(function (e) { return e.isIntersecting; })) { io.disconnect(); play(); }
        }, { threshold: 0.35 });
        io.observe(svg);
      } else { play(); }
    } else {
      setDown(true);
      fig.classList.add("is-inside");
      placeVisitor(548, true);
      say("Signed in: the bridge is down and the visitor is inside with a fresh assertion. Sign out to raise it, or forge a header to watch it get stripped.");
    }
  }

  /* ---------------------------------------------------------- 3. explain */
  var RULES = [
    { path: "/up", prot: "public" },
    { path: "/assets/", prot: "public" },
    { path: "/admin/", prot: "login" },
    { path: "/api/", prot: "api" }
  ];

  // Mirrors src/Http/PathNormalizer.php: cut the query, refuse an encoded
  // slash or backslash, decode once, refuse control bytes, "\", ";" and bad
  // UTF-8, then resolve "." and "..", keeping a trailing slash.
  function normalize(raw) {
    var p = raw;
    ["?", "#"].forEach(function (d) { var i = p.indexOf(d); if (i !== -1) p = p.slice(0, i); });
    if (p === "" || p[0] !== "/") return null;
    if (/%(?:2f|5c)/i.test(p)) return null;
    var bytes = [], enc = new TextEncoder();
    for (var i = 0; i < p.length; i++) {
      var m = /^%([0-9a-fA-F]{2})/.exec(p.slice(i, i + 3));
      if (m) { bytes.push(parseInt(m[1], 16)); i += 2; continue; }
      var cp = p.codePointAt(i), ch = String.fromCodePoint(cp);
      enc.encode(ch).forEach(function (b) { bytes.push(b); });
      if (cp > 0xffff) i++;
    }
    var decoded;
    try { decoded = new TextDecoder("utf-8", { fatal: true }).decode(new Uint8Array(bytes)); } catch (e) { return null; }
    if (/[\x00-\x1f\x7f\\;]/.test(decoded)) return null;
    var segs = [];
    decoded.split("/").forEach(function (s) {
      if (s === "" || s === ".") return;
      if (s === "..") { segs.pop(); return; }
      segs.push(s);
    });
    var out = "/" + segs.join("/");
    if (segs.length && decoded.slice(-1) === "/") out += "/";
    return out;
  }
  function matches(rule, n) {
    if (rule.path.slice(-1) !== "/") return n === rule.path;
    var base = rule.path.replace(/\/+$/, "");
    return n === base || n === base + "/" || n.indexOf(base + "/") === 0;
  }
  function specificity(rule) {
    var base = rule.path.replace(/\/+$/, "");
    return base.length * 2 + (rule.path.slice(-1) === "/" ? 0 : 1);
  }

  var form = $("[data-explain]");
  if (form) {
    var input = $("#explain-path");
    var out = $("[data-explain-out]");
    var arg = $("[data-explain-arg]");
    var rows = $$(".door-table tbody tr");
    var run = function () {
      var raw = input.value;
      arg.textContent = /[\s"'$`\\;&|<>()]/.test(raw) || raw === "" ? "'" + raw.replace(/'/g, "'\\''") + "'" : raw;
      var n = normalize(raw), hit = "";
      if (n === null) {
        out.textContent = "\"" + raw + "\" is refused as unsafe to route, so it is treated as login-required.";
        out.classList.add("is-refused");
      } else {
        var best = null;
        RULES.forEach(function (r) { if (matches(r, n) && (!best || specificity(r) >= specificity(best))) best = r; });
        out.textContent = "app1 " + raw + " => " + (best ? best.prot : "login") + " (matched as " + n + ")";
        out.classList.remove("is-refused");
        hit = best ? best.path : "*";
      }
      rows.forEach(function (tr) { tr.classList.toggle("is-match", tr.getAttribute("data-rule") === hit); });
    };
    form.addEventListener("submit", function (e) { e.preventDefault(); run(); });
    input.addEventListener("input", run);
    $$("[data-path]", form).forEach(function (b) {
      b.addEventListener("click", function () { input.value = b.getAttribute("data-path"); run(); });
    });
    run();
  }

  /* ------------------------------------------------------ 4. the assertion */
  var fill = $("[data-life]");
  function mint() {
    var now = Math.floor(Date.now() / 1000);
    var hex = "";
    for (var i = 0; i < 12; i++) hex += "0123456789abcdef"[Math.floor(Math.random() * 16)];
    var set = function (s, v) { var el = $(s); if (el) el.textContent = v; };
    set("[data-iat]", now); set("[data-nbf]", now); set("[data-exp]", now + 60); set("[data-jti]", hex);
  }
  mint();
  if (fill && motion) fill.addEventListener("animationiteration", mint);

  /* ---------------------------------------------- 5. reveals, tabs, copy */
  var reveals = $$("[data-reveal]");
  if (motion && "IntersectionObserver" in window) {
    var ro = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add("is-in"); ro.unobserve(e.target); } });
    }, { threshold: 0.12, rootMargin: "0px 0px -8% 0px" });
    reveals.forEach(function (el) { ro.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add("is-in"); });
  }

  $$("[data-tabs]").forEach(function (root) {
    var tabs = $$('[role="tab"]', root);
    var select = function (tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute("aria-selected", on ? "true" : "false");
        t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute("aria-controls")).hidden = !on;
      });
      if (focus) tab.focus();
    };
    tabs.forEach(function (t, i) {
      t.addEventListener("click", function () { select(t); });
      t.addEventListener("keydown", function (e) {
        var j = null;
        if (e.key === "ArrowRight") j = (i + 1) % tabs.length;
        if (e.key === "ArrowLeft") j = (i - 1 + tabs.length) % tabs.length;
        if (e.key === "Home") j = 0;
        if (e.key === "End") j = tabs.length - 1;
        if (j !== null) { e.preventDefault(); select(tabs[j], true); }
      });
    });
    // Guess the visitor's system once, so Linux and macOS people land on theirs.
    if (!/Win/i.test(navigator.platform || navigator.userAgent)) select(tabs[1]);
  });

  var toast = $("[data-toast]"), toastTimer;
  function pop(msg) {
    toast.textContent = msg;
    toast.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.classList.remove("is-on"); }, 2200);
  }
  $$("[data-copy-from]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var pre = $(btn.getAttribute("data-copy-from"));
      var lines = pre.textContent.split("\n")
        .filter(function (l) { return /^(PS> |\$ )/.test(l); })
        .map(function (l) { return l.replace(/^(PS> |\$ )/, ""); });
      var text = lines.join("\n");
      var ok = function () { pop("Copied. Mind the moat."); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(ok, function () { pop("Copy failed: select the commands by hand."); });
      } else { pop("Copy failed: select the commands by hand."); }
    });
  });
})();
