<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| WEBHOOK MERCADO PAGO
|--------------------------------------------------------------------------
|
| O Mercado Pago envia uma notificação quando há alteração no pagamento.
|
| Importante:
| - Não confiamos apenas nos dados recebidos pelo webhook.
| - Pegamos o ID do pagamento.
| - Consultamos diretamente a API do Mercado Pago.
| - Só consideramos o pagamento confirmado quando o status for:
|   approved
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Aceita POST
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


/*
|--------------------------------------------------------------------------
| Lê o corpo enviado pelo Mercado Pago
|--------------------------------------------------------------------------
*/

$rawBody = file_get_contents('php://input');

$data = json_decode($rawBody, true);


/*
|--------------------------------------------------------------------------
| O Mercado Pago pode enviar o ID em formatos diferentes.
|--------------------------------------------------------------------------
*/

$paymentId = null;


/*
|--------------------------------------------------------------------------
| Formato comum:
|
| {
|   "type": "payment",
|   "data": {
|      "id": "123456789"
|   }
| }
|--------------------------------------------------------------------------
*/

if (
    is_array($data) &&
    isset($data['data']['id']) &&
    $data['data']['id'] !== ''
) {
    $paymentId = (string) $data['data']['id'];
}


/*
|--------------------------------------------------------------------------
| Alguns formatos podem enviar resource
|--------------------------------------------------------------------------
*/

if (
    $paymentId === null &&
    is_array($data) &&
    isset($data['resource']) &&
    is_string($data['resource'])
) {
    $resource = $data['resource'];

    if (preg_match('/\/payments\/([0-9]+)/', $resource, $matches)) {
        $paymentId = $matches[1];
    }
}


/*
|--------------------------------------------------------------------------
| Também aceita query string:
|
| ?data.id=123456789
|
|--------------------------------------------------------------------------
*/

if (
    $paymentId === null &&
    isset($_GET['data.id']) &&
    $_GET['data.id'] !== ''
) {
    $paymentId = (string) $_GET['data.id'];
}


/*
|--------------------------------------------------------------------------
| Validação do ID
|--------------------------------------------------------------------------
*/

if (
    $paymentId === null ||
    !ctype_digit($paymentId)
) {
    /*
     * Retornamos 200 para evitar que o Mercado Pago fique
     * reenviando uma notificação sem ID utilizável.
     */

    http_response_code(200);

    echo json_encode([
        'success' => true,
        'message' => 'Notificação recebida sem ID de pagamento utilizável.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Consulta o pagamento diretamente no Mercado Pago
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
        'error' => 'Erro ao consultar o pagamento.',
        'details' => $curlError
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Decodifica resposta
|--------------------------------------------------------------------------
*/

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
| Erro retornado pela API
|--------------------------------------------------------------------------
*/

if ($httpCode < 200 || $httpCode >= 300) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'error' => 'Mercado Pago retornou um erro.',
        'http_code' => $httpCode,
        'payment_id' => $paymentId
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Dados do pagamento
|--------------------------------------------------------------------------
*/

$status = $payment['status'] ?? 'unknown';

$statusDetail = $payment['status_detail'] ?? null;

$transactionAmount = isset($payment['transaction_amount'])
    ? (float) $payment['transaction_amount']
    : 0;

$externalReference = $payment['external_reference'] ?? null;


/*
|--------------------------------------------------------------------------
| PAGAMENTO APROVADO
|--------------------------------------------------------------------------
|
| Aqui é onde sabemos que o Mercado Pago realmente aprovou
| o pagamento.
|
| IMPORTANTE:
|
| Não coloque Purchase simplesmente porque o webhook foi recebido.
|
| O Purchase deve ocorrer quando:
|
| $status === 'approved'
|
| O seu carrinho.html já faz esse controle através do
| consultar_pix.php.
|
|--------------------------------------------------------------------------
*/

if ($status === 'approved') {

    /*
     * Aqui futuramente podemos:
     *
     * - registrar o pedido
     * - salvar em arquivo
     * - enviar e-mail
     * - atualizar estoque
     * - liberar produto
     * - registrar conversão
     *
     * Como estamos trabalhando sem banco de dados,
     * não vamos criar nenhuma dessas operações agora.
     */
}


/*
|--------------------------------------------------------------------------
| Resposta para o Mercado Pago
|--------------------------------------------------------------------------
*/

http_response_code(200);

echo json_encode([
    'success' => true,

    'message' => 'Webhook recebido com sucesso.',

    'payment_id' => $paymentId,

    'status' => $status,

    'status_detail' => $statusDetail,

    'transaction_amount' => $transactionAmount,

    'external_reference' => $externalReference
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

exit;