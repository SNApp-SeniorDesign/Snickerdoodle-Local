# wifi_connect.sh: This script connects to the WiFi router in 
# /etc/wpa_supplicant.conf

echo "Connecting to WiFi..."
ip link set wlan0 up
wpa_supplicant -B -i wlan0 -c /etc/wpa_supplicant.conf
iw dev wlan0 link
dhclient -v wlan0
echo "wifi_connect.sh script has completed."