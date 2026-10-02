<?php

// Desativa a exibição de erros/avisos em HTML para não sujar o JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/config.php';

    // Valida se o Access Token do Mercado Pago está definido
    if (!defined('MP_ACCESS_TOKEN') || empty(MP_ACCESS_TOKEN) || MP_ACCESS_TOKEN === 'oculto') {
        throw new Exception("Access Token do Mercado Pago não configurado em config.php.");
    }

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
    | Recebe e valida o ID do pagamento
    |--------------------------------------------------------------------------
    */
    $paymentId = isset($_GET['payment_id']) ? trim($_GET['payment_id']) : '';

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
        throw new Exception("Não foi possível conectar ao Mercado Pago: " . $curlError);
    }

    /*
    |--------------------------------------------------------------------------
    | Decodifica resposta
    |--------------------------------------------------------------------------
    */
    $data = json_decode($response, true);

    if (!is_array($data)) {
        throw new Exception("Resposta inválida recebida da API do Mercado Pago.");
    }

    /*
    |--------------------------------------------------------------------------
    | Erro retornado pelo Mercado Pago
    |--------------------------------------------------------------------------
    */
    if ($httpCode < 200 || $httpCode >= 300) {
        $errorMessage = $data['message'] ?? 'Erro ao consultar o pagamento no Mercado Pago.';

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
    | Extração dos Dados Importantes
    |--------------------------------------------------------------------------
    */
    $paymentIdResponse = $data['id'] ?? $paymentId;
    $status = $data['status'] ?? 'unknown';
    $statusDetail = $data['status_detail'] ?? null;
    $transactionAmount = isset($data['transaction_amount']) ? (float)$data['transaction_amount'] : 0.0;
    $externalReference = $data['external_reference'] ?? null;

    /*
    |--------------------------------------------------------------------------
    | Retorno de Sucesso
    |--------------------------------------------------------------------------
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

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno no servidor PHP.',
        'details' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}