# Best practices

The rules DRAWBRIDGE holds itself to. Each one has a test.

1. **Strip first.** Remove every inbound identity header, in every spelling, on every request, before authentication
   runs. Test: `tests/E2E/SpoofingTest.php`.
2. **Fail closed.** No session, no signature, a refused path, a missing key or a bad config means no access. A signer
   that cannot sign gives 503, never an unsigned request. A config error stops the container from starting.
3. **Public is a decision, not a default.** A path with no rule gets `login` unless the app says otherwise. Public
   paths carry no identity at all.
4. **Match the normalised path, never the raw one.** Encoded slashes, backslashes, semicolons, control characters and
   bad UTF-8 are treated as protected. Query strings never choose a rule. Test: `PathAttackTest`, `PathNormalizerTest`.
5. **Exact or prefix, nothing clever.** No wildcards, no regular expressions in config. The Apache pattern is built
   from the literal, never typed by hand.
6. **Sign narrowly and briefly.** ES256, a pinned `typ`, one audience per app, 60 seconds by default, a fresh `jti` on
   every request. The key never leaves the signer.
7. **Let the SP do SAML.** DRAWBRIDGE writes no XML signature code. mod_auth_mellon verifies the response; tampered
   and unsolicited responses are refused. Test: `SamlTest`.
8. **One cookie per app host.** A session on one host opens no other. The gateway cookie is removed before the request
   is proxied.
9. **Overwrite forwarded headers.** `X-Forwarded-*` come from the gateway, never from the client, and `Connection` is
   trimmed to `close`, `keep-alive` and `upgrade`.
10. **Keep secrets out of git and out of logs.** Keys are mounted files. The signer logs a reason code, never a
    subject, an attribute or a token. Test keys are generated when the tests run.
11. **Do not trust the network.** The app verifies the signature even if only the gateway can reach it. Network
    isolation is a second control.
12. **Run small and boring.** Non-root, port above 1024, state under `/tmp`, logs to stdout and stderr, no host paths
    in the image. It runs the same on Citadel, OpenShift, Coolify or plain Docker.
13. **Bounded dependencies.** No `*` and no `dev-master`; `composer audit` is part of `composer ci`.
