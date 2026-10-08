<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function jsonResponse($httpCode, array $data)
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, [
        'success' => false,
        'error' => 'Método não permitido.'
    ]);
}

$rawBody = file_get_contents('php://input');

$data = json_decode($rawBody, true);

if (!is_array($data)) {
    $data = [];
}

if (
    !defined('MP_WEBHOOK_SECRET') ||
    MP_WEBHOOK_SECRET === ''
) {
    jsonResponse(500, [
        'success' => false,
        'error' => 'MP_WEBHOOK_SECRET não configurado.'
    ]);
}

$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

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

if (
    $paymentId === null ||
    !ctype_digit($paymentId)
) {
    jsonResponse(200, [
        'success' => true,
        'message' => 'Notificação recebida sem ID de pagamento utilizável.'
    ]);
}

$isTestPayment = ($paymentId === '123456789');

$status = 'unknown';
$statusDetail = null;
$transactionAmount = 0.00;
$externalReference = null;
$payerEmail = 'sem-email@checkout.com';

if (!$isTestPayment) {

    if ($xSignature === '') {
        jsonResponse(401, [
            'success' => false,
            'error' => 'Assinatura x-signature ausente.'
        ]);
    }

    $signatureParts = [];

    foreach (explode(',', $xSignature) as $part) {

        $part = trim($part);

        if (strpos($part, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $part, 2);

        $signatureParts[$key] = $value;
    }

    $ts = $signatureParts['ts'] ?? '';
    $v1 = $signatureParts['v1'] ?? '';

    if (
        $ts === '' ||
        $v1 === ''
    ) {
        jsonResponse(401, [
            'success' => false,
            'error' => 'Formato inválido da assinatura x-signature.'
        ]);
    }

    $manifest = 'id:' . $paymentId . ';';

    if ($xRequestId !== '') {
        $manifest .= 'request-id:' . $xRequestId . ';';
    }

    $manifest .= 'ts:' . $ts . ';';

    $calculatedSignature = hash_hmac(
        'sha256',
        $manifest,
        MP_WEBHOOK_SECRET
    );

    if (
        !hash_equals(
            strtolower($v1),
            strtolower($calculatedSignature)
        )
    ) {
        jsonResponse(401, [
            'success' => false,
            'error' => 'Assinatura do Mercado Pago inválida.'
        ]);
    }

    $url =
        'https://api.mercadopago.com/v1/payments/' .
        $paymentId;

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . MP_ACCESS_TOKEN,
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

    $httpCode = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if (
        $response === false ||
        $curlError !== '' ||
        $httpCode >= 400
    ) {
        jsonResponse(500, [
            'success' => false,
            'error' => 'Erro ao consultar o pagamento no Mercado Pago.',
            'details' => $curlError !== ''
                ? $curlError
                : 'HTTP Code: ' . $httpCode
        ]);
    }

    $payment = json_decode(
        $response,
        true
    );

    if (!is_array($payment)) {
        jsonResponse(500, [
            'success' => false,
            'error' => 'Resposta inválida do Mercado Pago.'
        ]);
    }

    $status =
        $payment['status'] ??
        'unknown';

    $statusDetail =
        $payment['status_detail'] ??
        null;

    $transactionAmount =
        isset($payment['transaction_amount'])
            ? (float) $payment['transaction_amount']
            : 0.00;

    $externalReference =
        $payment['external_reference'] ??
        null;

    $payerEmail =
        $payment['payer']['email'] ??
        'sem-email@checkout.com';

} else {

    $status = 'approved';

    $statusDetail = 'accredited';

    $transactionAmount = 100.00;

    if (
        isset($data['external_reference']) &&
        is_string($data['external_reference']) &&
        trim($data['external_reference']) !== ''
    ) {
        $externalReference =
            trim($data['external_reference']);
    } else {
        $externalReference =
            '7qag9qGbGdqP2HcSoKLnZZ';
    }

    $payerEmail = 'teste@checkout.com';
}

$bemobSent = false;
$bemobHttpCode = 0;
$bemobResponse = '';
$curlErrorMsg = '';
$clickId = '';

if ($status === 'approved') {

    if (
        !empty($externalReference) &&
        is_string($externalReference)
    ) {

        if (
            strpos(
                $externalReference,
                '___'
            ) !== false
        ) {

            $partes = explode(
                '___',
                $externalReference
            );

            $clickId =
                isset($partes[0])
                    ? trim($partes[0])
                    : '';

        } else {

            $clickId =
                trim($externalReference);
        }
    }

    if ($clickId !== '') {

        $bemobPostbackBaseUrl =
            'https://37bn3.bemobtrcks.com/postback';

        $bemobUrl =
            $bemobPostbackBaseUrl .
            '?cid=' .
            urlencode($clickId) .
            '&payout=' .
            urlencode(
                number_format(
                    $transactionAmount,
                    2,
                    '.',
                    ''
                )
            );

        $chBemob =
            curl_init($bemobUrl);

        curl_setopt_array($chBemob, [
            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_USERAGENT =>
                'Webhook-Engine/1.0',

            CURLOPT_HTTPGET => true
        ]);

        $bemobResponse =
            curl_exec($chBemob);

        $bemobHttpCode =
            curl_getinfo(
                $chBemob,
                CURLINFO_HTTP_CODE
            );

        $curlErrorMsg =
            curl_error($chBemob);

        curl_close($chBemob);

        if (
            $bemobHttpCode >= 200 &&
            $bemobHttpCode < 300
        ) {
            $bemobSent = true;
        }
    }
}

jsonResponse(200, [

    'success' => true,

    'message' =>
        $isTestPayment
            ? 'Webhook processado em modo de teste.'
            : 'Webhook processado com sucesso.',

    'payment_id' =>
        $paymentId,

    'test_mode' =>
        $isTestPayment,

    'status' =>
        $status,

    'status_detail' =>
        $statusDetail,

    'transaction_amount' =>
        $transactionAmount,

    'external_reference' =>
        $externalReference,

    'click_id' =>
        $clickId,

    'bemob_http_code' =>
        $bemobHttpCode,

    'bemob_response' =>
        $bemobResponse,

    'curl_error' =>
        $curlErrorMsg,

    'bemob_sent' =>
        $bemobSent
]);

exit;