<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
|--------------------------------------------------------------------------
| Recebe o ID do pagamento
|--------------------------------------------------------------------------
*/

$paymentId = isset($_GET['payment_id'])
    ? trim($_GET['payment_id'])
    : '';

if ($paymentId === '' || !ctype_digit($paymentId)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'error' => 'ID de pagamento inválido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| Consulta o pagamento no Mercado Pago
|--------------------------------------------------------------------------
*/

$url = 'https://api.mercadopago.com/v1/payments/' . urlencode($paymentId);

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

/*
|--------------------------------------------------------------------------
| Erro de conexão
|--------------------------------------------------------------------------
*/

if ($response === false || $curlError !== '') {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Não foi possível consultar o pagamento.',
        'details' => $curlError
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| Decodifica resposta
|--------------------------------------------------------------------------
*/

$data = json_decode($response, true);

if (!is_array($data)) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Resposta inválida do Mercado Pago.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| Erro retornado pelo Mercado Pago
|--------------------------------------------------------------------------
*/

if ($httpCode < 200 || $httpCode >= 300) {

    $errorMessage = 'Erro ao consultar o pagamento.';

    if (isset($data['message']) && $data['message'] !== '') {
        $errorMessage = $data['message'];
    }

    http_response_code($httpCode > 0 ? $httpCode : 500);

    echo json_encode([
        'success' => false,
        'error' => $errorMessage,
        'status' => $data['status'] ?? null,
        'status_detail' => $data['status_detail'] ?? null
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| Dados importantes do pagamento
|--------------------------------------------------------------------------
*/

$paymentIdResponse = $data['id'] ?? $paymentId;

$status = $data['status'] ?? 'unknown';

$statusDetail = $data['status_detail'] ?? null;

$transactionAmount = isset($data['transaction_amount'])
    ? (float) $data['transaction_amount']
    : 0;

$externalReference = $data['external_reference'] ?? null;

/*
|--------------------------------------------------------------------------
| Retorno para o carrinho
|--------------------------------------------------------------------------
|
| O carrinho.html usa principalmente:
|
| - payment_id
| - status
| - status_detail
| - transaction_amount
| - external_reference
|
| O status "approved" significa que o pagamento
| foi efetivamente aprovado.
|
*/

echo json_encode([
    'success' => true,

    'payment_id' => $paymentIdResponse,

    'status' => $status,

    'status_detail' => $statusDetail,

    'transaction_amount' => $transactionAmount,

    'amount' => $transactionAmount,

    'external_reference' => $externalReference
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;