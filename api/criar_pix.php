<?php

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

/*
|--------------------------------------------------------------------------
| SOMENTE POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| LER JSON
|--------------------------------------------------------------------------
*/

$rawInput = file_get_contents('php://input');

$input = json_decode(
    $rawInput,
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Dados inválidos.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| DADOS DO CLIENTE
|--------------------------------------------------------------------------
*/

$name = trim(
    (string)($input['name'] ?? '')
);

$email = trim(
    (string)($input['email'] ?? '')
);

$phone = trim(
    (string)($input['phone'] ?? '')
);

$address = $input['address'] ?? [];

$items = $input['items'] ?? [];

/*
|--------------------------------------------------------------------------
| VALIDAR NOME
|--------------------------------------------------------------------------
*/

if ($name === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe seu nome completo.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDAR E-MAIL
|--------------------------------------------------------------------------
*/

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe um e-mail válido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDAR TELEFONE
|--------------------------------------------------------------------------
*/

$phoneNumbers = preg_replace(
    '/\D+/',
    '',
    $phone
);

if (
    !$phoneNumbers ||
    strlen($phoneNumbers) < 10
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe um telefone válido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDAR ENDEREÇO
|--------------------------------------------------------------------------
*/

if (!is_array($address)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Endereço inválido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$zipCode = trim(
    (string)($address['zip_code'] ?? '')
);

$streetName = trim(
    (string)($address['street_name'] ?? '')
);

$streetNumber = trim(
    (string)($address['street_number'] ?? '')
);

$complement = trim(
    (string)($address['complement'] ?? '')
);

$neighborhood = trim(
    (string)($address['neighborhood'] ?? '')
);

$city = trim(
    (string)($address['city'] ?? '')
);

$federalUnit = strtoupper(
    trim(
        (string)($address['federal_unit'] ?? '')
    )
);

if ($zipCode === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe o CEP.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($streetName === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe a rua.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($streetNumber === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe o número do endereço.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($neighborhood === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe o bairro.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if ($city === '') {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe a cidade.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (
    $federalUnit === '' ||
    strlen($federalUnit) !== 2
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Informe o estado corretamente.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| VALIDAR CARRINHO
|--------------------------------------------------------------------------
*/

if (
    !is_array($items) ||
    count($items) === 0
) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'O carrinho está vazio.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| CATÁLOGO DO SERVIDOR
|--------------------------------------------------------------------------
|
| IMPORTANTE:
|
| O preço enviado pelo navegador NÃO é utilizado.
|
| O servidor define o preço verdadeiro de cada produto.
|
| Para adicionar novos produtos no futuro, basta adicionar
| outro item neste catálogo.
|
*/

$catalogo = [

    'produto1' => [
        'name' => 'Patinete Elétrico Cross Pro 10 - Freio Hidraulico',
        'price' => 89.90
    ],

    'produto2' => [
        'name' => 'Scooter Elétrica TUI 1000w | 2 Lugares | Básico | Sem CNH | Autonomia 60km',
        'price' => 199.90
    ],

    'produto3' => [
        'name' => 'Apple iPhone 15 (Vitrine Premium)',
        'price' => 599.90
    ],

    'produto4' => [
        'name' => 'Jogo de Panelas Antiaderente 10 Peças',
        'price' => 87.90
    ]

];

/*
|--------------------------------------------------------------------------
| VALIDAR PRODUTOS
|--------------------------------------------------------------------------
*/

$total = 0;

$produtosValidados = [];

foreach ($items as $item) {

    if (!is_array($item)) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Item inválido no carrinho.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $id = trim(
        (string)($item['id'] ?? '')
    );

    $quantity = (int)(
        $item['quantity'] ?? 1
    );

    /*
    |--------------------------------------------------------------------------
    | ID OBRIGATÓRIO
    |--------------------------------------------------------------------------
    */

    if ($id === '') {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Produto sem identificação.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | QUANTIDADE
    |--------------------------------------------------------------------------
    */

    if ($quantity < 1) {
        $quantity = 1;
    }

    /*
    |--------------------------------------------------------------------------
    | LIMITE DE SEGURANÇA
    |--------------------------------------------------------------------------
    */

    if ($quantity > 100) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Quantidade de produto inválida.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | PRODUTO PRECISA EXISTIR NO CATÁLOGO
    |--------------------------------------------------------------------------
    */

    if (!isset($catalogo[$id])) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Produto inválido: ' . $id
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $produto = $catalogo[$id];

    /*
    |--------------------------------------------------------------------------
    | PREÇO DEFINIDO PELO SERVIDOR
    |--------------------------------------------------------------------------
    */

    $unitPrice = (float)$produto['price'];

    /*
    |--------------------------------------------------------------------------
    | VALOR DO PRODUTO
    |--------------------------------------------------------------------------
    */

    if ($unitPrice <= 0) {
        http_response_code(400);

        echo json_encode([
            'success' => false,
            'message' => 'Preço não configurado para o produto: ' . $id
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

    $subtotal = $unitPrice * $quantity;

    $total += $subtotal;

    $produtosValidados[] = [
        'id' => $id,

        'name' => $produto['name'],

        'price' => $unitPrice,

        'quantity' => $quantity,

        'subtotal' => round(
            $subtotal,
            2
        )
    ];
}

/*
|--------------------------------------------------------------------------
| ARREDONDAR TOTAL
|--------------------------------------------------------------------------
*/

$total = round(
    $total,
    2
);

if ($total <= 0) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Valor inválido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| SEPARAR NOME E SOBRENOME
|--------------------------------------------------------------------------
*/

$nameParts = preg_split(
    '/\s+/',
    $name,
    -1,
    PREG_SPLIT_NO_EMPTY
);

$firstName =
    $nameParts[0]
    ?? $name;

$lastName = '';

if (count($nameParts) > 1) {

    array_shift(
        $nameParts
    );

    $lastName =
        implode(
            ' ',
            $nameParts
        );
}

/*
|--------------------------------------------------------------------------
| TELEFONE
|--------------------------------------------------------------------------
|
| O Mercado Pago recebe DDD separado do número.
|
*/

$areaCode = '';

$phoneNumber = $phoneNumbers;

if (strlen($phoneNumbers) >= 10) {

    $areaCode =
        substr(
            $phoneNumbers,
            0,
            2
        );

    $phoneNumber =
        substr(
            $phoneNumbers,
            2
        );
}

/*
|--------------------------------------------------------------------------
| LIMPAR CEP
|--------------------------------------------------------------------------
*/

$zipCodeClean = preg_replace(
    '/\D+/',
    '',
    $zipCode
);

/*
|--------------------------------------------------------------------------
| LIMPAR NÚMERO DO ENDEREÇO
|--------------------------------------------------------------------------
*/

$streetNumberClean = preg_replace(
    '/\D+/',
    '',
    $streetNumber
);

if ($streetNumberClean === '') {

    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Número do endereço inválido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| REFERÊNCIA DO PEDIDO
|--------------------------------------------------------------------------
*/

try {

    $randomReference =
        strtoupper(
            bin2hex(
                random_bytes(4)
            )
        );

} catch (Exception $e) {

    $randomReference =
        strtoupper(
            substr(
                md5(
                    uniqid(
                        '',
                        true
                    )
                ),
                0,
                8
            )
        );
}

$externalReference =
    'ROSA-' .
    date('YmdHis') .
    '-' .
    $randomReference;

/*
|--------------------------------------------------------------------------
| IDEMPOTENCY KEY
|--------------------------------------------------------------------------
|
| Evita criação duplicada do pagamento.
|
*/

try {

    $idempotencyKey =
        bin2hex(
            random_bytes(16)
        );

} catch (Exception $e) {

    $idempotencyKey =
        md5(
            uniqid(
                '',
                true
            )
        );
}

/*
|--------------------------------------------------------------------------
| DADOS DO PAGAMENTO
|--------------------------------------------------------------------------
*/

$paymentData = [

    'transaction_amount' =>
        $total,

    'description' =>
        LOJA_NOME .
        ' - Pedido ' .
        $externalReference,

    'payment_method_id' =>
        'pix',

    'external_reference' =>
        $externalReference,

    'payer' => [

        'email' =>
            $email,

        'first_name' =>
            $firstName,

        'last_name' =>
            $lastName,

        'phone' => [

            'area_code' =>
                $areaCode,

            'number' =>
                $phoneNumber
        ],

        'address' => [

            'zip_code' =>
                $zipCodeClean,

            'street_name' =>
                $streetName,

            'street_number' =>
                $streetNumberClean,

            'neighborhood' =>
                $neighborhood,

            'city' =>
                $city,

            'federal_unit' =>
                $federalUnit
        ]
    ]
];

/*
|--------------------------------------------------------------------------
| COMPLEMENTO
|--------------------------------------------------------------------------
*/

if ($complement !== '') {

    $paymentData['payer']['address']['complement'] =
        $complement;
}

/*
|--------------------------------------------------------------------------
| CRIAR PAGAMENTO NO MERCADO PAGO
|--------------------------------------------------------------------------
*/

$ch = curl_init(
    'https://api.mercadopago.com/v1/payments'
);

curl_setopt_array(
    $ch,
    [

        CURLOPT_RETURNTRANSFER =>
            true,

        CURLOPT_POST =>
            true,

        CURLOPT_POSTFIELDS =>
            json_encode(
                $paymentData,
                JSON_UNESCAPED_UNICODE
            ),

        CURLOPT_HTTPHEADER =>
            [

                'Accept: application/json',

                'Content-Type: application/json',

                'Authorization: Bearer ' .
                    MP_ACCESS_TOKEN,

                'X-Idempotency-Key: ' .
                    $idempotencyKey
            ],

        CURLOPT_TIMEOUT =>
            30
    ]
);

$response =
    curl_exec($ch);

$httpCode =
    curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

$curlError =
    curl_error($ch);

curl_close($ch);

/*
|--------------------------------------------------------------------------
| ERRO DE CONEXÃO
|--------------------------------------------------------------------------
*/

if (
    $response === false ||
    $curlError
) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Erro de conexão com o Mercado Pago.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| RESPOSTA DO MERCADO PAGO
|--------------------------------------------------------------------------
*/

$result =
    json_decode(
        $response,
        true
    );

if (!is_array($result)) {

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Resposta inválida do Mercado Pago.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| ERRO RETORNADO PELO MERCADO PAGO
|--------------------------------------------------------------------------
*/

if (
    $httpCode < 200 ||
    $httpCode >= 300
) {

    http_response_code(
        $httpCode ?: 500
    );

    echo json_encode([
        'success' => false,

        'message' =>
            $result['message']
            ??
            'Não foi possível criar o pagamento.',

        'status' =>
            $result['status']
            ?? null,

        'status_detail' =>
            $result['status_detail']
            ?? null,

        'error' =>
            $result['error']
            ?? null,

        'cause' =>
            $result['cause']
            ?? null

    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| DADOS DO PIX
|--------------------------------------------------------------------------
*/

$transactionData =
    $result[
        'point_of_interaction'
    ][
        'transaction_data'
    ]
    ?? [];

/*
|--------------------------------------------------------------------------
| VALIDAR RESPOSTA DO PIX
|--------------------------------------------------------------------------
*/

$paymentId =
    $result['id']
    ?? null;

$qrCode =
    $transactionData['qr_code']
    ?? null;

$qrCodeBase64 =
    $transactionData['qr_code_base64']
    ?? null;

$ticketUrl =
    $transactionData['ticket_url']
    ?? null;

if (!$paymentId) {

    http_response_code(500);

    echo json_encode([
        'success' => false,

        'message' =>
            'O Mercado Pago não retornou o ID do pagamento.'

    ], JSON_UNESCAPED_UNICODE);

    exit;
}

if (
    !$qrCode &&
    !$qrCodeBase64
) {

    http_response_code(500);

    echo json_encode([
        'success' => false,

        'message' =>
            'O Mercado Pago não retornou os dados do Pix.',

        'payment_id' =>
            $paymentId

    ], JSON_UNESCAPED_UNICODE);

    exit;
}

/*
|--------------------------------------------------------------------------
| RETORNO PARA O FRONT-END
|--------------------------------------------------------------------------
*/

echo json_encode([

    'success' =>
        true,

    'payment_id' =>
        $paymentId,

    'status' =>
        $result['status']
        ?? null,

    'status_detail' =>
        $result['status_detail']
        ?? null,

    'external_reference' =>
        $result['external_reference']
        ??
        $externalReference,

    'amount' =>
        $total,

    'transaction_amount' =>
        $result['transaction_amount']
        ??
        $total,

    'qr_code' =>
        $qrCode,

    'qr_code_base64' =>
        $qrCodeBase64,

    'ticket_url' =>
        $ticketUrl,

    'customer' => [

        'name' =>
            $name,

        'email' =>
            $email,

        'phone' =>
            $phone
    ],

    'address' => [

        'zip_code' =>
            $zipCode,

        'street_name' =>
            $streetName,

        'street_number' =>
            $streetNumber,

        'complement' =>
            $complement,

        'neighborhood' =>
            $neighborhood,

        'city' =>
            $city,

        'federal_unit' =>
            $federalUnit
    ],

    'products' =>
        $produtosValidados

], JSON_UNESCAPED_UNICODE);