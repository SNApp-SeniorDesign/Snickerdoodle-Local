cp system.bit /boot/system.bit
sync
echo "this should return a number after reboot: devmem2 0x40400000 w"
echo "run reboot when ready"