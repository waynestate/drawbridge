# DRAWBRIDGE gateway image: Apache httpd + mod_auth_openidc (the OpenID Connect relying party) + mod_proxy + mod_lua,
# plus the small PHP component that signs the assertion. Build from the repository root:
#   docker build -f docker/gateway/Dockerfile -t drawbridge .
# The optional --build-arg DRAWBRIDGE_SOURCE_URL / DRAWBRIDGE_REVISION / DRAWBRIDGE_VERSION fill the OCI labels;
# the pipeline passes them, a local build may omit them.

# Base images are fully qualified (docker.io/library/...) because buildah enforces short-name resolution and
# cannot prompt without a TTY, which is exactly the situation in a CI job. Docker resolves the short names to
# the same references, so `docker build` behaves identically.
FROM docker.io/library/composer:2.10.3 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader
COPY src ./src
COPY bin ./bin
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

FROM docker.io/library/debian:trixie-slim

# OCI labels. The pipeline passes the build args ($CI_PROJECT_URL, $CI_COMMIT_SHA, $CI_COMMIT_TAG); a local build
# without them gets empty labels rather than a failure.
ARG DRAWBRIDGE_SOURCE_URL=""
ARG DRAWBRIDGE_REVISION=""
ARG DRAWBRIDGE_VERSION=""
LABEL org.opencontainers.image.source="$DRAWBRIDGE_SOURCE_URL" \
    org.opencontainers.image.revision="$DRAWBRIDGE_REVISION" \
    org.opencontainers.image.version="$DRAWBRIDGE_VERSION" \
    org.opencontainers.image.licenses="MIT"

# apache2 2.4.x and libapache2-mod-auth-openidc 2.4.x come from Debian 13 (trixie) and are patched by Debian's security
# team. Rebuild the image regularly to pick those patches up.
RUN apt-get update \
    && apt-get install -y --no-install-recommends apache2 libapache2-mod-auth-openidc php-cli ca-certificates curl \
    && rm -rf /var/lib/apt/lists/* \
    && rm -rf /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled

COPY --from=vendor /app /opt/drawbridge
COPY docker/gateway/httpd.conf /etc/drawbridge/httpd.conf
COPY docker/gateway/drawbridge.lua /etc/drawbridge/drawbridge.lua
COPY docker/gateway/entrypoint.sh /usr/local/bin/drawbridge-entrypoint
RUN chmod 0755 /usr/local/bin/drawbridge-entrypoint /opt/drawbridge/bin/drawbridge \
    && mkdir -p /tmp/drawbridge /var/empty \
    && chgrp -R 0 /tmp/drawbridge && chmod -R g=u /tmp/drawbridge

# Any non-root UID works (OpenShift assigns one): state lives under /tmp/drawbridge, group 0 can write it.
USER 1001:0
ENV DRAWBRIDGE_PORT=8080 DRAWBRIDGE_RUN_DIR=/tmp/drawbridge
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD curl -fsS "http://127.0.0.1:${DRAWBRIDGE_PORT}/_drawbridge/up" || exit 1
ENTRYPOINT ["/usr/local/bin/drawbridge-entrypoint"]
