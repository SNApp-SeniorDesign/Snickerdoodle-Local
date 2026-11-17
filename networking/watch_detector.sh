#!/bin/bash
set -uo pipefail

FLAG_GPIO=0x41200000
CLEAR_GPIO=0x41210000
POLL_INTERVAL=0.5
SEND_EVENT="$(dirname "$0")/send_event.sh"

read_flag() {
    devmem2 "$FLAG_GPIO" w | awk '/Value at address/ { print $NF }'
}

clear_flag() {
    devmem2 "$CLEAR_GPIO" w 1 >/dev/null
    sleep 0.2
    devmem2 "$CLEAR_GPIO" w 0 >/dev/null
}

echo "Watching detector flag at $FLAG_GPIO... (Ctrl+C to stop)"

while true; do
    VAL_HEX=$(read_flag)
    VAL=$((VAL_HEX))

    if (( VAL & 1 )); then
        echo "$(date '+%H:%M:%S') DETECTED — sending event"
        bash "$SEND_EVENT" || echo "send_event failed, continuing watch..."
        clear_flag
    fi

    sleep "$POLL_INTERVAL"
done