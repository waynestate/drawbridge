# Open questions

Three sections: what we need answered, what has to be supplied before a real deployment, and what is not built yet.
"Default built" is what the code does today so work could continue; each default is the safer choice.

## Questions

### The identity provider (I-1 to I-7)

| ID | Question | Who answers | Blocks | Default built |
|---|---|---|---|---|
| I-1 | Does the IdP (Academica) support SAML single logout, and which binding? | owner of the IdP | real single logout | Logout ends the gateway session only (local logout). The visitor is returned to the same host; mellon refuses other targets. |
| I-2 | Does the IdP sign the Response, the Assertion, or both? | owner of the IdP | tightening the signature policy | mod_auth_mellon accepts a signed Response or a signed Assertion. The rig IdP signs the assertion. Tampered responses are refused (tested). |
| I-3 | Are assertions encrypted? | owner of the IdP | SP encryption key setup | The SP key pair is used for signing and decryption; nothing assumes encryption. |
| I-4 | Which attributes are released (uid, mail, display name, affiliation, others)? | owner of the IdP | the `attributes` map in the config | `sub` from `NAME_ID`, `email` from `mail`, `name` from `displayName`, `affiliations` from `eduPersonAffiliation`. All four are settable per deployment. |
| I-5 | Is there a test SAML environment, and what is the key rotation process? | owner of the IdP | any test against a real IdP; rotation plan | Everything runs against the throwaway SimpleSAMLphp IdP in `rig/`. No real IdP is ever contacted. |
| I-6 | Can one SP entity ID cover `auth.apps.wayne.edu` with one assertion consumer URL per app host and a per-app audience? | owner of the IdP | the shape of the IdP registration | One entity ID; `sp:metadata` lists one ACS per app host. If the IdP wants one SP per app, `sp_entity_id` becomes per-app (a config change, no code change). |
| I-7 | Does the authentication statement carry the login time (`auth_time`), and which `AuthnContextClassRef` values mean what (`amr`)? | owner of the IdP; SESAME owner for the MFA list | MFA-aware apps | `auth_time` comes from `AuthnInstant` when present. The default `amr_map` covers PasswordProtectedTransport and Password as `pwd`; MobileTwoFactorContract as `pwd`,`mfa`; TimesyncToken as `otp`,`mfa`; Smartcard as `hwk`; the REFEDS MFA profile as `mfa`. An unknown class gives no `amr` (never a guessed `mfa`). Values come from the standard list, not from what the real IdP sends. |

### The platform (C-1 to C-7)

| ID | Question | Who answers | Blocks | Default built |
|---|---|---|---|---|
| C-1 | Can app containers reach each other's port directly? | platform team | how much the network protects as a second control | The gateway assumes they can. Apps must verify the signature (SESAME `gateway` driver). |
| C-2 | Is an upgrade of the front HA-Proxy or its OS planned? | platform team | whether the gateway can sit behind it unchanged | The image is independent of the front proxy. `DRAWBRIDGE_TRUSTED_PROXIES` takes the front proxy's addresses. |
| C-3 | Where does the gateway sit: Option A, B or C from the routing research? | platform team | the public routing and TLS design | Only the gateway container is built. Placement is configuration. |
| C-4 | Who builds the image, runs it, and when? Do GitLab runners allow Docker-in-Docker? | platform team | production rollout; the GitLab rig job | The GitHub rig job runs automatically. The GitLab rig job is manual and may fail: a Docker-in-Docker runner cannot mount the checkout into the test container the way `rig/run.sh` does. |
| C-5 | Confirm the Shibboleth SP V4 status. | security / platform team | whether Shibboleth is ever a better SP than mod_auth_mellon | Not used. Shibboleth SP V4 has an Alpha 1 (2026-02-24) and a 4.0.0beta1 (2026-10-07), and neither is a production release. mod_auth_mellon 0.19.1 is used. |
| C-6 | Proxy and signer language. | decided | | Apache httpd + mod_auth_mellon + mod_proxy for the proxy and SP; a small PHP component for the signer, config validation and header policy. **Alternative recorded: a Go signer** (a single static binary replacing the PHP `prg:` process). It would cut the image size and start-up time. It was not chosen because PHP is the shared stack language and the signer is about 100 lines on a maintained JWT library. Revisit if the PHP process shows up in profiling. |
| C-7 | Key distribution and rotation: how do apps get the public key, and how does rotation overlap? | platform team; SESAME owner | rotation without downtime | One signing key, one `kid`. The public half is a file apps copy. Rotation needs the app side to accept two keys at once (SESAME's `gateway` driver takes one key today), so rotation currently means a short coordinated window. |

### Questions that came up while building

| Question | Who answers | Blocks | Default built |
|---|---|---|---|
| Should SESAME's `gateway` driver check `jti` against a replay cache? Assertions live 60 seconds and are minted per request, so replay is bounded but possible. | SESAME owner | replay protection at the app | Nothing. SESAME is read-only for this work; DRAWBRIDGE only puts a fresh `jti` in every token. |
| Should apps be allowed to see identity on public paths (an "identify" mode: signed in visitors get the assertion, anonymous ones pass)? | app owners | public pages that say "Hello, name" | No. Public means no identity, ever. |
| How many gateway instances are needed, and where is the session kept? | platform team | scale-out and restarts | One instance, sessions in process memory. A restart signs everyone out and a second instance needs sticky routing. |
| Is the `__Host-` cookie prefix wanted? | security | cookie hardening | The prefix is configurable, but mod_auth_mellon prepends `mellon-` to the cookie name, so browsers do not apply the `__Host-` rules. The cookie is still Secure (when `DRAWBRIDGE_COOKIE_SECURE` is true) and SameSite=Lax. |
| What should happen to a request that carries a header under an owned prefix that is not on the generated list? | security | closing the one known gap | It is forwarded. Apache's header table cannot be listed from Lua, so the guard removes explicit names only (owned names, plus each owned prefix joined to common identity words). Apps must read only the documented headers. |

## Missing configuration

Nothing here is in git. Each item is a file or value an operator supplies; the entrypoint refuses to start without
the files.

| Item | Source | Placeholder in this repo |
|---|---|---|
| SP private key (`sp.key`) and certificate (`sp.crt`) | generated by the platform team for the gateway; the certificate goes to the IdP owner with the metadata | none; the rig generates a throwaway pair when the tests run |
| IdP metadata (`idp-metadata.xml`) | the IdP owner (I-5) | the rig builds one from its own SimpleSAMLphp IdP |
| SP entity ID | agreed with the IdP owner (I-6) | `https://auth.apps.wayne.edu/_drawbridge/sso/metadata` in the example config |
| Assertion signing key (`signing.key`) and its `kid` | `keys:generate`, kept by the platform team; the public half goes to every app | rig key generated at test time, `kid` `rig-1` |
| Public signing key for apps | the platform team, to each app's SESAME `gateway` driver configuration | none |
| `DRAWBRIDGE_CONFIG` file | the platform team, one entry per app (host, upstream, path rules) | `config/drawbridge.example.yaml` |
| App hosts and upstream addresses | each app owner | `app1.apps.wayne.edu`, `http://app1:8080` |
| `DRAWBRIDGE_TRUSTED_PROXIES` | the platform team (C-2, C-3) | empty (trust nobody) |
| `DRAWBRIDGE_PUBLIC_SCHEME`, `DRAWBRIDGE_PUBLIC_PORT` | the platform team | `https`, empty |
| TLS certificate for the app hosts | the platform team; terminated in front of the gateway | none (the image speaks HTTP behind the front proxy) |
| Attribute names the IdP releases (I-4) | the IdP owner | `.env.example` and the `attributes` block in the example config |
| `AuthnContextClassRef` values (I-7) | the IdP owner | the default `amr_map` |
| Registry and runner details for building and publishing the image | the platform team (C-4) | none |
| GitHub and GitLab repository settings (badges, mirror) | repository owners | badge URLs in `README.md` are placeholders |

## Not built yet

| Item | Why not now | Planned |
|---|---|---|
| Real SAML single logout through the IdP | needs I-1 | 0.2 |
| Identify-on-public-paths mode | needs a decision (above) | 0.2 |
| IdP-initiated (unsolicited) login | a security trade-off needing the IdP owner's input. The rig tests only that an unsolicited response without a prior request is refused. | 0.2 |
| Session revocation (end a user's sessions from outside) | mod_auth_mellon keeps sessions in process memory; needs a shared store | 0.2 |
| Shared session store and more than one instance | see the scale-out question | 0.2 |
| Two accepted signing keys / rotation overlap on the app side | SESAME is read-only here (C-7) | 0.2 |
| `jti` replay check in SESAME's `gateway` driver | SESAME is read-only here | SESAME release |
| Prometheus metrics (off by default) | no Prometheus server exists yet | 0.2 |
| Close the owned-prefix header gap | needs a different mechanism than mod_lua header enumeration | 0.2 |
| Test the signed token against the real SESAME `GatewayDriver` class | the e2e suite mirrors its checks (`tests/Support/SesameContract.php`) because SESAME is not installable from here; an optional test that runs the real class when `SESAME_PATH` is set was left out | 0.2 |
| XML signature wrapping and second-assertion tests | exercised by mod_auth_mellon's own tests; the rig covers a tampered response only | 0.2 |
| MFA test fixtures (a rig user whose IdP sends an MFA class) | needs I-7 | 0.2 |
| Docker-in-Docker rig job on GitLab | needs C-4; the GitLab job is manual | when C-4 is answered |
| Build and publish the image to a registry | needs C-4 | 0.2 |
| Release tag and GitHub release | tags and releases are not made from this build | when approved |
