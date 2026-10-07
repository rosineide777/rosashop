<?php

// Desativa a exibição de erros/avisos HTML para não poluir a resposta JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| WEBHOOK MERCADO PAGO - VALIDAÇÃO DA REQUISIÇÃO
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

$paymentId = null;

// Extração do ID de pagamento nos formatos enviados pelo Mercado Pago
if (is_array($data) && isset($data['data']['id']) && $data['data']['id'] !== '') {
    $paymentId = (string) $data['data']['id'];
}

if ($paymentId === null && is_array($data) && isset($data['resource']) && is_string($data['resource'])) {
    $resource = $data['resource'];
    if (preg_match('/\/payments\/([0-9]+)/', $resource, $matches)) {
        $paymentId = $matches[1];
    }
}

if ($paymentId === null && isset($_GET['data.id']) && $_GET['data.id'] !== '') {
    $paymentId = (string) $_GET['data.id'];
}

if ($paymentId === null || !ctype_digit($paymentId)) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Notificação recebida sem ID de pagamento utilizável.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| CONSULTA O PAGAMENTO DIRETO NA API DO MERCADO PAGO
|--------------------------------------------------------------------------
*/

$url = 'https://api.mercadopago.com/v1/payments/' . $paymentId;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'Content-Type: application/json'
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlError !== '' || $httpCode >= 400) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao consultar o pagamento no Mercado Pago.',
        'details' => $curlError !== '' ? $curlError : "HTTP Code: $httpCode"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payment = json_decode($response, true);

if (!is_array($payment)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Resposta inválida do Mercado Pago.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| PROCESSAMENTO DO PAGAMENTO E POSTBACK BEMOB
|--------------------------------------------------------------------------
*/

$status = $payment['status'] ?? 'unknown';
$statusDetail = $payment['status_detail'] ?? null;
$transactionAmount = isset($payment['transaction_amount']) ? (float) $payment['transaction_amount'] : 0;
$externalReference = $payment['external_reference'] ?? null;

$bemobSent = false;

if ($status === 'approved') {

    // Extração do ClickID (subid) a partir da external_reference
    if (!empty($externalReference) && is_string($externalReference)) {
        
        if (strpos($externalReference, '___') !== false) {
            $partes = explode('___', $externalReference);
            $clickId = isset($partes[0]) ? trim($partes[0]) : '';
        } else {
            $clickId = trim($externalReference);
        }
        
        if (!empty($clickId)) {
            // URL oficial do BeMob (Usando HTTPS para garantir o envio)
            $bemobPostbackUrl = "https://37bn3.bemobtrcks.com/postback?cid=" . urlencode($clickId) . "&payout=" . urlencode($transactionAmount);
            
            // Disparo S2S para a BeMob
            $chBemob = curl_init($bemobPostbackUrl);
            curl_setopt_array($chBemob, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false, // Evita bloqueio por SSL
                CURLOPT_USERAGENT => 'Webhook-Engine/1.0'
            ]);
            
            $bemobResponse = curl_exec($chBemob);
            $bemobHttpCode = curl_getinfo($chBemob, CURLINFO_HTTP_CODE);
            curl_close($chBemob);

            if ($bemobHttpCode >= 200 && $bemobHttpCode < 300) {
                $bemobSent = true;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| RESPOSTA FINAL AO MERCADO PAGO
|--------------------------------------------------------------------------
*/

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Webhook recebido e processado com sucesso.',
    'payment_id' => $paymentId,
    'status' => $status,
    'status_detail' => $statusDetail,
    'transaction_amount' => $transactionAmount,
    'external_reference' => $externalReference,
    'bemob_postback_sent' => $bemobSent
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;