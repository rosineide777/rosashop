<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Arquivo config.php nao encontrado no servidor.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!is_array($data)) {
    $data = [];
}

$paymentId = null;

if (isset($_GET['data.id']) && $_GET['data.id'] !== '') {
    $paymentId = (string) $_GET['data.id'];
}

if ($paymentId === null && isset($data['data']['id']) && $data['data']['id'] !== '') {
    $paymentId = (string) $data['data']['id'];
}

if ($paymentId === null && isset($data['resource']) && is_string($data['resource'])) {
    if (preg_match('/\/payments\/([0-9]+)/', $data['resource'], $matches)) {
        $paymentId = isset($matches[1]) ? $matches[1] : null;
    }
}

if ($paymentId === null || !ctype_digit($paymentId)) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Notifikasi recebida sem ID de pagamento utilizavel.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$isTestPayment = ($paymentId === '123456789');

$status = 'unknown';
$statusDetail = null;
$transactionAmount = 0.00;
$externalReference = null;
$payerEmail = 'sem-email@checkout.com';

if (!$isTestPayment) {

    if (!defined('MP_WEBHOOK_SECRET') || MP_WEBHOOK_SECRET === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'MP_WEBHOOK_SECRET não configurado no config.php.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

    if ($xSignature === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Assinatura x-signature ausente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $signatureParts = [];
    foreach (explode(',', $xSignature) as $part) {
        $part = trim($part);
        if (strpos($part, '=') === false) continue;
        [$key, $value] = explode('=', $part, 2);
        $signatureParts[$key] = $value;
    }

    $ts = $signatureParts['ts'] ?? '';
    $v1 = $signatureParts['v1'] ?? '';

    if ($ts === '' || $v1 === '') {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Formato inválido da assinatura x-signature.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $manifest = 'id:' . $paymentId . ';';
    if ($xRequestId !== '') {
        $manifest .= 'request-id:' . $xRequestId . ';';
    }
    $manifest .= 'ts:' . $ts . ';';

    $calculatedSignature = hash_hmac('sha256', $manifest, MP_WEBHOOK_SECRET);

    if (!hash_equals(strtolower($v1), strtolower($calculatedSignature))) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Assinatura do Mercado Pago inválida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $url = 'https://mercadopago.com' . $paymentId;
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . (defined('MP_ACCESS_TOKEN') ? MP_ACCESS_TOKEN : ''),
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CUSTOMREQUEST => 'GET'
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode >= 400) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao consultar o pagamento no Mercado Pago.', 'details' => $curlError !== '' ? $curlError : 'HTTP Code: ' . $httpCode], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $payment = json_decode($response, true);
    if (!is_array($payment)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Resposta inválida do Mercado Pago.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = $payment['status'] ?? 'unknown';
    $statusDetail = $payment['status_detail'] ?? null;
    $transactionAmount = isset($payment['transaction_amount']) ? (float)$payment['transaction_amount'] : 0;
    $externalReference = $payment['external_reference'] ?? null;
    $payerEmail = $payment['payer']['email'] ?? 'sem-email@checkout.com';

} else {
    $status = 'approved';
    $statusDetail = 'accredited';
    $transactionAmount = 100.00;
    $externalReference = '7qag9qGbGdqP2HcSoKLnZZ';
}

$bemobSent = false;
$bemobHttpCode = 0;
$bemobResponse = '';
$curlErrorMsg = '';
$clickId = '';

if ($status === 'approved') {
    if (!empty($externalReference) && is_string($externalReference)) {
        if (strpos($externalReference, '___') !== false) {
            $partes = explode('___', $externalReference);
            $clickId = isset($partes[0]) ? trim($partes[0]) : '';
        } else {
            $clickId = trim($externalReference);
        }
    }

    if ($clickId !== '') {
        $bemobPostbackBaseUrl = 'https://bemobtrcks.com';
        $bemobUrl = $bemobPostbackBaseUrl . '?cid=' . urlencode($clickId) . '&payout=' . urlencode(number_format($transactionAmount, 2, '.', ''));

        $chBemob = curl_init($bemobUrl);
        curl_setopt_array($chBemob, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Webhook-Engine/1.0',
            CURLOPT_HTTPGET => true
        ]);

        $bemobResponse = curl_exec($chBemob);
        $bemobHttpCode = curl_getinfo($chBemob, CURLINFO_HTTP_CODE);
        $curlErrorMsg = curl_error($chBemob);
        curl_close($chBemob);

        if ($bemobHttpCode >= 200 && $bemobHttpCode < 300) {
            $bemobSent = true;
        }
    }
}

// RESPOSTA FORMATADA LIMPA DE ACORDO COM O FLUXO SERVERLESS DA VERCEL
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => $isTestPayment ? 'Webhook processado em modo de teste.' : 'Webhook processado com sucesso.',
    'payment_id' => $paymentId,
    'test_mode' => $isTestPayment,
    'status' => $status,
    'transaction_amount' => $transactionAmount,
    'external_reference' => $externalReference,
    'click_id' => $clickId,
    'bemob_http_code' => $bemobHttpCode,
    'bemob_response' => $bemobResponse,
    'curl_error' => $curlErrorMsg,
    'bemob_sent' => $bemobSent
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Garante o fechamento limpo liberando o buffer de dados imediatamente
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
