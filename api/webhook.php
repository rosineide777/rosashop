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
$externalReference = null;

// Extração do ID de pagamento e external_reference
if (is_array($data) && isset($data['data']['id']) && $data['data']['id'] !== '') {
    $paymentId = (string) $data['data']['id'];
}
if (is_array($data) && isset($data['external_reference'])) {
    $externalReference = $data['external_reference'];
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
| CONSULTA OU MODO DE TESTE (BYPASS PARA O ID 123456789)
|--------------------------------------------------------------------------
*/

$status = 'approved';
$statusDetail = 'accredited';
$transactionAmount = 100.00;
$payerEmail = 'sem-email@checkout.com';

if ($paymentId === '123456789') {
    // Modo de simulação para testes manuais
    if (empty($externalReference)) {
        $externalReference = '7qag9qGbGdqP2HcSoKLnZZ';
    }
} else {
    // Consulta real na API do Mercado Pago para pagamentos reais
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

    $status = $payment['status'] ?? 'unknown';
    $statusDetail = $payment['status_detail'] ?? null;
    $transactionAmount = isset($payment['transaction_amount']) ? (float) $payment['transaction_amount'] : 0;
    $externalReference = $payment['external_reference'] ?? null;
    $payerEmail = $payment['payer']['email'] ?? 'sem-email@checkout.com';
}

/*
|--------------------------------------------------------------------------
| PROCESSAMENTO DO ENVIO DIRETO PARA A API DE CONVERSÕES DO TIKTOK
|--------------------------------------------------------------------------
*/

$tiktokSent = false;
$tiktokHttpCode = 0;
$tiktokResponse = '';
$curlErrorMsg = '';

if ($status === 'approved') {

    if (!empty($externalReference) && is_string($externalReference)) {
        
        if (strpos($externalReference, '___') !== false) {
            $partes = explode('___', $externalReference);
            $clickId = isset($partes[0]) ? trim($partes[0]) : '';
        } else {
            $clickId = trim($externalReference);
        }
        
        if (!empty($clickId)) {
            // Endpoint oficial da API de Conversões do TikTok v1.3
            $tiktokUrl = "https://business-api.tiktok.com/open_api/v1.3/pixel/track/";
            
            // Gera o hash SHA256 do e-mail do cliente exigido pelo TikTok
            $emailHash = hash('sha256', strtolower(trim($payerEmail)));

            // Payload estruturado corrigido para produção (sem modo de teste)
            $payload = [
                "pixel_code" => "DAVURN3C77U77GG17K20",
                "event" => "CompletePayment",
                "event_source" => "PIXEL_EVENTS",
                "data" => [
                    [
                        "event_time" => time(),
                        "value" => (float) $transactionAmount,
                        "currency" => "BRL",
                        "user_data" => [
                            "email" => [$emailHash]
                        ],
                        "context" => [
                            "ad" => [
                                "callback_id" => $clickId
                            ]
                        ],
                        "properties" => [
                            "contents" => [
                                [
                                    "content_id" => "produto_principal",
                                    "content_type" => "product",
                                    "quantity" => 1,
                                    "price" => (float) $transactionAmount
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $chTiktok = curl_init($tiktokUrl);
            curl_setopt_array($chTiktok, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "Access-Token: fd86247944ec5910d3c2a608eb47d3b2077a323a"
                ],
                CURLOPT_USERAGENT => 'Webhook-Engine/1.0'
            ]);
            
            $tiktokResponse = curl_exec($chTiktok);
            $tiktokHttpCode = curl_getinfo($chTiktok, CURLINFO_HTTP_CODE);
            $curlErrorMsg = curl_error($chTiktok);
            curl_close($chTiktok);

            if ($tiktokHttpCode >= 200 && $tiktokHttpCode < 300) {
                $tiktokSent = true;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| RESPOSTA FINAL
|--------------------------------------------------------------------------
*/

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Webhook processado.',
    'payment_id' => $paymentId,
    'status' => $status,
    'tiktok_http_code' => $tiktokHttpCode,
    'tiktok_response' => $tiktokResponse,
    'curl_error' => $curlErrorMsg,
    'tiktok_sent' => $tiktokSent
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;