<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';


/*
|--------------------------------------------------------------------------
| FUNÇÃO DE RESPOSTA JSON
|--------------------------------------------------------------------------
*/

function jsonResponse($httpCode, array $data)
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| SOMENTE POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, [
        'success' => false,
        'error' => 'Método não permitido.'
    ]);
}


/*
|--------------------------------------------------------------------------
| RECEBE O BODY
|--------------------------------------------------------------------------
*/

$rawBody = file_get_contents('php://input');

$data = json_decode($rawBody, true);

if (!is_array($data)) {
    $data = [];
}


/*
|--------------------------------------------------------------------------
| VERIFICA CONFIGURAÇÃO DO WEBHOOK
|--------------------------------------------------------------------------
*/

if (
    !defined('MP_WEBHOOK_SECRET') ||
    MP_WEBHOOK_SECRET === ''
) {
    jsonResponse(500, [
        'success' => false,
        'error' => 'MP_WEBHOOK_SECRET não configurado.'
    ]);
}


/*
|--------------------------------------------------------------------------
| HEADERS DO MERCADO PAGO
|--------------------------------------------------------------------------
*/

$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';


/*
|--------------------------------------------------------------------------
| EXTRAI ID DO PAGAMENTO
|--------------------------------------------------------------------------
*/

$paymentId = null;


/*
|--------------------------------------------------------------------------
| 1. ID PELA URL
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['data.id']) &&
    $_GET['data.id'] !== ''
) {
    $paymentId = (string) $_GET['data.id'];
}


/*
|--------------------------------------------------------------------------
| 2. ID PELO BODY
|--------------------------------------------------------------------------
*/

if (
    $paymentId === null &&
    isset($data['data']['id']) &&
    $data['data']['id'] !== ''
) {
    $paymentId = (string) $data['data']['id'];
}


/*
|--------------------------------------------------------------------------
| 3. ID PELO RESOURCE
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| VERIFICA SE EXISTE ID
|--------------------------------------------------------------------------
*/

if (
    $paymentId === null ||
    !ctype_digit($paymentId)
) {
    jsonResponse(200, [
        'success' => true,
        'message' => 'Notificação recebida sem ID de pagamento utilizável.'
    ]);
}


/*
|--------------------------------------------------------------------------
| VALIDAÇÃO DA ASSINATURA
|--------------------------------------------------------------------------
|
| O ID 123456789 é reservado para TESTE.
|
| Nesse caso:
| - Não exige x-signature
| - Não calcula hash
| - Não rejeita por assinatura
|
| Para qualquer outro ID:
| - A assinatura é obrigatória
| - A assinatura é calculada
| - A assinatura é comparada
|--------------------------------------------------------------------------
*/

$isTestPayment = ($paymentId === '123456789');


if (!$isTestPayment) {

    /*
    |--------------------------------------------------------------------------
    | PAGAMENTO REAL - ASSINATURA OBRIGATÓRIA
    |--------------------------------------------------------------------------
    */

    if ($xSignature === '') {
        jsonResponse(401, [
            'success' => false,
            'error' => 'Assinatura x-signature ausente.'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | SEPARA ts E v1
    |--------------------------------------------------------------------------
    */

    $signatureParts = [];

    foreach (
        explode(',', $xSignature)
        as $part
    ) {

        $part = trim($part);

        if (
            strpos($part, '=') === false
        ) {
            continue;
        }

        [$key, $value] = explode(
            '=',
            $part,
            2
        );

        $signatureParts[$key] = $value;
    }


    $ts = $signatureParts['ts'] ?? '';
    $v1 = $signatureParts['v1'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | VERIFICA FORMATO DA ASSINATURA
    |--------------------------------------------------------------------------
    */

    if (
        $ts === '' ||
        $v1 === ''
    ) {
        jsonResponse(401, [
            'success' => false,
            'error' => 'Formato inválido da assinatura x-signature.'
        ]);
    }


    /*
    |--------------------------------------------------------------------------
    | MONTA MANIFEST
    |--------------------------------------------------------------------------
    */

    $manifest = 'id:' . $paymentId . ';';

    if ($xRequestId !== '') {
        $manifest .=
            'request-id:' .
            $xRequestId .
            ';';
    }

    $manifest .=
        'ts:' .
        $ts .
        ';';


    /*
    |--------------------------------------------------------------------------
    | CALCULA ASSINATURA HMAC
    |--------------------------------------------------------------------------
    */

    $calculatedSignature = hash_hmac(
        'sha256',
        $manifest,
        MP_WEBHOOK_SECRET
    );


    /*
    |--------------------------------------------------------------------------
    | COMPARA ASSINATURAS
    |--------------------------------------------------------------------------
    */

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
}


/*
|--------------------------------------------------------------------------
| CONSULTA O PAGAMENTO NO MERCADO PAGO
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| ERRO NA CONSULTA
|--------------------------------------------------------------------------
*/

if (
    $response === false ||
    $curlError !== '' ||
    $httpCode >= 400
) {

    jsonResponse(500, [
        'success' => false,
        'error' =>
            'Erro ao consultar o pagamento no Mercado Pago.',
        'details' =>
            $curlError !== ''
                ? $curlError
                : 'HTTP Code: ' . $httpCode
    ]);
}


/*
|--------------------------------------------------------------------------
| DECODIFICA PAGAMENTO
|--------------------------------------------------------------------------
*/

$payment = json_decode(
    $response,
    true
);

if (!is_array($payment)) {

    jsonResponse(500, [
        'success' => false,
        'error' =>
            'Resposta inválida do Mercado Pago.'
    ]);
}


/*
|--------------------------------------------------------------------------
| DADOS DO PAGAMENTO
|--------------------------------------------------------------------------
*/

$status =
    $payment['status'] ??
    'unknown';

$statusDetail =
    $payment['status_detail'] ??
    null;

$transactionAmount =
    isset($payment['transaction_amount'])
        ? (float) $payment['transaction_amount']
        : 0;

$externalReference =
    $payment['external_reference'] ??
    null;

$payerEmail =
    $payment['payer']['email'] ??
    'sem-email@checkout.com';


/*
|--------------------------------------------------------------------------
| VARIÁVEIS DO BEMOB
|--------------------------------------------------------------------------
*/

$bemobSent = false;

$bemobHttpCode = 0;

$bemobResponse = '';

$curlErrorMsg = '';

$clickId = '';


/*
|--------------------------------------------------------------------------
| SOMENTE PAGAMENTO APPROVED
|--------------------------------------------------------------------------
*/

if ($status === 'approved') {

    /*
    |--------------------------------------------------------------------------
    | EXTRAI CLICK ID DO EXTERNAL_REFERENCE
    |--------------------------------------------------------------------------
    */

    if (
        !empty($externalReference) &&
        is_string($externalReference)
    ) {

        /*
        |--------------------------------------------------------------------------
        | FORMATO:
        |
        | CLICK_ID___OUTRA_INFORMACAO
        |--------------------------------------------------------------------------
        */

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

            /*
            |--------------------------------------------------------------------------
            | SE FOR SOMENTE O CLICK ID
            |--------------------------------------------------------------------------
            */

            $clickId =
                trim(
                    $externalReference
                );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | ENVIA POSTBACK PARA BEMOB
    |--------------------------------------------------------------------------
    */

    if ($clickId !== '') {

        $bemobPostbackBaseUrl =
            'https://37bn3.bemobtrcks.com/postback';


        /*
        |--------------------------------------------------------------------------
        | MONTA URL DO POSTBACK
        |--------------------------------------------------------------------------
        */

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


        /*
        |--------------------------------------------------------------------------
        | ENVIA POSTBACK
        |--------------------------------------------------------------------------
        */

        $chBemob =
            curl_init($bemobUrl);


        curl_setopt_array(
            $chBemob,
            [

                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_TIMEOUT =>
                    15,

                CURLOPT_CONNECTTIMEOUT =>
                    5,

                CURLOPT_SSL_VERIFYPEER =>
                    true,

                CURLOPT_SSL_VERIFYHOST =>
                    2,

                CURLOPT_USERAGENT =>
                    'Webhook-Engine/1.0',

                CURLOPT_HTTPGET =>
                    true
            ]
        );


        $bemobResponse =
            curl_exec(
                $chBemob
            );


        $bemobHttpCode =
            curl_getinfo(
                $chBemob,
                CURLINFO_HTTP_CODE
            );


        $curlErrorMsg =
            curl_error(
                $chBemob
            );


        curl_close(
            $chBemob
        );


        /*
        |--------------------------------------------------------------------------
        | VERIFICA SE O BEMOB ACEITOU
        |--------------------------------------------------------------------------
        */

        if (
            $bemobHttpCode >= 200 &&
            $bemobHttpCode < 300
        ) {

            $bemobSent = true;
        }
    }
}


/*
|--------------------------------------------------------------------------
| RESPOSTA FINAL
|--------------------------------------------------------------------------
*/

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

// teste de atualizacao forcada bemob 2026
