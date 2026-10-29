# MAINTAINING: oiling the chains

How to build, test, release and run Drawbridge. Drawbridge is the Citadel auth gateway: one OpenID Connect client to
Microsoft Entra ID (through Apache mod_auth_openidc) in front of apps, which signs a short assertion for SESAME's
`gateway` driver. A drawbridge that sticks keeps everyone out, so the maintenance below is mostly about keys,
secrets and their expiry dates.

## What Drawbridge is and what it depends on

- A container image (`docker/gateway/Dockerfile`) plus a PHP signer and CLI (`bin/drawbridge`). It is not installed
  with Composer by apps and is not on Packagist.
- Runtime dependencies (PHP): `lcobucci/jwt`, `symfony/console`, `symfony/yaml`, `promphp/prometheus_client_php`,
  `ext-openssl`. Apache with mod_auth_openidc in the image.
- **Its consumer is SESAME's `gateway` driver.** The assertion (header, claims, signing algorithm, lifetime) is a
  contract between the two; change both together. Integration tests run against a SESAME checkout when `SESAME_PATH`
  is set.
- Status: built and tested against a local rig with a mock identity provider. Not released or deployed yet.

## Where it lives

Development happens on Wayne State's GitLab. This GitHub repository is a read-only release mirror: `main` and tags are
copied here automatically, and changes, issues and merge requests go to GitLab first. Nobody pushes to GitHub
directly.

## Build and test

| Task | Windows (PowerShell) | Linux and macOS |
|---|---|---|
| Install | `composer install` | `composer install` |
| All checks (what CI runs) | `composer ci` | `composer ci` |
| Validate a config file | `php bin/drawbridge config:validate --config=config/drawbridge.yaml` | same |
| Build the gateway image | `docker build -f docker/gateway/Dockerfile -t drawbridge-gateway .` | same |
| End-to-end rig (mock IdP, gateway, echo app, tests) | `rig\run.ps1` | `sh rig/run.sh` |

The README's "Install and quick start" has the full Docker-only sequence for each OS. `composer ci` runs
`composer validate --strict`, PHPUnit (unit and integration suites), Pint, PHPStan and `composer audit`. The
end-to-end suite (`phpunit.e2e.xml.dist`) runs inside the rig. The rig's keys and passwords are throwaway values made
for the rig only.

## CI

- **GitLab:** Preflight's `php-test` (PHP 8.3, 8.4, 8.5) and `composer-audit` components, a `composer-ci` job,
  `gateway-image:build` (Buildah, on merge requests and `main`), `gateway-image:publish` (on tags only) and a manual
  `rig` job.
- **GitHub:** `.github/workflows/ci.yml` calls Preflight's reusable workflow with `composer ci`, plus the `rig` job.
- Green means `composer ci` passes on all three PHP versions on both, and the rig passes on GitHub.

## What an operator supplies

Settings are described in "Configuration" and "Environment variables" in the README, and in
`config/drawbridge.example.yaml`. In kinds, never values:

- **From the Entra app registration** (single tenant, Web platform, one redirect URI per app host): the tenant's
  issuer, the client identifier, a client secret, and optional claims (`accessid` as the subject, `email`,
  `auth_time`, `acrs`, and `wsuRoles` as a mapped claim).
- **Secrets, as environment variables in the deployment's secret store:** `DRAWBRIDGE_OIDC_CLIENT_SECRET` and
  `DRAWBRIDGE_OIDC_CRYPTO_PASSPHRASE` (32 characters or more).
- **The assertion signing key:** an ES256 key made with `keys:generate`, mounted as a file, with its `kid` from the
  environment. The public half goes to every app's SESAME configuration.
- **Per-app rules:** host, upstream, mode, and which paths are public, protected, step-up or API.
- Keys, PEM files and real config stay out of git (`.gitignore` covers them).

## Expiry and rotation

- **The Entra client secret expires** (the portal caps it at 24 months). An expired secret means nobody can sign in.
  Keep at least two owners on the app registration so the expiry notice reaches more than one inbox. To rotate without
  signing anyone out: add a new secret in Entra, update `DRAWBRIDGE_OIDC_CLIENT_SECRET`, restart the gateway, check a
  sign-in, then delete the old secret.
- **The crypto passphrase:** changing it and restarting signs everyone out (safe, but noisy).
- **The assertion signing key:** SESAME accepts one key at a time today, so rotating it means switching the gateway and
  the apps in one short window.

`OPEN_QUESTIONS.md` lists what is still to be verified against a real tenant and what is not built yet.

## Releasing

1. Move the lines under the top heading of `CHANGELOG.md` (Keep a Changelog) to `## X.Y.Z (YYYY-MM-DD)`. No version
   has been released yet.
2. `composer ci` and the rig green locally, then both pipelines green for that commit.
3. Run a secrets scan of the history and check that nothing internal (server names, people, tenant or client IDs) is
   in the tree.
4. Tag `X.Y.Z` (no `v`) on GitLab. The tag runs `gateway-image:publish`, which pushes the image to the GitLab
   project's container registry. The mirror carries the tag to GitHub.
5. A release needs a maintainer's approval. Running it in front of real apps also needs the Citadel platform team.

## The front page

`site/` is the static front page, for `https://drawbridge.apps.wayne.edu/` on Citadel, served through a small raw-PHP
`deploy/` layer generated with Squire. The gateway itself is a separate deploy.

## Who maintains it

Adam Perkins, Wayne State University Enterprise Applications. Internal runbook (accounts, the app registration,
secret storage, platform contacts): ask Enterprise Applications.
