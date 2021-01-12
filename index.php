<?php

// Note: https://g0v.hackmd.io/A175kRMVSxGqBRCerawXIA
// License: BSD License

function getCSV($id, $skip_ethercalc_down = true) {
    if ($skip_ethercalc_down and file_exists("/tmp/ethercalc-down") and file_get_contents("/tmp/ethercalc-down") > time() - 5 * 60) {
        // if ethercalc is down in 5 minutes and $skip_ethercalc_down is true, skip it
        throw new Exception("ethercalc is down in 5 minutes");
    }
    $curl = curl_init("https://ethercalc.net/_/{$id}/csv");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    if ($skip_ethercalc_down) {
        curl_setopt($curl, CURLOPT_TIMEOUT, 3);
    } else {
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
    }
    $content = curl_exec($curl);
    $info = curl_getinfo($curl);
    if (200 != $info['http_code']) {
        file_put_contents("/tmp/ethercalc-down", time());
        error_log("ethercalc down");
        throw new Exception("fetch error: " . curl_error($curl));
    }
    if (file_exists("/tmp/ethercalc-down")) {
        unlink("/tmp/ethercalc-down");
    }
    return $content;
}

function printContent($content, $header_note) {
    header('Access-Control-Allow-Methods: GET');
    header('Access-Control-Allow-Origin: *');
    header('Content-Type: text/csv; charset=utf-8');
    header('X-Cache-Status: ' . $header_note);

    echo $content;
    exit;
}

// check URI must be /_/{$name}/csv
$uri = $_SERVER['REQUEST_URI'];
if (!preg_match('#^/_/([^/?]+)/csv(\?purge=1)?$#', $uri, $matches)) {
    die("only allow /_/{name}/csv URL");
}
$id = $matches[1];
$purge = false;
if ($matches[2]) {
    $purge = true;
}

// env DATABASE_URL=mysql://{user}:{pass}@{ip}/{db}
// connect to db
if (!preg_match('#mysql://(.*)(:.*)@(.*)/(.*)#', getenv('DATABASE_URL'), $matches)) {
    die("need DATABASE_URL");
}
$db_user = $matches[1];
$db_password = ltrim($matches[2], ':');
$db_ip = $matches[3];
$db_name = $matches[4];
$db = new PDO(sprintf("mysql:host=%s;dbname=%s", $db_ip, $db_name), $db_user, $db_password);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($purge) {
    $sql = "UPDATE cache SET cache_at = 0 WHERE id = " . $db->quote($id);
    $db->prepare($sql)->execute();
    exit;
}

$sql = "SELECT * FROM cache WHERE id = " . $db->quote($id);
$stmt = $db->prepare($sql);
$stmt->execute();
// cache miss
if (!$row = $stmt->fetch()) {
    try {
        $content = getCSV($id, false);
        $sql = sprintf("INSERT INTO cache (id, cache_at, content) VALUES (%s, %d, %s)",
            $db->quote($id),
            intval(time()),
            $db->quote($content)
        );
        $db->prepare($sql)->execute();
        printContent($content, "Cache miss, fetch it");
        exit;
    } catch (Exception $e) {
        die("backend error: " . $e->getMessage());
    }
}

// cache hit but expired
if ($row['cache_at'] < time() - 5 * 60) {
    try {
        $content = getCSV($id);
        $sql = sprintf("UPDATE cache SET cache_at = %d, content = %s WHERE id = %s",
            intval(time()),
            $db->quote($content),
            $db->quote($id)
        );
        $db->prepare($sql)->execute();
        printContent($content, "Cache hit but expired, update");
        exit;
    } catch (Exception $e) {
        printContent($row['content'], "backend error, use cache");
    }
}
printContent($row['content'], "Cache hit");
