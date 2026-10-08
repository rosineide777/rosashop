<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input');

$data = json_decode($rawBody, true);

$paymentId = null;

if (
    isset($_GET['data.id']) &&
    $_GET['data.id'] !== ''
) {
    $paymentId = (string) $_GET['data.id'];
}

if (
    $paymentId === null &&
    isset($data['data']['id']) &&
    $data['data']['id'] !== ''
) {
    $paymentId = (string) $data['data']['id'];
}

if (
    $paymentId === null &&
    isset($data['resource']) &&
    is_string($data['resource'])
) {
    if (
        preg_match(
            '/\/payments\/([0-9]+)/',
            $data['resource'],
            $matches
        )
    ) {
        $paymentId = $matches[1];
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Webhook PHP funcionando.',
    'payment_id' => $paymentId,
    'raw_body' => $rawBody,
    'data' => $data
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;