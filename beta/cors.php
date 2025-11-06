<?php
ini_set('display_errors', '0');
set_time_limit(0);
ignore_user_abort(true);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: *");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_GET['url'])) {
    http_response_code(400);
    exit("Missing url");
}

$url = $_GET['url'];
$parsed = parse_url($url);
if ($parsed === false || !isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'])) {
    http_response_code(400);
    exit("Invalid or missing scheme in url");
}

// Disable PHP buffering
while (ob_get_level()) ob_end_clean();
ob_implicit_flush(true);

// Gather headers to forward
$incoming = function_exists('getallheaders') ? getallheaders() : [];
$upstreamHeaders = [];
$hopByHop = array_map('strtolower', [
    'connection','keep-alive','proxy-authenticate','proxy-authorization',
    'te','trailers','transfer-encoding','upgrade','proxy-connection'
]);
$allowedForward = ['user-agent','accept','accept-language','range','referer','cookie','authorization'];

foreach ($incoming as $name => $value) {
    $nl = strtolower($name);
    if ($nl === 'host') continue;
    if (in_array($nl, $hopByHop)) continue;
    if (!in_array($nl, $allowedForward)) continue;
    $upstreamHeaders[] = "$name: $value";
}

$headerNames = array_map('strtolower', array_keys($incoming));

// Origin
if (!in_array('origin', $headerNames)) {
    $upstreamHeaders[] = "Origin: https://orteil.dashnet.org";
}

// Referer
if (!in_array('referer', $headerNames)) {
    $upstreamHeaders[] = "Referer: https://orteil.dashnet.org/";
}

// Detect and set Range for cURL
$hasRange = false;
if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    $upstreamHeaders[] = "Range: $range";
    $hasRange = true;
}

// Initialize cURL
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false, // we stream directly
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_BUFFERSIZE => 65536,
    CURLOPT_HTTPHEADER => $upstreamHeaders,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_FAILONERROR => false, // pass through non-200s
    CURLOPT_HEADER => false,
    CURLOPT_FORBID_REUSE => false,
    CURLOPT_FRESH_CONNECT => false
]);

$currentStatus = 200;

curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $headerLine) use (&$currentStatus, $hopByHop) {
    $trim = trim($headerLine);
    if ($trim === '') return strlen($headerLine);

    if (preg_match('#^HTTP/[0-9\.]+\s+([0-9]{3})#i', $trim, $m)) {
        $code = intval($m[1]);
        http_response_code($code); // This will be 200 or 206
        $currentStatus = $code;
        return strlen($headerLine);
    }

    $parts = explode(':', $trim, 2);
    if (count($parts) !== 2) return strlen($headerLine);

    $name = trim($parts[0]);
    $value = trim($parts[1]);
    $lname = strtolower($name);

    if (in_array($lname, $hopByHop)) return strlen($headerLine);
    if ($lname === 'access-control-allow-origin') return strlen($headerLine);

    // Forward range-related + content headers
    $passHeaders = ['content-type','content-length','content-range','accept-ranges','etag','last-modified','cache-control'];
    if (in_array($lname, $passHeaders) || str_starts_with($lname, 'x-')) {
        header("$name: $value", false);
    }

    return strlen($headerLine);
});

// Stream body
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    curl_setopt($ch, CURLOPT_NOBODY, true);
} else {
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
        echo $data;
        @flush();
        return strlen($data);
    });
}

// Execute
$ok = curl_exec($ch);

if ($ok === false && !headers_sent()) {
    http_response_code(502);
    header('Content-Type: text/plain');
    echo "cURL error: " . curl_error($ch);
}

curl_close($ch);
