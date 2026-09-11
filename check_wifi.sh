# check_wifi.sh: checks for valid WiFi connection
echo "Checking IP Address"
ip -4 address show wlan0
echo "Scanning local WiFi Networks"
iw dev wlan0 scan | grep SSID
echo "Pinging google.com"
ping -c 4 google.com