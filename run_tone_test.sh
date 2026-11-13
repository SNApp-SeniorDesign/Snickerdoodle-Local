#!/bin/bash

set -euo pipefail

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: run this script as root."
    exit 1
fi

if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <pcm_file>"
    echo "Example: $0 tone_4k_48k.pcm"
    exit 1
fi

PCM_FILE="$1"

if [ ! -f "$PCM_FILE" ]; then
    echo "ERROR: PCM file not found: $PCM_FILE"
    exit 1
fi

DDR_ADDR=0x18000000
TRANSFER_LENGTH=96000

DMA_CONTROL=0x40400000
DMA_STATUS=0x40400004
DMA_SOURCE=0x40400018
DMA_LENGTH=0x40400028

FLAG_GPIO=0x41200000
CLEAR_GPIO=0x41210000

echo "=== AXI DMA PCM detector test ==="
echo
echo "PCM file: $PCM_FILE"
echo "DDR address: $DDR_ADDR"
echo "Transfer length: $TRANSFER_LENGTH bytes"

echo
echo "[1/7] Copying PCM file into reserved DDR..."
dd if="$PCM_FILE" \
   of=/dev/mem \
   bs=1M \
   seek=384 \
   conv=notrunc
sync
echo "PCM file copied."

echo
echo "[2/7] Clearing sticky detector flag..."
devmem2 "$CLEAR_GPIO" w 0
devmem2 "$CLEAR_GPIO" w 1
devmem2 "$CLEAR_GPIO" w 0

echo
echo "[3/7] Verifying detector flag is clear..."
CLEAR_OUTPUT=$(devmem2 "$FLAG_GPIO" w)
echo "$CLEAR_OUTPUT"

CLEAR_HEX=$(echo "$CLEAR_OUTPUT" | awk '/Value at address/ {print $NF}')
CLEAR_VALUE=$((CLEAR_HEX))

if (( CLEAR_VALUE & 1 )); then
    echo "ERROR: detector flag did not clear."
    exit 1
fi

echo "Detector flag is clear."

echo
echo "[4/7] Reading initial DMA status..."
devmem2 "$DMA_STATUS" w

echo
echo "[5/7] Enabling DMA..."
devmem2 "$DMA_CONTROL" w 1

echo
echo "[6/7] Configuring and starting DMA..."
devmem2 "$DMA_SOURCE" w "$DDR_ADDR"
devmem2 "$DMA_LENGTH" w "$TRANSFER_LENGTH"

echo
echo "Waiting for transfer completion..."
sleep 1

echo
echo "[7/7] Reading final DMA status and detector flag..."

STATUS_OUTPUT=$(devmem2 "$DMA_STATUS" w)
echo "$STATUS_OUTPUT"

FLAG_OUTPUT=$(devmem2 "$FLAG_GPIO" w)
echo "$FLAG_OUTPUT"

STATUS_HEX=$(echo "$STATUS_OUTPUT" | awk '/Value at address/ {print $NF}')
FLAG_HEX=$(echo "$FLAG_OUTPUT" | awk '/Value at address/ {print $NF}')

STATUS=$((STATUS_HEX))
FLAG=$((FLAG_HEX))

echo
echo "=== Result ==="

if (( STATUS & 0x70 )); then
    echo "DMA ERROR: status = $STATUS_HEX"
    exit 1
fi

if (( FLAG & 1 )); then
    echo "DETECTED: detector flag bit 0 = 1"
else
    echo "NOT DETECTED: detector flag bit 0 = 0"
fi