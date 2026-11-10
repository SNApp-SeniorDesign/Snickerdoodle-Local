# SNApp WiFi Setup Page — install from zero

This sets up the board so a phone can join its hotspot, pick a WiFi network from a
scanned list, enter the password, and have the board connect — with a live
"Connected" / "didn't connect" result on the page.

Do all of this as **root** on the board (Snickerdoodle, Ubuntu 20.04).

---

## 0. Before you start

Pull `index.php` from the repo (or use the one attached to this README) and have it
somewhere you can copy from, e.g. your home folder on the board.

Check where `wpa_cli` actually lives on this board:
```bash
which wpa_cli
```
It's usually `/usr/sbin/wpa_cli`. **Remember this path — you need it in step 3 and
step 4.**

---

## 1. Copy the page

```bash
cp index.php /var/www/html/index.php
php -l /var/www/html/index.php      # must say "No syntax errors detected"
```

---

## 2. Set the `wpa_cli` path in the page

Open the file and check near the top:
```bash
grep -n 'wpacli' /var/www/html/index.php
```
It should say:
```php
$wpacli  = "/usr/sbin/wpa_cli";
```
If `which wpa_cli` in step 0 gave a different path, edit this line to match
(`nano -c /var/www/html/index.php`, save, then `php -l` again).

---

## 3. Give the web server permission to run 4 exact commands

This is the only thing `www-data` (the web server user) is allowed to do as root.
**The `-s /run/wpa_supplicant` part is required** — without it, Apache's isolated
`/tmp` folder stops `wpa_cli` from getting a reply, and every scan/status check
hangs for ~10 seconds and times out. (Use the same path you found in step 0 in
place of `/usr/sbin/wpa_cli` if it's different.)

```bash
cat > /root/www-data.sudoers <<'EOF'
www-data ALL=(root) NOPASSWD: /bin/systemctl restart --no-block wifi-connect.service
www-data ALL=(root) NOPASSWD: /usr/sbin/wpa_cli -s /run/wpa_supplicant -i wlan0 scan
www-data ALL=(root) NOPASSWD: /usr/sbin/wpa_cli -s /run/wpa_supplicant -i wlan0 scan_results
www-data ALL=(root) NOPASSWD: /usr/sbin/wpa_cli -s /run/wpa_supplicant -i wlan0 status
EOF

visudo -cf /root/www-data.sudoers      # must say "parsed OK" before continuing

cp /root/www-data.sudoers /etc/sudoers.d/www-data
chmod 440 /etc/sudoers.d/www-data
chown root:root /etc/sudoers.d/www-data
visudo -c                               # must say "parsed OK" for every file listed
```

---

## 4. Let the web page read/write the saved-networks file

```bash
chown root:www-data /etc/wpa_supplicant.conf
chmod 660 /etc/wpa_supplicant.conf
```

---

## 5. Make sure the reconnect script cleans up before restarting

Find the script `wifi-connect.service` actually runs:
```bash
systemctl cat wifi-connect.service | grep ExecStart
```
Open that script (e.g. `/root/wifi_connect.sh` or `/root/connect_wifi.sh`) and check
it has these near the top, **before** it starts `wpa_supplicant`:
```bash
killall wpa_supplicant dhclient 2>/dev/null
rm -f /var/run/wpa_supplicant/wlan0
```
If missing, add them. Without this, a restart from the page can fail with
`ctrl_iface exists` and the board won't pick up the new network.

---

## 6. Hotspot and WiFi client both start on boot

```bash
systemctl disable hostapd dnsmasq       # our scripts start these themselves
systemctl daemon-reload
systemctl enable wifi-connect.service snapp-hotspot.service
```

If `snapp-hotspot.service` doesn't exist yet on this board, create it (it just runs
`start_hotspot.sh` — don't run that script by hand once this service exists, since
the script's `pkill hostapd` would kill the service's own hostapd):
```bash
cat > /etc/systemd/system/snapp-hotspot.service <<'EOF'
[Unit]
Description=SNApp hotspot (wlan1)
After=network.target

[Service]
Type=simple
ExecStart=/bin/bash /root/start_hotspot.sh
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable snapp-hotspot.service
```

---

## 7. Reboot and test

```bash
reboot
```

After ~1 minute:
1. Join the **SNApp-Device** WiFi network on your phone.
2. Open `http://10.0.110.2`.
3. A dropdown of nearby networks should fill in within ~3 seconds.
4. Pick your network, enter the password, press **Connect**.
5. The page should show "Connecting...", then "Connected to ...".

---

## Troubleshooting

| Symptom | Check |
|---|---|
| Network list stays empty / page hangs | `curl -s -m 15 "http://127.0.0.1/?scan=1"` should answer in ~3s. If it times out, re-check step 3 (the `-s /run/wpa_supplicant` part especially) |
| "Connecting..." never resolves | `sudo -u www-data sudo -n /usr/sbin/wpa_cli -s /run/wpa_supplicant -i wlan0 status` should answer instantly, not hang |
| Hotspot not visible | `systemctl status snapp-hotspot.service`, `pgrep -a hostapd` — **never run `start_hotspot.sh` by hand while this service is enabled** |
| `visudo -c` or `-cf` reports an error | Fix it before installing — don't copy an unchecked file into `/etc/sudoers.d/` |

## Known limitations

- No login on the setup page — anyone on the hotspot can add/replace a network.
- The hotspot password is a placeholder; change it before any real deployment.
- Wrong passwords are detected by timeout (~45s), not instantly.
- 2.4 GHz networks only; enterprise networks (eduroam) aren't in the scan list.
