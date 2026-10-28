# DRAWBRIDGE

[![GitHub CI](https://github.com/waynestate/drawbridge/actions/workflows/ci.yml/badge.svg)](https://github.com/waynestate/drawbridge/actions/workflows/ci.yml)

Checks: `composer ci` (the same command runs on GitLab and GitHub). The container rig has its own script, see "Run the checks".

> The one way into the Citadel. It only comes down for people who signed in.

DRAWBRIDGE is the Citadel auth gateway at `auth.apps.wayne.edu`: one SAML service provider for every app on the
platform. It sits in front of the apps as a reverse proxy, makes sure the visitor has a session, throws away any
identity header the visitor tried to bring along, and passes the app either a short-lived signed assertion that
SESAME's `gateway` driver verifies, or plain `X-Authenticated-*` headers for apps that cannot verify a token yet.
Which paths need a login is declared per app in a config file. Nothing else decides it.

Why a moat and not a lock on each door: every app doing its own SAML is another chance to get XML
signatures wrong. DRAWBRIDGE does the sign-in once, in a maintained SP, and every app only has to check one small
signed token.

This repository is published from Wayne State University's internal GitLab and mirrored here. Changes land there
first.

## Where it fits in WELD

WELD is the Wayne State application stack: one contract, several paths that agree, accessibility as a gate, and
decisions written down.

| Part | Job |
|---|---|
| WayneUI | the interface layer (project 0 of the stack) |
| CREED | the shared rules and policy format |
| PROCTOR | checks an app against the version policy |
| PREFLIGHT | the shared CI components |
| SESAME | sign-in inside the app; its `gateway` driver verifies what DRAWBRIDGE signs |
| HELMET | security headers and CSP reporting |
| Pinstripe | the Laravel example that wears all of it |
| Sourdough | the starter that keeps the stack fed |
| **DRAWBRIDGE** | **the one SAML front door for the Citadel** |

## How it works

```
browser --> Apache httpd (DRAWBRIDGE) --> app container
              |  mod_auth_mellon : SAML SP, one session cookie per app host
              |  mod_lua         : strips identity headers on every request, before anything else
              |  mod_rewrite     : asks the PHP signer for an assertion (prg: map)
              |  mod_proxy       : forwards to the app
              `- PHP component   : validates the config, renders the Apache config, signs assertions (ES256)
```

- A request for a **protected** path without a session is redirected to the IdP. For an **api** path it gets a plain
  401, so a script is never handed an HTML login page. A **public** path passes through with no identity at all.
- The assertion is a compact JWT with `typ: drawbridge+jwt` and a `kid`. Claims: `iss`, `aud` (the app's host),
  `sub`, `iat`, `nbf`, `exp` (60 seconds by default), `jti`, `auth_time` and `amr` when the IdP says so, plus
  `email`, `name` and `affiliations`. A new one is minted for every request.
- If the signer cannot sign, the answer is 503. A request never reaches the app unsigned.
- Header stripping: the guard removes every inbound `X-Authenticated-*`, `X-Sesame-*`, `X-Drawbridge-*`,
  `Remote-User`, `X-Forwarded-*`, `Forwarded` and similar header, in every hyphen and underscore spelling, on every
  request, before authentication runs. It also trims `Connection` to `close`, `keep-alive` and `upgrade`, so a client
  cannot ask the proxy to drop a header the gateway set.

Known limit: Apache's header table cannot be listed from Lua, so the guard removes a generated list of explicit names
(the gateway's own headers, plus each owned prefix joined to the common identity words). A made-up header under an
owned prefix that nothing reads is not on that list. Apps must read only the headers the gateway documents, and
SESAME's `gateway` driver reads one.

## Install and quick start

You need Docker. The PHP component needs PHP 8.3 or newer and Composer, but the container carries its own.

**Windows (PowerShell)**

```powershell
git clone <this repo> drawbridge; cd drawbridge
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 composer install --no-interaction
Copy-Item .env.example .env
Copy-Item config\drawbridge.example.yaml config\drawbridge.yaml
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 php bin/drawbridge config:validate --config=config/drawbridge.yaml
docker build -f docker/gateway/Dockerfile -t drawbridge-gateway .
```

**Linux and macOS**

```sh
git clone <this repo> drawbridge && cd drawbridge
docker run --rm -v "$PWD:/app" -w /app composer:2.10.3 composer install --no-interaction
cp .env.example .env
cp config/drawbridge.example.yaml config/drawbridge.yaml
docker run --rm -v "$PWD:/app" -w /app composer:2.10.3 php bin/drawbridge config:validate --config=config/drawbridge.yaml
docker build -f docker/gateway/Dockerfile -t drawbridge-gateway .
```

With PHP installed locally the same commands are `composer install` and `php bin/drawbridge ...`.

Then run the image with the files it cannot start without mounted read-only (the entrypoint refuses to start if any is
missing):

| File | What it is |
|---|---|
| `drawbridge.yaml` | the per-app config |
| `idp-metadata.xml` | the IdP's SAML metadata |
| `sp.key`, `sp.crt` | the SP's SAML key pair (`sp:metadata` prints the metadata to register) |
| `signing.key` | the ES256 key that signs assertions (`keys:generate` makes one) |

```sh
docker run --rm -p 8080:8080 --env-file .env \
  -v "$PWD/config/drawbridge.yaml:/etc/drawbridge/drawbridge.yaml:ro" \
  -v "$PWD/secrets:/run/drawbridge:ro" drawbridge-gateway
```

Keys, certificates and IdP metadata are never in git. `OPEN_QUESTIONS.md` lists where each real one comes from.

## Configuration

One YAML (or PHP array) file declares the gateway and each app. `config/drawbridge.example.yaml` is annotated.

```yaml
apps:
  - id: app1
    host: app1.apps.wayne.edu
    upstream: http://app1:8080
    mode: assertion          # or "headers", or "both"
    default: login           # what a path with no rule gets
    public: [/up, /assets/]
    protected: [/admin/]
    api: [/api/]
```

Path rules are exact (`/up`) or prefix (a trailing slash, `/assets/`). There are no wildcards and no regular
expressions. The longest rule wins, and an exact rule beats a prefix of the same length. Query strings are never part
of the match. A path with an encoded slash, a backslash, a semicolon, a control character or bad UTF-8 is treated as
`login` (and the guard answers 400). `/_drawbridge` belongs to the gateway.

## Environment variables

| Variable | Default | Meaning |
|---|---|---|
| `DRAWBRIDGE_CONFIG` | `/etc/drawbridge/drawbridge.yaml` | the per-app config file |
| `DRAWBRIDGE_PORT` | `8080` | port Apache listens on (non-root, so above 1024) |
| `DRAWBRIDGE_PUBLIC_SCHEME` | `https` | how browsers reach the gateway; feeds `ServerName`, `X-Forwarded-Proto`, metadata |
| `DRAWBRIDGE_PUBLIC_PORT` | empty | public port when it is not the scheme default |
| `DRAWBRIDGE_COOKIE_SECURE` | `true` | Secure session cookie; `false` only for a plain-HTTP local rig |
| `DRAWBRIDGE_TRUSTED_PROXIES` | empty | comma-separated CIDRs allowed to set `X-Forwarded-For`; empty trusts nobody |
| `DRAWBRIDGE_IDP_METADATA_FILE` | `/run/drawbridge/idp-metadata.xml` | IdP metadata (mounted) |
| `DRAWBRIDGE_SP_KEY_FILE` | `/run/drawbridge/sp.key` | SAML SP private key (mounted) |
| `DRAWBRIDGE_SP_CERT_FILE` | `/run/drawbridge/sp.crt` | SAML SP certificate (mounted) |
| `DRAWBRIDGE_SIGNING_KEY_FILE` | `/run/drawbridge/signing.key` | ES256 private key for assertions (mounted) |
| `DRAWBRIDGE_SIGNING_KEY_ID` | `drawbridge-1` | `kid` in each token header; change it when the key changes |
| `DRAWBRIDGE_SESSION_CACHE_ENTRIES` | `1000` | sessions held in memory (minimum 10) |
| `DRAWBRIDGE_RUN_DIR` | `/tmp/drawbridge` | scratch for rendered config, locks and the session cache |
| `DRAWBRIDGE_LOG_LEVEL` | `warn` | Apache log level |

## Commands

Run as `php bin/drawbridge <command>` (or `vendor/bin/drawbridge` when installed as a dependency).

| Command | Does |
|---|---|
| `config:validate` | checks the config and prints every problem at once; exit 1 if any |
| `explain <host> <path>` | says which rule applies to a request and why |
| `render` | writes `apps.conf` and `policy.lua` for Apache (the entrypoint runs this) |
| `sp:metadata` | prints the SAML SP metadata: one entity ID, one ACS per app host |
| `keys:generate` | makes an ES256 key pair; refuses to overwrite a file |
| `signer:map` | the long-running process behind Apache's `prg:` map (not for people) |

## Run the checks

```sh
composer ci          # manifest, unit tests, Pint, PHPStan level 8, composer audit
```

In Docker, with no local PHP:

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 sh -c "composer install --no-interaction && composer ci"
```

The container rig runs the real gateway image with a throwaway SimpleSAMLphp IdP and a tiny echo app, and covers
login, public and protected paths, header spoofing, path tricks, tampered SAML, signature checks against SESAME's
`gateway` driver rules, and logout. Nothing in it reaches a real IdP.

```powershell
rig\run.ps1          # Windows
```

```sh
sh rig/run.sh        # Linux, macOS
```

## Using the assertion in an app

Point SESAME's `gateway` driver at DRAWBRIDGE: the same issuer, the app's host as the audience, the signing public
key, and the header `X-Sesame-Assertion`. Apps do not parse the token themselves.

## Documents

- `BEST_PRACTICES.md`: the short list of rules the gateway holds itself to.
- `OPEN_QUESTIONS.md`: what is unanswered, what is missing, what is not built yet.
- `CHANGELOG.md`: what changed.
- `site/`: the front page, a static sheet set for the gatehouse (`site/index.html`; see `site/README.md`).

## The name

It is a drawbridge, so it goes up on strangers and down on friends. If you find a header it should have caught,
raise the issue. The moat is only as good as the people who keep reporting where it is shallow.

## Licence

MIT. Copyright Wayne State University.
