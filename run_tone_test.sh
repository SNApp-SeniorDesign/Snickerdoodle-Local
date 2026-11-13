#!/bin/bash

set -e

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: run this script as root."
    exit 1
fi

if [ ! -f "$1" ]; then
    echo "ERROR: file not found: $1"
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

echo
echo "=== AXI DMA PCM detector test ==="
echo "PCM file: $1"
echo "DDR address: $DDR_ADDR"
echo "Transfer length: $TRANSFER_LENGTH bytes"

echo
echo "[1/6] Copying PCM file into reserved DDR..."
dd if="$1" \
   of=/dev/mem \
   bs=1M \
   seek=384 \
   conv=notrunc
sync
echo "PCM file copied."

echo
echo "[2/6] Clearing sticky detector flag..."
devmem2 "$CLEAR_GPIO" w 0
devmem2 "$CLEAR_GPIO" w 1
devmem2 "$CLEAR_GPIO" w 0

echo
echo "[3/6] Reading initial DMA status..."
devmem2 "$DMA_STATUS" w

echo
echo "[4/6] Enabling DMA..."
devmem2 "$DMA_CONTROL" w 1

echo
echo "[5/6] Configuring and starting DMA..."
devmem2 "$DMA_SOURCE" w "$DDR_ADDR"
devmem2 "$DMA_LENGTH" w "$TRANSFER_LENGTH"

echo
echo "Waiting for transfer completion..."
sleep 1

echo
echo "[6/6] Reading final status and detector flag..."

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