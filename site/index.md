---
title: "Drawbridge: Comes down for signed-in people"
description: "Drawbridge is the sign-in gateway we're building for Citadel, Wayne State's container platform: Apache with mod_auth_openidc as one OpenID Connect client for Microsoft Entra ID. It drops any identity header a visitor sends and passes the app a 60-second ES256-signed assertion that SESAME checks. It isn't in front of any apps yet."
url: https://drawbridge.apps.wayne.edu/
llms: https://drawbridge.apps.wayne.edu/llms.txt
generator: Squire agent-ready
---

A-101 Gatehouse · section A-A

# Drawbridge

Comes down for signed-in people.

- **draw·bridge noun, not an acronym**: The one way into the Citadel. It comes down only for people who are signed in, and goes back up for everyone else.

Drawbridge is Apache with mod_auth_openidc, set up as one OpenID Connect client for Microsoft Entra ID. Its home will be **auth.apps.wayne.edu**, in front of every app on Citadel. It checks that the visitor has a session, drops any identity header they sent, and passes the app a 60-second ES256-signed assertion that SESAME verifies.

**Works alongside [WELD](https://weld.apps.wayne.edu/).** You don't install Drawbridge in your app; it's a Citadel service. It isn't in front of any apps yet, so WELD signs in with [SESAME](https://sesame.apps.wayne.edu/) directly for now. Once the gateway is live, Citadel apps switch SESAME to its `gateway` driver.

[Build the gatehouse](https://drawbridge.apps.wayne.edu/#build-title) [Read the section](https://drawbridge.apps.wayne.edu/#section-title)

![Drawbridge mark: a cream gatehouse tower on a green tile, its bridge lowered on a gold chain](https://drawbridge.apps.wayne.edu/brand/drawbridge-avatar-256.png)

The bridge is down for a signed-in visitor. A forged header would be stripped at the gate, before sign-in runs.

A-201 Longitudinal section

## One request through the gatehouse

Apache httpd with four modules and a small PHP component. Every request takes the same passage, in this order.

1. ### Browser
   Sends whatever headers it wants, forged ones included.
   the approach
2. ### Guard
   Strips every inbound identity header, in every hyphen and underscore spelling, before anything else runs.
   mod_lua
3. ### OpenID Connect client
   One Entra ID client for every app, with a separate session for each app host. Without a session, the browser is sent to Entra to sign in.
   mod_auth_openidc
4. ### Signer
   Signs a new ES256 assertion for each request. If signing fails, the visitor gets a 503.
   mod_rewrite prg: map + PHP
5. ### App
   Receives the request with one signed header and checks it with SESAME's `gateway` driver.
   mod_proxy

login

No session? The browser goes to Entra to sign in and comes back to the page.

api

Without a session it gets a plain **401**, so a script never receives an HTML login page.

public

Passes through with no identity attached. A path is public only if the config says so.

signer down

**503**. Nothing reaches the app unsigned.

Why a moat and not a lock on every door: each app doing its own OpenID Connect is another chance to get a nonce, a state check or a token signature wrong. We'd rather get that right once, in a maintained module (mod_auth_openidc). Each app then checks one small signed token.

A-301 Door schedule

## Which doors need a key

Each app lists its paths in one config file, and nothing else decides. A rule is exact (`/up`) or a prefix (`/assets/`). The longest match wins, and an exact rule beats a prefix of the same length. There are no wildcards or regular expressions, and the query string is never part of the match.

**Door schedule for `app1.apps.wayne.edu`, from `config/drawbridge.example.yaml`**

| Door | Path | Rule | Needs |
| --- | --- | --- | --- |
| D1 | `/up` | exact | public |
| D2 | `/assets/` | prefix | public |
| D3 | `/admin/` | prefix | login |
| D4 | `/api/` | prefix | api |
| D0 | *anything else* | default | login |

```
$ php bin/drawbridge explain app1.apps.wayne.edu /admin/users
app1 /admin/users => login (matched as /admin/users)
```

This box runs the same matching rules in your browser, written to agree with `explain` for the example config. The output lines are the real command's wording.

A-401 Detail · the assertion

## What crosses the bridge

A compact JWT, made fresh for every request and valid for 60 seconds by default. The signing key stays in the signer.

header

```
{
  "alg": "ES256",
  "typ": "drawbridge+jwt",
  "kid": "drawbridge-1"
}
```

claims (sample)

```
{
  "iss": "https://auth.apps.wayne.edu",
  "aud": "app1.apps.wayne.edu",
  "sub": "ab1234",
  "iat": 1798000000, "nbf": 1798000000,
  "exp": 1798000060,
  "jti": "4f1c9a2e7b3d",
  "auth_time": 1797999400,
  "amr": ["pwd", "mfa"],
  "email": "…", "name": "…",
  "affiliations": ["staff"]
}
```

Sent as `X-Sesame-Assertion`. Apps that cannot verify a token yet can take plain `X-Authenticated-*` headers instead (`mode: headers`), or both.

### Left at the gate

The guard removes these from every request, in any hyphen or underscore spelling, before authentication runs. Then the gateway sets its own.

- ~~X-Authenticated-\*~~
- ~~X-Sesame-\*~~
- ~~X-Drawbridge-\*~~
- ~~Remote-User~~
- ~~X-Remote-User~~
- ~~X-Forwarded-\*~~
- ~~Forwarded~~
- ~~X-Real-IP~~
- ~~X-Auth-Request-\*~~
- ~~OIDC-Claim-\*~~
- ~~Proxy~~

`Connection` is trimmed to `close`, `keep-alive` and `upgrade`, so a client cannot ask the proxy to drop a header the gateway set.

A-501 General notes

## Fourteen rules, each with a test

From `BEST_PRACTICES.md`. The contractor shall not improvise.

1. **Strip first.** Inbound identity headers go, in any spelling, before authentication runs on any request.
2. **Fail closed.** No session, no signature, a refused path, a missing key or a bad config means no access.
3. **Public is a decision.** A path with no rule gets `login`. Public paths carry no identity.
4. **Match the normalised path.** Encoded slashes, backslashes, semicolons and bad UTF-8 are treated as protected.
5. **Exact or prefix, nothing clever.** No wildcards or regular expressions. The Apache pattern is built from the literal path.
6. **Sign narrowly and briefly.** ES256, a pinned `typ`, one audience per app, a fresh `jti` each time.
7. **Let the module do OpenID Connect.** Drawbridge writes no OAuth client. Forged callbacks and missing contexts are refused.
8. **One session per app host.** A session cookie carried to another host opens nothing there.
9. **Overwrite forwarded headers.** `X-Forwarded-*` come from the gateway, never the client.
10. **Secrets stay out.** Keys are mounted files; the client secret and passphrase live in the environment. Logs carry a reason code, never a subject or a token.
11. **Do not trust the network.** The app verifies the signature even if only the gateway can reach it.
12. **Run small and boring.** Non-root, port above 1024, state under `/tmp`, logs to stdout.
13. **Bounded dependencies.** No `*`, no `dev-master`; `composer audit` runs in CI.
14. **Add headers, never overwrite.** The gateway's headers ride on proxied answers only where the app sent none, so HELMET's stay.

A-601 Construction sequence

## Build the gatehouse

You need Docker and nothing else. The PHP component needs PHP 8.3 or newer and Composer, and these steps run both inside the `composer:2.10.3` container.

**Windows (PowerShell)**

```
PS> git clone https://github.com/waynestate/drawbridge.git drawbridge; cd drawbridge
PS> docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 composer install --no-interaction
PS> Copy-Item .env.example .env
PS> Copy-Item config\drawbridge.example.yaml config\drawbridge.yaml
PS> docker run --rm -v "${PWD}:/app" -w /app composer:2.10.3 php bin/drawbridge config:validate --config=config/drawbridge.yaml
The config is valid: 1 app(s).
OpenID Connect provider: https://login.microsoftonline.com/<tenant-id>/v2.0
   … client id, secret (set or not, never its value), claims, amr map
 - app1 (app1.apps.wayne.edu): default login, 5 path rule(s), mode assertion, auth context: /admin/payroll/ c1
PS> docker build -f docker/gateway/Dockerfile -t drawbridge-gateway .
```

**Linux and macOS**

```
$ git clone https://github.com/waynestate/drawbridge.git drawbridge && cd drawbridge
$ docker run --rm -v "$PWD:/app" -w /app composer:2.10.3 composer install --no-interaction
$ cp .env.example .env
$ cp config/drawbridge.example.yaml config/drawbridge.yaml
$ docker run --rm -v "$PWD:/app" -w /app composer:2.10.3 php bin/drawbridge config:validate --config=config/drawbridge.yaml
The config is valid: 1 app(s).
OpenID Connect provider: https://login.microsoftonline.com/<tenant-id>/v2.0
   … client id, secret (set or not, never its value), claims, amr map
 - app1 (app1.apps.wayne.edu): default login, 5 path rule(s), mode assertion, auth context: /admin/payroll/ c1
$ docker build -f docker/gateway/Dockerfile -t drawbridge-gateway .
```

### Then raise it

The entrypoint won't start without two files mounted read-only and two secrets in the environment. None of them goes in git.

- `drawbridge.yaml` the per-app config
- `signing.key` the ES256 key; `keys:generate` makes one
- `DRAWBRIDGE_OIDC_CLIENT_SECRET` the Entra client secret, from the app registration
- `DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE` encrypts sessions; 32+ characters

```
$ docker run --rm -p 8080:8080 --env-file .env \
    -v "$PWD/config/drawbridge.yaml:/etc/drawbridge/drawbridge.yaml:ro" \
    -v "$PWD/secrets:/run/drawbridge:ro" drawbridge-gateway
```

### The drafting kit `php bin/drawbridge <command>`

- **`config:validate`**: checks the config and prints every problem at once
- **`explain <host> <path>`**: says which rule applies, and why
- **`render`**: writes `apps.conf` and `policy.lua` for Apache
- **`keys:generate`**: an ES256 key pair; refuses to overwrite

```
$ composer ci     # manifest, unit tests, Pint, PHPStan level 8, composer audit
PS> rig\run.ps1   # the real image, a mock OpenID Connect provider and an echo app (Windows)
$ sh rig/run.sh   # the same rig on Linux and macOS
```

The rig covers sign-in, public and protected paths, header spoofing (forged `OIDC_*` headers too), path tricks, forged callbacks, step-up to an authentication context, signature checks against SESAME's rules, and logout. Nothing in it reaches Entra.

A-602 Specifications

## Specs for your agent

Your coding agent is the contractor. Copy a spec, or open it directly in OpenCode, Claude Code, Codex or ChatGPT. Each spec gives the task and what the agent must leave alone, and tells it to ask you before it guesses.

### Get started with Drawbridge

Clone, validate the example config, ask explain about three doors, and run the rig against a mock provider. Nothing reaches Entra.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent helping a Wayne State University developer stand up Drawbridge on their own machine. Drawbridge is the Citadel auth gateway of WELD, the Wayne State application stack: Apache with mod_auth_openidc in front of every app, one OpenID Connect client for Microsoft Entra ID, a guard that strips every identity header a visitor brings, and a 60-second ES256 assertion that SESAME's gateway driver verifies.

Read first:
- https://drawbridge.apps.wayne.edu/llms.txt, the index of Drawbridge's pages, each one as Markdown. Work from them, not from memory.
- ADVISOR, the stack's MCP server, if it is connected: read_project_docs with project drawbridge, and list_stack_rules with chapter auth. If it is not connected, say so and work from the pages. How to add it: https://advisor.apps.wayne.edu/llms.txt
- The README's Install and quick start, Configuration and Commands sections, and config/drawbridge.example.yaml.

Task:
1. If this folder is not a checkout, clone it with `git clone https://github.com/waynestate/drawbridge.git`. Install with `composer install`, or in the composer:2.10.3 container the README uses if there is no PHP 8.3 here.
2. Copy the example config and the example environment file to their working names, as the README does, then run `php bin/drawbridge config:validate --config=config/drawbridge.yaml`.
3. Run the explain command on three paths of app1 (one public, one protected, one api) and tell me which rule wins each time, and why.
4. Run the container rig: rig\run.ps1 on Windows, sh rig/run.sh on Linux and macOS. It signs in against a mock OpenID Connect provider and never reaches Entra.

Constraints:
- Never put a real tenant id, client id, client secret, crypto passphrase or signing key in a file git tracks. Keys and secrets are never in git.
- Leave the guard and the path rules alone: exact or prefix only, no wildcards, no regular expressions.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- Ask me before you guess, and before anything talks to a real Entra tenant.

Output: the commands you ran, the three explain answers, the rig result, then your questions.
```

### Or the spec for the app behind the gate

### A Laravel app behind Drawbridge

Its door schedule for the gate, and SESAME's gateway driver checking the signed assertion on the way in.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent putting a Laravel app at Wayne State University behind Drawbridge, the Citadel auth gateway of WELD, the Wayne State application stack. Drawbridge signs the visitor in and sends the app a short-lived signed assertion in the X-Sesame-Assertion header. The app checks it with SESAME's gateway driver and never parses the token itself.

Read first:
- https://drawbridge.apps.wayne.edu/llms.txt, then the README's Using the assertion in an app and Configuration sections.
- SESAME's pages, https://sesame.apps.wayne.edu/llms.txt: the gateway driver and its Laravel setup.
- ADVISOR, the stack's MCP server, if it is connected: list_stack_rules with the permutation that fits this app (laravel-blade, laravel-blade-vue or laravel-inertia-vue) and chapter auth, and read_project_docs with project sesame. If it is not connected, say so and work from the pages.
- This app's composer.json, config/, routes/ and .env.example.

Task:
1. Draft this app's entry for Drawbridge's config: id, host, upstream, mode assertion, a default, and the public, protected and api paths the app really has (routes/ tells you).
2. Set up SESAME's gateway driver the way SESAME's docs say: the issuer Drawbridge signs with, this app's host as the audience, Drawbridge's public signing key, the X-Sesame-Assertion header and the trusted proxy ranges, all as placeholders in .env.example.
3. Put the auth.sesame middleware on the routes Drawbridge protects, and make sure the api routes answer 401 instead of redirecting.
4. If a path needs step-up (an Entra authentication context such as c1), mark it in the draft and add sesame.context:c1 to that route. What c1 demands is a Conditional Access policy in Entra, not app code.

Constraints:
- Assertion mode, not plain headers: a Laravel app can verify the token, so it does.
- Never trust X-Authenticated-* or any other identity header in the app. Only the verified assertion counts.
- Keep SESAME's replay guard on. Never commit a key, a secret or a real .env.
- Ask me before you guess the host, the issuer or the proxy ranges, and before you change auth middleware that is already there.

Output: the config entry, the SESAME settings as placeholders, the files you changed, then your questions.
```

### A raw PHP app behind Drawbridge

The same door schedule, and SESAME's gateway driver built by hand, with the check placed before any output.

A prompt for your coding agent. Copy it as it stands:

```text
You are a coding agent putting a raw PHP app (no framework) at Wayne State University behind Drawbridge, the Citadel auth gateway of WELD, the Wayne State application stack. Drawbridge signs the visitor in and sends the app a short-lived signed assertion in the X-Sesame-Assertion header.

Read first:
- https://drawbridge.apps.wayne.edu/llms.txt, then the README's Using the assertion in an app and Configuration sections.
- SESAME's pages, https://sesame.apps.wayne.edu/llms.txt: the gateway driver and the Raw PHP section.
- ADVISOR, the stack's MCP server, if it is connected: list_stack_rules with permutation raw-php (raw-php-vue if the app builds Vue) and chapter auth, and read_project_docs with project sesame. If it is not connected, say so and work from the pages.
- This app's composer.json, its front controller or entry scripts, and how it signs people in today.

Task:
1. Draft this app's entry for Drawbridge's config: id, host, upstream, a default, and the public, protected and api paths the app really has.
2. Assertion mode is the default. Build SESAME's gateway driver as its Raw PHP section shows (public key, issuer, audience, trusted proxies, and a PSR-16 cache for the replay guard), and sign the user in from the verified assertion.
3. Headers mode is only for an app that cannot verify a token yet. If you think this app is one, say why and ask me before you choose it.
4. Replace the app's own sign-in only on the paths Drawbridge protects, and show me the diff before you apply it.

Constraints:
- A raw PHP app has no framework to lean on: show exactly where the check runs, before any output is sent.
- Never trust an identity header the app did not get from a verified assertion.
- Keep the replay guard on. Never commit a key, a secret or a real .env.
- Give separate commands for Windows (PowerShell), macOS and Linux wherever they differ.
- Ask me before you guess, and before you touch anything that deploys.

Output: the config entry, where the check runs and the diff, then your questions.
```

Agents read the same pages you do. [llms.txt](https://drawbridge.apps.wayne.edu/llms.txt) lists them, [llms-full.txt](https://drawbridge.apps.wayne.edu/llms-full.txt) has them all in one file, and this page has a [Markdown twin](https://drawbridge.apps.wayne.edu/index.md). The page sends nothing anywhere; the buttons only copy text or open the tool you picked. No spec lowers the bridge for anyone who hasn't signed in.

A-701 Site plan

## Where the gate stands in the stack

Drawbridge is one part of the Wayne State application stack. [WELD](https://weld.apps.wayne.edu/) is the Laravel starter app with the rest of it already set up. Once it's live, Drawbridge is the one door in Citadel's wall, and the apps and the stack's other parts run inside.

1. Drawbridgesigns the visitor in, once
2. [SESAME](https://sesame.apps.wayne.edu/)its gateway driver verifies the token
3. [HELMET](https://helmet.apps.wayne.edu/)sets the security headers
4. [WayneUI](https://wayneui.apps.wayne.edu/)renders the page

### Key

- **[WayneUI](https://wayneui.apps.wayne.edu/)** the interface layer
- **[CREED](https://creed.apps.wayne.edu/)** the shared rules and policy
- **[PROCTOR](https://proctor.apps.wayne.edu/)** checks an app against the policy
- **[Preflight](https://preflight.apps.wayne.edu/)** the shared CI components
- **[SESAME](https://sesame.apps.wayne.edu/)** sign-in inside the app
- **[HELMET](https://helmet.apps.wayne.edu/)** security headers and CSP reports
- **[Pinstripe](https://pinstripe.apps.wayne.edu/)** code style presets
- **[Sourdough](https://sourdough.apps.wayne.edu/)** the raw PHP starter
- **[WELD](https://weld.apps.wayne.edu/)** the Laravel starter, with the stack set up
- **[Booster](https://booster.apps.wayne.edu/)** upgrades PHP and Laravel one version at a time
- **[Rollcall](https://rollcall.apps.wayne.edu/)** every app answers "present"
- **[ADVISOR](https://advisor.apps.wayne.edu/)** an MCP server your agent can ask
- **[Squire](https://squire.apps.wayne.edu/)** the deploy kit for Citadel
- **Drawbridge** the sign-in gateway for Citadel's apps *you are here*

A-801 Revision schedule

## Drawn, not yet built

Release 0.2 (not released yet) swaps SAML for OpenID Connect with Entra ID. The guard, the signer and the rig work as before. So far only the gateway image is built: it will run as a service on Citadel, and the routing in front of it is still the platform team's to design. The items below come from `OPEN_QUESTIONS.md`, each with the safer default the code uses until it's built.

**Planned revisions**

| Rev | Item | Until then |
| --- | --- | --- |
| next | Shared session store, more than one instance | One instance, sessions in its shared memory; a restart signs everyone out. |
| next | Bearer tokens on API paths | An `api` path without a session gets a plain 401. |
| next | Two signing keys at once, for rotation | One key, one `kid`; rotation is a short coordinated window. |
| next | Identity on public paths, if wanted | Public means no identity, ever. |
| next | Live signer counters on /metrics | The off-by-default endpoint reports build info only; counters stay in the signer. |
