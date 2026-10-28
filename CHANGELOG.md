# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
