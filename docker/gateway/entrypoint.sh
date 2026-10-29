#!/bin/sh
# Start Drawbridge: check the files it cannot run without, render the config, then run Apache.
# Any problem stops the container. A gateway that is half configured must not come up, because "up" means
# "the way into every app", and an open way in is worse than a closed one.
set -eu

: "${DRAWBRIDGE_PORT:=8080}"
: "${DRAWBRIDGE_RUN_DIR:=/tmp/drawbridge}"
: "${DRAWBRIDGE_LOG_LEVEL:=warn}"
: "${DRAWBRIDGE_CONFIG:=/etc/drawbridge/drawbridge.yaml}"
: "${DRAWBRIDGE_SIGNING_KEY_FILE:=/run/drawbridge/signing.key}"
export DRAWBRIDGE_PORT DRAWBRIDGE_RUN_DIR DRAWBRIDGE_LOG_LEVEL DRAWBRIDGE_CONFIG DRAWBRIDGE_SIGNING_KEY_FILE

for file in "$DRAWBRIDGE_CONFIG" "$DRAWBRIDGE_SIGNING_KEY_FILE"; do
    if [ ! -r "$file" ]; then
        echo "drawbridge: cannot read $file. See OPEN_QUESTIONS.md, Missing configuration." >&2
        exit 1
    fi
done

mkdir -p "$DRAWBRIDGE_RUN_DIR/run" "$DRAWBRIDGE_RUN_DIR/generated"

# render also checks the OpenID Connect client secret and crypto passphrase in the environment, and refuses to
# write anything (so Apache never starts) when either is missing or unusable.
php /opt/drawbridge/bin/drawbridge render --output-dir="$DRAWBRIDGE_RUN_DIR/generated"

apache2 -f /etc/drawbridge/httpd.conf -t

exec apache2 -f /etc/drawbridge/httpd.conf -DFOREGROUND
