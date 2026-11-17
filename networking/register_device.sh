#!/bin/bash 
# SNApp: claim this board's device key from the backend. Run once; safe to run again.
# The device must already be added in the app, using this board's MAC as the serial number.

BASE_URL="${SNSA_URL:-https://smart-notification-system-for-appliances.onrender.com}"

DIR="$(dirname "$(readlink -f "$0")")"
KEY_FILE="${KEY_FILE:-$DIR/device_key}"
MAC="${MAC_OVERRIDE:-$(tr 'A-Z' 'a-z' < /sys/class/net/wlan0/address)}"

command -v curl >/dev/null || { echo "curl is not installed (apt-get install -y curl)"; exit 1; }

if [ -s "$KEY_FILE" ]; then
    echo "This board already has a key ($KEY_FILE). Nothing to do."
    exit 0
fi

echo "Claiming a key for $MAC ..."
for attempt in 1 2 3 4 5; do
    RESP=$(mktemp)
    # First request after the backend sleeps can take 30-60 s (Render free tier)
    CODE=$(curl -sS -m 90 -o "$RESP" -w "%{http_code}" -X POST "$BASE_URL/device/claim" \
        -H "Content-Type: application/json" \
        -d "{\"serial_number\":\"$MAC\"}")
    case "$CODE" in
        201)
            KEY=$(sed -n 's/.*"device_key":"\([^"]*\)".*/\1/p' "$RESP")
            rm -f "$RESP"
            if [ -z "$KEY" ]; then echo "Got 201 but no key in the reply"; exit 1; fi
            umask 077
            printf '%s' "$KEY" > "$KEY_FILE"
            chmod 600 "$KEY_FILE"
            echo "Key saved to $KEY_FILE"
            exit 0 ;;
        404)
            rm -f "$RESP"
            echo "Backend doesn't know this device. Add it in the app with serial number: $MAC"
            exit 2 ;;
        409)
            rm -f "$RESP"
            echo "A key was already claimed for this device. The owner must reset it in the backend."
            exit 3 ;;
        *)
            echo "Attempt $attempt failed (HTTP $CODE). Retrying in 10 s ..."
            rm -f "$RESP"
            sleep 10 ;;
    esac
done
echo "Could not reach the backend. Check the WiFi connection and try again."
exit 4
