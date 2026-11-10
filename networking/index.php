<?php
// SNApp Network Setup page. Lives in /var/www/html/index.php

$file    = "/etc/wpa_supplicant.conf";
$backup  = "/tmp/snapp_wpa_backup.conf";      // copy of the .conf made before each "add"
$wpacli  = "/usr/sbin/wpa_cli";                    // confirm on the board with: which wpa_cli
$restart = "sudo /bin/systemctl restart --no-block wifi-connect.service 2>&1";
$ownAP   = "SNApp-Device";                     // the board's own hotspot, hidden from the list

// <functions>

// Runs a fixed wpa_cli command on wlan0 (no user input ever goes in here).
function wpa($args) {
    global $wpacli;
    return shell_exec("sudo " . $wpacli . " -s /run/wpa_supplicant -i wlan0 " . $args . " 2>&1") ?? "";
}

// Turns the output of "wpa_cli scan_results" into a clean list of networks.
function parse_scan($text, $ownAP = "") {
    $best = [];
    foreach (explode("\n", $text) as $line) {
        $f = explode("\t", $line);
        if (count($f) < 5) continue;                  // header line and errors have no tabs
        $signal = (int) $f[2];
        $flags  = $f[3];
        $ssid   = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', function ($m) {
            return chr(hexdec($m[1]));
        }, $f[4]);
        if ($ssid === "" || $ssid === $ownAP) continue;          // hidden network or our own hotspot
        if (preg_match('/[\x00-\x1f]/', $ssid)) continue;       // hidden networks show up as \x00 characters
        if (!preg_match('//u', $ssid)) continue;
        if (strpos($flags, 'EAP') !== false) continue;           // school/enterprise (eduroam): not supported here
        $open = (strpos($flags, 'WPA') === false && strpos($flags, 'WEP') === false);
        if (!isset($best[$ssid]) || $signal > $best[$ssid]['signal']) {
            $best[$ssid] = ['ssid' => $ssid, 'signal' => $signal, 'open' => $open];
        }
    }
    $list = array_values($best);
    usort($list, function ($a, $b) { return $b['signal'] - $a['signal']; });
    return $list;
}

// Turns "key=value" lines from "wpa_cli status" into an array.
function parse_status($text) {
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $p = strpos($line, '=');
        if ($p === false) continue;
        $out[substr($line, 0, $p)] = trim(substr($line, $p + 1));
    }
    return $out;
}

// Removes any saved network block for this SSID from the .conf text.
function remove_ssid($conf, $ssid) {
    $hex = bin2hex($ssid);
    if (preg_match_all('/^[ \t]*network=\{.*?^[ \t]*\}[ \t]*\n?/ms', $conf, $m, PREG_OFFSET_CAPTURE)) {
        foreach (array_reverse($m[0]) as $hit) {
            $block = $hit[0];
            $match = preg_match('/^[ \t]*ssid=' . $hex . '[ \t]*$/mi', $block)
                  || preg_match('/^[ \t]*ssid="' . preg_quote($ssid, '/') . '"[ \t]*$/m', $block);
            if ($match) {
                $conf = substr($conf, 0, $hit[1]) . substr($conf, $hit[1] + strlen($block));
            }
        }
    }
    return $conf;
}

// </functions>

function send_json($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// ---------- Requests from the page's JavaScript ----------

// Scan for nearby networks (takes about 3 seconds)
if (isset($_GET['scan'])) {
    wpa("scan");
    sleep(3);
    send_json(['nets' => parse_scan(wpa("scan_results"), $ownAP)]);
}

// Is the board connected to this network yet?
if (isset($_GET['status'])) {
    $st   = parse_status(wpa("status"));
    $want = $_GET['ssid'] ?? '';
    $ok   = ($st['wpa_state'] ?? '') === 'COMPLETED'
         && ($st['ssid'] ?? '') === $want
         && !empty($st['ip_address']);
    send_json(['connected' => $ok, 'state' => $st['wpa_state'] ?? 'STARTING']);
}

// ---------- Form posts ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save a new network and try to connect to it
    if ($action === 'add') {
        $choice = $_POST['ssid_select'] ?? '';
        $ssid   = trim(($choice === '' || $choice === '__other__') ? ($_POST['ssid_manual'] ?? '') : $choice);
        $pass   = $_POST['password'] ?? '';

        if ($ssid === '' || strlen($ssid) > 32
            || ($pass !== '' && (strlen($pass) < 8 || strlen($pass) > 63))
            || preg_match('/[\x00-\x1f]/', $ssid . $pass)) {
            header("Location: ?error=1"); exit;
        }

        $conf = str_replace("\r", "", (string) @file_get_contents($file));
        @file_put_contents($backup, $conf);          // so we can undo if the connection fails
        $conf = remove_ssid($conf, $ssid);           // replace an older entry with the same name

        $block = "network={\n    ssid=" . bin2hex($ssid) . "\n";
        $block .= $pass === ''
            ? "    key_mgmt=NONE\n"
            : "    psk=" . hash_pbkdf2('sha1', $pass, $ssid, 4096, 64) . "\n    key_mgmt=WPA-PSK\n";
        $block .= "}\n";

        if (@file_put_contents($file, rtrim($conf) . "\n\n" . $block, LOCK_EX) === false) {
            header("Location: ?error=2"); exit;
        }
        shell_exec($restart);
        header("Location: ?connecting=" . urlencode($ssid)); exit;
    }

    // The connection failed: put the .conf back the way it was and reconnect
    if ($action === 'revert') {
        if (is_file($backup)) {
            @file_put_contents($file, (string) file_get_contents($backup), LOCK_EX);
            @unlink($backup);
            shell_exec($restart);
        }
        send_json(['ok' => true]);
    }
}

$connecting = $_GET['connecting'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>SNApp Network Setup</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --blue: #08b5ed;
            --white: #f8fdff;
            --text: #1a1d21;
            --muted: #6b7785;
            --border: #dfe5ec;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--blue);
            color: var(--text);
            font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 16px;
            flex-direction: column;
        }

        .card {
            width: 100%;
            max-width: 420px;
            background: rgba(248, 253, 255, 0.18);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        h1 { font-size: 24px; margin: 0 0 6px; }
        .sub { color: rgba(255, 255, 255, 0.9); font-size: 15px; margin: 0 0 18px; line-height: 1.4; }
        .box {
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 8px 18px 20px;
        }
        label { display: block; font-size: 15px; font-weight: 600; margin: 16px 0 8px; }
        input[type=text], input[type=password], select {
            width: 100%;
            padding: 12px 14px;
            font-size: 16px;
            color: var(--text);
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        input:focus, select:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(8, 181, 237, 0.25); }
        input::placeholder { color: rgba(26, 29, 33, 0.35); }
        .show { display: flex; align-items: center; gap: 8px; font-weight: 400; font-size: 14px; color: var(--muted); }
        button {
            width: 100%;
            margin-top: 18px;
            padding: 13px;
            font-size: 16px;
            font-weight: 700;
            color: var(--white);
            background: var(--blue);
            border: 2px solid var(--white);
            border-radius: 12px;
        }
        button:disabled { opacity: 0.6; }
        button.ghost { width: auto; margin: 8px 0 0; padding: 6px 12px; font-size: 13px; font-weight: 600; background: transparent; }
        .hidden { display: none; }

        .toast {
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            width: max-content;
            max-width: calc(100% - 32px);
            background: var(--white);
            color: var(--text);
            padding: 12px 18px;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
            font-size: 14px;
            animation: toast 5s forwards;
        }
        .toast.persist { animation: none; }
        .toast.ok    { border-left: 5px solid #2fb36b; }
        .toast.error { border-left: 5px solid #e5484d; }
        @keyframes toast {
            0%   { opacity: 0; top: 0; }
            10%  { opacity: 1; top: 16px; }
            85%  { opacity: 1; }
            100% { opacity: 0; visibility: hidden; }
        }
    </style>
</head>
<body>

<?php if (isset($_GET['error'])): ?>
    <div class="toast error">
        <?= $_GET['error'] === '2' ? "Couldn't save the network. Please try again." : "Invalid name or password (8 to 63 characters)." ?>
    </div>
<?php endif; ?>
<?php if ($connecting !== ''): ?>
    <div class="toast persist" id="status">Connecting to <?= htmlspecialchars($connecting, ENT_QUOTES) ?>...</div>
<?php endif; ?>

    <div class="card">
        <h1>Network Setup</h1>
        <p class="sub">Choose your WiFi network and enter its password to connect your device.</p>
        <div class="box">
            <form method="post" id="form">
                <input type="hidden" name="action" value="add">

                <label for="ssid_select">Network</label>
                <select id="ssid_select" name="ssid_select">
                    <option value="" disabled selected>Searching for networks...</option>
                </select>
                <button type="button" class="ghost" id="refresh">Refresh list</button>

                <div id="manual_wrap" class="hidden">
                    <label for="ssid_manual">Network name</label>
                    <input type="text" id="ssid_manual" name="ssid_manual" maxlength="32" placeholder="Network name" autocapitalize="none" autocorrect="off">
                </div>

                <div id="pass_wrap">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Password" autocapitalize="none" autocorrect="off">
                    <label class="show"><input type="checkbox" id="showpw"> Show password</label>
                </div>

                <button type="submit" id="submit">Connect</button>
            </form>
        </div>
    </div>

<script>
var sel      = document.getElementById('ssid_select');
var manual   = document.getElementById('manual_wrap');
var passWrap = document.getElementById('pass_wrap');
var pass     = document.getElementById('password');
var submitB  = document.getElementById('submit');
var refreshB = document.getElementById('refresh');

document.getElementById('showpw').onclick = function () {
    pass.type = this.checked ? 'text' : 'password';
};

// Show the name box only for "Other", hide the password box for open networks
function updateFields() {
    var opt = sel.options[sel.selectedIndex];
    manual.classList.toggle('hidden', sel.value !== '__other__');
    passWrap.classList.toggle('hidden', !!(opt && opt.dataset.open === '1'));
}
sel.onchange = updateFields;

function addOption(value, text, open) {
    var o = document.createElement('option');
    o.value = value; o.textContent = text;
    if (open) o.dataset.open = '1';
    sel.appendChild(o);
}

function scan() {
    sel.innerHTML = '';
    addOption('', 'Searching for networks...');
    sel.options[0].disabled = true;
    refreshB.disabled = true;
    fetch('?scan=1')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            sel.innerHTML = '';
            if (d.nets.length) {
                addOption('', 'Select your network');
                sel.options[0].disabled = true;
                d.nets.forEach(function (n) {
                    addOption(n.ssid, n.ssid + (n.open ? ' (open)' : ''), n.open);
                });
            } else {
                addOption('', 'No networks found');
                sel.options[0].disabled = true;
            }
            addOption('__other__', 'Other (type the name)...');
            sel.selectedIndex = d.nets.length ? 0 : 1;
            if (!d.nets.length) { sel.value = '__other__'; }
            updateFields();
        })
        .catch(function () {
            sel.innerHTML = '';
            addOption('__other__', 'Other (type the name)...');
            sel.value = '__other__';
            updateFields();
        })
        .then(function () { refreshB.disabled = false; });
}
refreshB.onclick = scan;

// Make sure a network was chosen before sending
document.getElementById('form').onsubmit = function (e) {
    if (sel.value === '') { e.preventDefault(); sel.focus(); }
};

function showToast(text, kind, ms) {
    var old = document.getElementById('status');
    if (old) old.remove();
    var t = document.createElement('div');
    t.className = 'toast persist ' + kind;
    t.id = 'status';
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, ms);
}

// After pressing Connect: wait for the board to join the network
var target = <?= json_encode($connecting, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
if (target) {
    submitB.disabled = true;
    var tries = 0, maxTries = 22;          // about 45 seconds
    var timer = setInterval(function () {
        tries++;
        fetch('?status=1&ssid=' + encodeURIComponent(target))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.connected) {
                    clearInterval(timer);
                    history.replaceState(null, '', location.pathname);
                    showToast('Connected to ' + target + '.', 'ok', 8000);
                    submitB.disabled = false;
                } else if (tries >= maxTries) {
                    clearInterval(timer);
                    history.replaceState(null, '', location.pathname);
                    var f = new FormData(); f.append('action', 'revert');
                    fetch('index.php', { method: 'POST', body: f }).catch(function () {});
                    showToast("It didn't connect to " + target + ". Check the password and try again.", 'error', 10000);
                    submitB.disabled = false;
                }
            })
            .catch(function () {});
    }, 2000);
}

scan();
</script>
</body>
</html>
