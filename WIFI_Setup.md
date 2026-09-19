# SNApp — Snickerdoodle WiFi Auto-Connect Setup

---

## Create the WiFi Connection Script
```bash
nano /root/connect_wifi.sh
```
Paste this inside:
```bash
#!/bin/bash
sleep 5
ip link set wlan0 up
wpa_supplicant -B -i wlan0 -c /etc/wpa_supplicant.conf
sleep 5
dhclient -v wlan0
echo "Wifi Connected"
```

```bash
chmod +x /root/connect_wifi.sh
```

---

## Create the Systemd Service File
```bash
nano /etc/systemd/system/wifi-connect.service
```
Paste this inside:
```
[Unit]
Description=SNApp WiFi Auto Connect
After=sys-subsystem-net-devices-wlan0.device

[Service]
Type=oneshot
ExecStart=/root/connect_wifi.sh
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
```

---

## Enable and Start the Service
```bash
systemctl enable wifi-connect.service
systemctl start wifi-connect.service
```

---

## Verify
```bash
systemctl status wifi-connect.service
ping -c 4 8.8.8.8
```
