#!/bin/bash
set -euo pipefail

if (( EUID != 0 )); then
    echo "Run as root."
    exit 1
fi

if (( $# != 1 )); then
    echo "Usage: $0 <pcm_file>"
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PCM_FILE="$1"
TRANSFER_LENGTH=96000

DDR_ADDR=0x18000000
DMA_CONTROL=0x40400000
DMA_STATUS=0x40400004
DMA_SOURCE=0x40400018
DMA_LENGTH=0x40400028
FLAG_GPIO=0x41200000
CLEAR_GPIO=0x41210000

read_reg() {
    devmem2 "$1" w | awk '/Value at address/ { print $NF }'
}

write_reg() {
    devmem2 "$1" w "$2" >/dev/null
}

if [[ ! -f "$PCM_FILE" ]]; then
    echo "PCM file not found: $PCM_FILE"
    exit 1
fi

if [[ $(stat -c%s "$PCM_FILE") -ne $TRANSFER_LENGTH ]]; then
    echo "PCM file must be exactly $TRANSFER_LENGTH bytes."
    exit 1
fi

SILENCE_FILE="$SCRIPT_DIR/silence_48k.pcm"

if [[ "$PCM_FILE" != "$SILENCE_FILE" ]]; then
    echo "Conditioning detector with silence..."
    bash "$SCRIPT_DIR/run_tone_test.sh" "$SILENCE_FILE" >/dev/null
fi

echo "Loading $PCM_FILE into DDR..."
dd if="$PCM_FILE" of=/dev/mem bs=1M seek=384 conv=notrunc status=none
sync

echo "Clearing detector flag..."
write_reg "$CLEAR_GPIO" 1
sleep 1
write_reg "$CLEAR_GPIO" 0

FLAG_HEX=$(read_reg "$FLAG_GPIO")
FLAG=$((FLAG_HEX))

if (( FLAG & 1 )); then
    echo "ERROR: detector flag is still high before DMA."
    exit 1
fi

echo "Detector flag is low. Starting DMA..."

write_reg "$DMA_CONTROL" 1
write_reg "$DMA_SOURCE" "$DDR_ADDR"
write_reg "$DMA_LENGTH" "$TRANSFER_LENGTH"

sleep 1

STATUS_HEX=$(read_reg "$DMA_STATUS")
FLAG_HEX=$(read_reg "$FLAG_GPIO")
STATUS=$((STATUS_HEX))
FLAG=$((FLAG_HEX))

echo
echo "DMA status: $STATUS_HEX"
echo "Detector flag: $FLAG_HEX"

if (( STATUS & 0x70 )); then
    echo "DMA ERROR"
    exit 1
fi

if (( FLAG & 1 )); then
    echo "DETECTED"
else
    echo "NOT DETECTED"
fi

echo
echo "Reading Goertzel power..."
bash "$SCRIPT_DIR/power_mem_read.sh"