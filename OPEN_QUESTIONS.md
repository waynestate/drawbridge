# Open questions

Three sections: what we need answered, what has to be supplied before a real deployment, and what is not built yet.
"Default built" is what the code does today so work could continue; each default is the safer choice.

## Questions

### The identity provider: Microsoft Entra ID (E-1 to E-8)

| ID | Question | Who answers | Blocks | Default built |
|---|---|---|---|---|
| E-1 | What does a real ID token from the tenant carry in `amr`? | Entra admin, checked on a real sign-in (inspect the token, no secret needed) | MFA-aware apps | The default `amr_map` passes `pwd`, `mfa`, `otp`, `rsa`, `wia` through, maps `ngcmfa` to `mfa`, and keeps a harmless REFEDS MFA entry. An unknown value gives no `amr`, never a guessed `mfa`. Every value is from Entra's documentation, not yet from a real token. |
| E-2 | Is `auth_time` released as an optional ID token claim? | Entra admin | apps that ask "how long ago did you sign in" | Copied into the assertion when present (and dropped if it is in the future); left out when the token has none. The rig always sends it. |
| E-3 | Which claim should become `sub`: `preferred_username` (the default) or `alias` (the AccessID, `user.mailnickname`) once released as an optional claim? | Entra admin; app owners | the user identifier every app stores | `oidc.user_claim: preferred_username`. Changing to `alias` is one line of config after Entra releases the claim. Changing it later changes every app's user key, so decide before the first app goes live. |
| E-4 | Can `wsuRoles` (a custom attribute synced from on-premises AD) be emitted as a claim, and under what name (a directory extension name is usually `extension_<app id>_wsuRoles`)? | Entra admin | apps that authorise by role | `extra_claims` copies any listed claim by its exact name. The example config lists none; the rig and tests use `wsuRoles`. If Entra emits the long extension name, list that name (a rename option is not built). |
| E-5 | Which authentication contexts (`c1` ...) exist, and what Conditional Access policy does each demand? | Entra admin; security | any path with `auth_context` | The gateway asks for the context and requires it in `acrs` (membership, not equality). What a context means is Entra's business. Only tested against the mock provider; see "Not verified against Entra" below. |
| E-6 | Client secret lifetime and rotation: who rotates it, how far ahead, and is an overlap of two valid secrets used? | Entra admin; platform team | uninterrupted sign-in | One secret in one environment variable. Rotation is: add a new secret in Entra, update the variable, restart the gateway, delete the old secret. Existing sessions survive (the passphrase did not change). An expired secret means nobody can sign in, so the expiry date needs an owner and a reminder. |
| E-7 | Are redirect URIs per app host acceptable to register (one per host, added as apps join)? | Entra admin | adding an app | One redirect URI per host, `https://<host>/_drawbridge/oidc/callback`. A single shared callback host was not built: it would mean one session for every app. |
| E-8 | Sign-out: does Entra accept a `post_logout_redirect_uri` on the app host that is not itself a registered redirect URI? | Entra admin, checked on a real sign-out | where a visitor lands after signing out | mod_auth_openidc ends the gateway session for the host and sends the browser through the provider's end-session endpoint. The rig proves the local session dies and that another host is refused as a target; Entra's handling of the return address is unverified. |

### The platform (C-1 to C-7)

| ID | Question | Who answers | Blocks | Default built |
|---|---|---|---|---|
| C-1 | Can app containers reach each other's port directly? | platform team | how much the network protects as a second control | The gateway assumes they can. Apps must verify the signature (SESAME `gateway` driver). |
| C-2 | Is an upgrade of the front HA-Proxy or its OS planned? | platform team | whether the gateway can sit behind it unchanged | The image is independent of the front proxy. `DRAWBRIDGE_TRUSTED_PROXIES` takes the front proxy's addresses. |
| C-3 | Where does the gateway sit: Option A, B or C from the routing research? | platform team | the public routing and TLS design | Only the gateway container is built. Placement is configuration. |
| C-4 | Who builds the image, runs it, and when? Do the GitLab runners allow Docker-in-Docker? | platform team | production rollout; the GitLab rig job | The GitHub rig job runs automatically. The GitLab rig job is manual and may fail: a Docker-in-Docker runner cannot mount the checkout into the test container the way `rig/run.sh` does. |
| C-6 | Proxy and signer language. | decided | | Apache httpd + mod_auth_openidc + mod_proxy for the proxy and OpenID Connect client; a small PHP component for the signer, config validation and header policy. **Alternative recorded: a Go signer** (a single static binary replacing the PHP `prg:` process). It would cut the image size and start-up time. It was not chosen because PHP is the shared stack language and the signer is a short class on a maintained JWT library. Revisit if the PHP process shows up in profiling. |
| C-7 | Key distribution and rotation: how do apps get the public key, and how does rotation overlap? | platform team; SESAME owner | rotation without downtime | One signing key, one `kid`. The public half is a file apps copy. Rotation needs the app side to accept two keys at once (SESAME's `gateway` driver takes one key today), so rotation currently means a short coordinated window. |

### Questions that came up while building

| Question | Who answers | Blocks | Default built |
|---|---|---|---|
| Should SESAME's `gateway` driver check `jti` against a replay cache? Assertions live 60 seconds and are minted per request, so replay is bounded but possible. | SESAME owner | replay protection at the app | Nothing. SESAME is read-only for this work; DRAWBRIDGE only puts a fresh `jti` in every token. |
| Should apps be allowed to see identity on public paths (an "identify" mode: signed in visitors get the assertion, anonymous ones pass)? | app owners | public pages that say "Hello, name" | No. Public means no identity, ever. |
| How many gateway instances are needed, and where is the session kept? | platform team | scale-out and restarts | One instance, sessions in Apache's shared memory, encrypted. A restart signs everyone out and a second instance needs sticky routing or a shared cache (mod_auth_openidc supports Redis and memcached; not configured). |
| How is the crypto passphrase rotated? | platform team | passphrase hygiene | Changing `DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE` and restarting signs everyone out (sessions and in-flight sign-ins become unreadable), which is safe but noisy. Recent mod_auth_openidc releases document a second, previous passphrase for a graceful change; that is neither checked against the pinned version nor wired into the render. |
| Does the `__Host-` cookie actually carry `Secure` behind the front proxy? | platform team, checked on the first https deployment | cookie hardening | With `DRAWBRIDGE_COOKIE_SECURE=true` the session cookie is named `__Host-drawbridge`. mod_auth_openidc sets `Secure` when it sees the request as https, which it reads from the rendered `ServerName https://...`. A browser silently drops a `__Host-` cookie without `Secure`, so a misread would fail closed (nobody stays signed in), not open. The rig is plain http and cannot check this. |
| Should API paths accept an Entra access token (`Authorization: Bearer`) instead of a browser session? | app owners; security | machine-to-machine calls through the gateway | No. `api` paths take the same browser session and answer 401 without one. Bearer validation (mod_auth_openidc's `oauth20` mode) is not configured. |

## Missing configuration

Nothing here is in git. Each item is a file or value an operator supplies; the container refuses to start without
the files and the secrets.

| Item | Source | Placeholder in this repo |
|---|---|---|
| Tenant ID (in `oidc.issuer`) | the Entra admin | `00000000-0000-0000-0000-000000000000` in the example config |
| Application (client) ID (`oidc.client_id`) | the Entra app registration | zeros in the example config; `drawbridge-rig` in the rig |
| Client secret (`DRAWBRIDGE_OIDC_CLIENT_SECRET`) | the Entra app registration, kept in the deployment's secret store (E-6) | empty in `.env.example`; a throwaway value in `rig/compose.yaml` that the mock accepts |
| Crypto passphrase (`DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE`) | generated by the platform team, 32+ characters | empty in `.env.example`; a throwaway value in `rig/compose.yaml` |
| Redirect URIs, one per app host (E-7) | registered by the Entra admin | none |
| Optional claims (`email`, `auth_time`, `acrs`, and `alias` or `wsuRoles` if used) | the Entra admin (E-2 to E-4) | the `oidc` block in the example config |
| Authentication contexts and their Conditional Access policies (E-5) | the Entra admin; security | `/admin/payroll/` with `c1` in the example config |
| Assertion signing key (`signing.key`) and its `kid` | `keys:generate`, kept by the platform team; the public half goes to every app | rig key generated at test time, `kid` `rig-1` |
| Public signing key for apps | the platform team, to each app's SESAME `gateway` driver configuration | none |
| `DRAWBRIDGE_CONFIG` file | the platform team, one entry per app (host, upstream, path rules) | `config/drawbridge.example.yaml` |
| App hosts and upstream addresses | each app owner | `app1.apps.wayne.edu`, `http://app1:8080` |
| `DRAWBRIDGE_TRUSTED_PROXIES` | the platform team (C-2, C-3) | empty (trust nobody) |
| `DRAWBRIDGE_PUBLIC_SCHEME`, `DRAWBRIDGE_PUBLIC_PORT` | the platform team | `https`, empty |
| TLS certificate for the app hosts | the platform team; terminated in front of the gateway | none (the image speaks HTTP behind the front proxy) |
| Registry and runner details for building and publishing the image | the platform team (C-4) | none |
| GitHub and GitLab repository settings (badges, mirror) | repository owners | badge URLs in `README.md` are placeholders |

## Not verified against Entra

The rig's provider is [mock-oauth2-server](https://github.com/navikt/mock-oauth2-server): a real OpenID Connect
server, but it signs in anyone and ignores what it is asked for. So these hold in the rig and still need one check
against the real tenant:

- that Entra honours the `claims` request for an authentication context and returns `acrs` (the mock just echoes
  what the test types in);
- the real `amr`, `auth_time`, `alias` and `wsuRoles` values (E-1 to E-4);
- sign-out through Entra's end-session endpoint (E-8);
- `Secure` on the `__Host-` cookie behind https (above).

## Not built yet

| Item | Why not now | Planned |
|---|---|---|
| Identify-on-public-paths mode | needs a decision (above) | later |
| Bearer tokens on API paths | needs a decision (above) | later |
| Session revocation (end a user's sessions from outside) | sessions live in one instance's shared memory; needs a shared store | later |
| Shared session store and more than one instance | see the scale-out question | later |
| Graceful crypto passphrase rotation | see the question above | later |
| Renaming an extra claim (for a long directory extension name) | waits for E-4 | when E-4 is answered |
| Two accepted signing keys / rotation overlap on the app side | SESAME is read-only here (C-7) | later |
| `jti` replay check in SESAME's `gateway` driver | SESAME is read-only here | SESAME release |
| Prometheus metrics (off by default) | no Prometheus server exists yet | later |
| Test the signed token against the real SESAME `GatewayDriver` class | the e2e suite mirrors its checks (`tests/Support/SesameContract.php`) because SESAME is not installable from here; an optional test that runs the real class when `SESAME_PATH` is set was left out | later |
| Docker-in-Docker rig job on the GitLab runners | needs C-4; the GitLab job is manual | when C-4 is answered |
| Build and publish the image to a registry | needs C-4 | later |
| Release tag and GitHub release | tags and releases are not made from this build | when approved |
