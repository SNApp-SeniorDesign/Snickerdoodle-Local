# Kill wifi connection
systemctl stop wpa_supplicant
killall wpa_supplicant
rm -f /var/run/wpa_supplicant/wlan0