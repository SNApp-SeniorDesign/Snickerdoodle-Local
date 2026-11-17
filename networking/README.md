# SNApp Networking Setup

Everything needed to reproduce the networking layer on a new board: WiFi setup page, hotspot, auto-connect, device auth, and the detection watchdog.

Run all commands as root on the board.

---

## 0. Prerequisites

Get the files onto the board:
```bash
git clone --depth 1 https://github.com/SNApp-SeniorDesign/Snickerdoodle-Local.git
cd Snickerdoodle-Local/networking
chmod +x *.sh
which wpa_cli   # confirm the path (usually /usr/sbin/wpa_cli) — update index.php if different
```

---

## 1. WiFi Setup Page (index.php)

Install Apache and PHP (skip if already installed):
```bash
apt update
apt install -y apache2 php libapache2-mod-php
systemctl enable apache2
systemctl start apache2
```

Copy the page:
```bash
cp index.php /var/www/html/index.php
```

Allow Apache to run wpa_cli:
```bash
visudo -f /etc/sudoers.d/www-data
```
Paste this line, save:
```
www-data ALL=(root) NOPASSWD: /usr/sbin/wpa_cli -s /run/wpa_supplicant *
```
Validate:
```bash
visudo -cf /etc/sudoers.d/www-data
```

Create a blank wpa_supplicant.conf (no real networks yet):
```bash

chown root:netdev /etc/wpa_supplicant.conf
chmod 660 /etc/wpa_supplicant.conf
```

---

## 2. Hotspot (wlan1)

```bash
chmod +x /root/start_hotspot.sh
systemctl disable hostapd dnsmasq
systemctl daemon-reload
systemctl enable snapp-hotspot.service
systemctl start snapp-hotspot.service
```

---

## 3. WiFi Auto-Connect (wlan0)

```bash
chmod +x /root/wifi_connect.sh /root/kill_wifi.sh /root/check_wifi.sh
systemctl daemon-reload
systemctl enable wifi-connect.service
reboot
```

---

## 4. Connect to Real WiFi (required before registering)

The board has no saved network yet — `register_device.sh` needs real internet access.

1. On your phone, connect to the board's hotspot (SSID shown in `hostapd.conf`).
2. Open `http://10.0.110.2` in your phone's browser.
3. Use the page to scan and connect to a real WiFi network (home, campus, etc.).
4. Confirm the board is online:
```bash
ping -c 3 8.8.8.8
```

---

## 5. Device Registration

```bash
cp register_device.sh /root/register_device.sh
chmod +x /root/register_device.sh
SNSA_URL=https://<your-backend-url> /root/register_device.sh
```

---

## 6. Event Watchdog

```bash
cp send_event.sh watch_detector.sh /root/
chmod +x /root/send_event.sh /root/watch_detector.sh
./watch_detector.sh
```

---

