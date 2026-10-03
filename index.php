<?php
$file   = "wpa_supplicant.conf";
$wifi_script = "wifi_connect_setup.sh";

//This is only called if the form posts new data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
      $newssidinfo = trim($_POST['newssidinfo'] ?? 'oops, nothing loaded');
      $newssidinfo = str_replace("\r", "", $newssidinfo); //strip Win newlines
      file_put_contents($file, $newssidinfo, LOCK_EX);
      //$myoutput = shell_exec("bash " . escapeshellarg($wifi_script) . " 2>&1");
      //myoutput = shell_exec("bash " . $wifi_script);
      echo "wifi_connect_setup.sh returned <br/><pre>" . htmlspecialchars($myoutput) . "</pre>";
    }
}

$filecontents = file_get_contents($file);
?>

<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Players</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: darkGray; margin: 40px; }
        form { margin-bottom: 30px; }
        button { padding: 16px 20px; }
        label { display: inline-block; margin-right: 5px; }
    </style>
</head>
<body>
    <h1>Edit SSID file <?=$file?></h1>

    <!-- Add/update player form -->
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="save" >
        <textarea id="newssidinfo" name="newssidinfo" rows="12" cols="50"><?= htmlspecialchars($filecontents)?></textar>

<!--  For the future, may want more surgical update of individual strings

        <label for="username">Username</label>
        <input type="text" id="username" name="username" required >

        <label for="password">password</label>
        <input type="number" id="password" name="password" min="0" max="150" required >
-->

        <p>
          <button type="submit">Save</button>
        </p>
    </form>
    <br><br>

<?php
echo "filemtime: "    .filemtime($file)."<br/>"; //get last mod time
echo "fileperms: "    .fileperms($file)."<br/>"; //get permissions
echo "filesize: "     .filesize($file)."<br/>";  //get size
echo "File checksum: ".crc32(file_get_contents($file))."<br/>";
?>

</body>
</html>