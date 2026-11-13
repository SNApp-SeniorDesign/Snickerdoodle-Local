# reads power value (split into 2 gpios bc bigger than 32'b max)
LOW=$(devmem2 0x41220000 w | awk '/Value at address/ {print $NF}')
HIGH=$(devmem2 0x41230000 w | awk '/Value at address/ {print $NF}')

POWER=$(( (HIGH << 32) | LOW ))
printf 'Power = %llu\n' "$POWER"