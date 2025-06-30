<?php

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// === Configuration ===
$remoteHost       = '1.2.3.4'; /// your remote host
$remotePort       = 1234; /// your remote port
$remoteBasePrefix = '/app.php'; /// your remote base path
$remoteHostHeader = 'example.com'; /// your remote host header
$logFile          = __DIR__ . '/proxy.log';
$loggingEnabled   = true; /// enable or disable logging

// === Logger ===
function logMessage($msg) {
    global $logFile, $loggingEnabled;
    if (!$loggingEnabled) return;
    $t = date('Y-m-d H:i:s');
    error_log("[$t] $msg\n", 3, $logFile);
}

// === Disable buffering ===
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'off');
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);
set_time_limit(0);

// Capture incoming request headers
$requestHeaders = [];
foreach (getallheaders() as $name => $value) {
    if (strcasecmp($name, 'Host') !== 0) {
        $requestHeaders[] = "$name: $value";
    }
}

// Log incoming request
$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['REQUEST_URI'];
logMessage("Incoming $method from {$_SERVER['REMOTE_ADDR']} URI=$uri HEADERS=" . json_encode($requestHeaders));

// Validate prefix
if (strpos($uri, $remoteBasePrefix . '/') !== 0) {
    http_response_code(400);
    logMessage("Invalid path prefix: $uri");
    echo 'Invalid path';
    exit;
}

// Build remote URL
$remoteUrl = "http://$remoteHost:$remotePort$uri";
logMessage("Proxying to $remoteUrl");

// cURL initializer: merges incoming request headers + necessary overrides
function initCurl($url, $method) {
    global $remoteHostHeader, $requestHeaders;
    $ch = curl_init($url);
    // Start with incoming headers
    $headers = $requestHeaders;
    // Override Host
    $headers[] = "Host: $remoteHostHeader";
    // Ensure correct content negotiation
    if ($method === 'GET') {
        $headers[] = 'Accept: text/event-stream';
        $headers[] = 'Cache-Control: no-store';
        $headers[] = 'X-Accel-Buffering: no';
    } else {
        $headers[] = 'Content-Type: application/octet-stream';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FAILONERROR, false);
    return $ch;
}

// Forward response headers including X-* headers
function forwardHeaders($ch, $headerLine) {
    if (preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', trim($headerLine), $m)) {
        $name = $m[1];
        $value = $m[2];
        // Relay X-Padding and other X- headers
        if (stripos($name, 'X-') === 0) {
            header("$name: $value");
        }
    }
    return strlen($headerLine);
}

// Handle POST uplink (packet-up)
if ($method === 'POST') {
    $payload = file_get_contents('php://input');
    $ch = initCurl($remoteUrl, 'POST');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'forwardHeaders');

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $body = substr($resp, $headerSize);
    logMessage("POST status=$status err='$err' body_len=".strlen($body));

    http_response_code($status);
    header('Content-Type: text/plain');
    echo $body;
    exit;
}

// Handle GET SSE downlink
if ($method === 'GET') {
    header('Content-Type: text/event-stream');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST');

    $ch = initCurl($remoteUrl, 'GET');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'forwardHeaders');
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
        echo $chunk;
        if (ob_get_length()) ob_flush();
        flush();
        return strlen($chunk);
    });

    $ok = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502;
    curl_close($ch);

    logMessage("GET SSE status=$status err='$err'");
    if (!$ok) {
        http_response_code(502);
        echo 'Upstream error';
    }
    exit;
}

// Other methods
http_response_code(405);
logMessage("Method Not Allowed: $method");
echo 'Method Not Allowed';
exit;
