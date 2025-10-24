<?php
// Simple backend proxy to fetch MyID token
// Expects headers: 'access-token' and 'phone-number'

header('Content-Type: application/json; charset=utf-8');
// If you need cross-origin access during development, uncomment below
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Headers: Content-Type, access-token, phone-number');

// Allow only GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed'
    ]);
    exit;
}

// Helper: get headers case-insensitively
function get_header_ci(array $headers, string $key): ?string {
    foreach ($headers as $k => $v) {
        if (strtolower($k) === strtolower($key)) {
            return is_array($v) ? ($v[0] ?? null) : $v;
        }
    }
    return null;
}

// getallheaders alternative for certain SAPIs
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}

$headers = getallheaders();
$accessToken = get_header_ci($headers, 'access-token');
$phoneNumber = get_header_ci($headers, 'phone-number');

if (!$accessToken || !$phoneNumber) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required headers: access-token and/or phone-number'
    ]);
    exit;
}

// Build target URL (as provided)
$query = http_build_query([
    'uuid'  => 'd76d97c8d3351be3a26c0f5edf00131c',
    'mcuid' => '58e88a766de8a4628fd62173ac0f91c2',
    'mcapp' => 'myid',
]);
$url = 'https://myidgo.mytel.com.mm/?' . $query;

// Prepare headers for upstream request
$ua = 'Mozilla/5.0 (Linux; Android 11; Redmi Note 8 Pro Build/RP1A.200720.011; wv) '
    . 'AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 '
    . 'Chrome/139.0.7258.94 Mobile Safari/537.36';

$upstreamHeaders = [
    'avatar: ',
    'lang: en',
    'phone-number: ' . $phoneNumber,
    'access-token: ' . $accessToken,
    'User-Agent: ' . $ua,
    // Optional but sometimes helpful
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9',
    'Cache-Control: no-cache',
];

// Use cURL if available, otherwise fallback to file_get_contents
function http_get_with_headers(string $url, array $headers, int $timeout = 20): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => true,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [null, 0, $err];
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);
        curl_close($ch);
        return [$body, $status, null];
    }

    // Fallback using streams
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int)$m[1];
                break;
            }
        }
    }
    return [$body === false ? null : $body, $status, $body === false ? 'Request failed' : null];
}

[$html, $status, $error] = http_get_with_headers($url, $upstreamHeaders);

if ($error !== null) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Upstream request error: ' . $error,
    ]);
    exit;
}

if ($status < 200 || $status >= 300 || $html === null) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => 'Upstream returned HTTP ' . $status,
    ]);
    exit;
}


if (preg_match('/window\.token\s*=\s*"([^"]+)"/i', $html, $m)) {
    echo json_encode([
        'success' => true,
        'token' => $m[1],
    ]);
    exit;
}

http_response_code(200);
echo json_encode([
    'success' => false,
    'message' => 'Token not found in response',
]);

