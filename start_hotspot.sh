# SNApp Hotspot Start Script

pkill hostapd
sleep 2

ip link set wlan1 up
ip addr add 10.0.110.2/24 dev wlan1
systemctl start dnsmasq
hostapd /etc/hostapd/hostapd.conf
echo "Hotspot started! Connect to SNApp-Device"
