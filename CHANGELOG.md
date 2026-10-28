# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.2.0 (unreleased)

The drawbridge changes its chain: Microsoft Entra ID over OpenID Connect replaces SAML. Same gate, same guards,
same signed assertion for SESAME; only the rope to the identity provider is new.

### Changed

- mod_auth_openidc (Debian package, pinned like the rest of the image) replaces mod_auth_mellon. Authorization code
  flow with PKCE, one client for every app host, the redirect URI at `/_drawbridge/oidc/callback` on each host,
  sessions in an encrypted shared-memory cache with a passphrase per host.
- Config: the `oidc` block (`issuer`, `metadata_url`, `client_id`, `client_secret_env`, `user_claim`,
  `attribute_claims`, `extra_claims`, `amr_map`) replaces `gateway.sp_entity_id`, `gateway.attributes` and
  `gateway.amr_map`. The issuer must be https (http only for a `.test` host). `config:validate` and `explain` print
  the new fields, never the secret.
- The signer reads the validated ID token: `sub` from the user claim (`preferred_username` by default, configurable,
  for example to `alias`), `auth_time`, `amr` (Entra values through the new default map), `acrs`, the attribute
  claims and any `extra_claims` such as `wsuRoles`. An ID token from another issuer is not signed. The signer's input
  line is now `v2`.
- The guard strips `OIDC_CLAIM_*`, `OIDC_access_token` and the module's other token and claim names, and now removes
  every header under an owned prefix whatever follows it (closing the known gap of 0.1).
- `render` refuses to write anything when the client secret or crypto passphrase is missing or unsafe to substitute,
  so Apache never starts with a literal `${...}` for a secret.
- Rig: [mock-oauth2-server](https://github.com/navikt/mock-oauth2-server) 6.1.3 replaces the SimpleSAMLphp IdP.

### Added

- `auth_context` on a path rule (`c1` to `c99`): the gateway asks Entra for the context with the `claims` request
  parameter and requires it in the ID token's `acrs` array, sending a signed-in visitor without it back to step up
  (`api` paths answer 401).
- Rig tests for step-up, forged callbacks and forged `OIDC_*` headers.

### Removed

- `sp:metadata` (`MetadataCommand`, `MetadataRenderer`), the SAML rig IdP, `SamlTest`, and the
  `DRAWBRIDGE_IDP_METADATA_FILE`, `DRAWBRIDGE_SP_KEY_FILE` and `DRAWBRIDGE_SP_CERT_FILE` variables.

## 0.1.0 (never tagged)

### Added

- Apache httpd gateway image: mod_auth_mellon as the one SAML service provider, mod_proxy to each app, mod_lua guard
  that strips identity headers before authentication, non-root, port 8080, state under `/tmp/drawbridge`.
- Per-app config (YAML or PHP array) with exact and prefix path rules, `login`, `api` and `public` protection, and
  `assertion`, `headers` or `both` delivery. `config:validate` reports every problem at once.
- `render` turns the config into Apache config and a Lua policy; `explain` says which rule applies; `sp:metadata`
  prints one entity ID with one ACS per app host; `keys:generate` makes an ES256 key pair.
- ES256 assertion signer (`typ: drawbridge+jwt`, `kid`, `iss`, `aud`, `sub`, `iat`, `nbf`, `exp`, `jti`, `auth_time`,
  `amr`) behind Apache's `prg:` rewrite map. A signing failure gives 503.
- Container rig (`rig/run.sh`, `rig/run.ps1`): the real image, a SimpleSAMLphp test IdP and an echo app, with
  end-to-end tests for login, public, protected and api paths, header spoofing, path tricks, tampered and unsolicited
  SAML, assertion verification (SESAME `gateway` driver rules) and logout.
- `composer ci`: manifest validation, PHPUnit, Pint, PHPStan level 8, `composer audit`. GitLab and GitHub CI.
- `README.md`, `BEST_PRACTICES.md`, `OPEN_QUESTIONS.md`, `.env.example`, MIT licence.
