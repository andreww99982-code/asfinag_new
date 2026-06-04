<?php
require_once 'config.php';

header('Content-Type: application/json');

$bin = $_GET['bin'] ?? '';

if (strlen($bin) < 6) {
    echo json_encode(['error' => 'Invalid BIN']);
    exit;
}

$bin = substr($bin, 0, 6);
$url = "https://data.handyapi.com/bin/" . $bin;

$options = [
    "http" => [
        "method" => "GET",
        "header" => "x-api-key: " . BIN_API_KEY . "\r\n"
    ]
];

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);

if ($response === FALSE) {
    echo json_encode(['error' => 'API request failed']);
    exit;
}

echo $response;
?>