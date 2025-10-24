<?php
header('Content-Type: application/json; charset=utf-8');


$input = json_decode(file_get_contents("php://input"), true);
if (!$input  empty($input['token'])  empty($input['msisdn'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing token or msisdn'
    ]);
    exit;
}

$token = trim($input['token']);
$msisdn = trim($input['msisdn']);

$url = "https://apis.mytel.com.mm/daily-quest-v3/api/v3/daily-quest/daily-claim";

$data = [
    "requestTime" => round(microtime(true) * 1000),
    "requestId" => uniqid(),
    "rewardCode" => "SUCCESS",
    "msisdn" => $msisdn
];


$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json; charset=utf-8",
        "Accept-Encoding: gzip",
        "User-Agent: okhttp/3.9.1"
    ],
]);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode([
        'success' => false,
        'message' => "cURL Error: " . $error
    ]);
    exit;
}


$decoded = json_decode($response, true);
if (isset($decoded['code']) && $decoded['code'] === 200) {
    echo json_encode([
        'success' => true,
        'message' => $decoded['message'] ?? 'Claim success'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => $decoded['message'] ?? 'Claim failed'
    ]);
}
?>