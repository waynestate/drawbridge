#!/bin/sh
# Start DRAWBRIDGE: check the files it cannot run without, render the config, then run Apache.
# Any problem stops the container. A gateway that is half configured must not come up, because "up" means
# "the way into every app", and an open way in is worse than a closed one.
set -eu

: "${DRAWBRIDGE_PORT:=8080}"
: "${DRAWBRIDGE_RUN_DIR:=/tmp/drawbridge}"
: "${DRAWBRIDGE_LOG_LEVEL:=warn}"
: "${DRAWBRIDGE_CONFIG:=/etc/drawbridge/drawbridge.yaml}"
: "${DRAWBRIDGE_IDP_METADATA_FILE:=/run/drawbridge/idp-metadata.xml}"
: "${DRAWBRIDGE_SP_KEY_FILE:=/run/drawbridge/sp.key}"
: "${DRAWBRIDGE_SP_CERT_FILE:=/run/drawbridge/sp.crt}"
: "${DRAWBRIDGE_SIGNING_KEY_FILE:=/run/drawbridge/signing.key}"
export DRAWBRIDGE_PORT DRAWBRIDGE_RUN_DIR DRAWBRIDGE_LOG_LEVEL DRAWBRIDGE_CONFIG
export DRAWBRIDGE_IDP_METADATA_FILE DRAWBRIDGE_SP_KEY_FILE DRAWBRIDGE_SP_CERT_FILE DRAWBRIDGE_SIGNING_KEY_FILE

for file in "$DRAWBRIDGE_CONFIG" "$DRAWBRIDGE_IDP_METADATA_FILE" "$DRAWBRIDGE_SP_KEY_FILE" "$DRAWBRIDGE_SP_CERT_FILE" "$DRAWBRIDGE_SIGNING_KEY_FILE"; do
    if [ ! -r "$file" ]; then
        echo "drawbridge: cannot read $file. See OPEN_QUESTIONS.md, Missing configuration." >&2
        exit 1
    fi
done

mkdir -p "$DRAWBRIDGE_RUN_DIR/run" "$DRAWBRIDGE_RUN_DIR/generated"

php /opt/drawbridge/bin/drawbridge render --output-dir="$DRAWBRIDGE_RUN_DIR/generated"

apache2 -f /etc/drawbridge/httpd.conf -t

exec apache2 -f /etc/drawbridge/httpd.conf -DFOREGROUND
