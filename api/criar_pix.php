<?php

// Desativa a exibição de erros/avisos HTML para não sujar a resposta JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/config.php';

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR CONFIGURAÇÕES
    |--------------------------------------------------------------------------
    */
    if (!defined('MP_ACCESS_TOKEN') || empty(MP_ACCESS_TOKEN) || MP_ACCESS_TOKEN === 'oculto') {
        throw new Exception("Access Token do Mercado Pago não configurado corretamente em config.php.");
    }

    if (!defined('LOJA_NOME')) {
        define('LOJA_NOME', 'Rosa Shop');
    }

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
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Dados inválidos recebidos pelo servidor.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DADOS DO CLIENTE
    |--------------------------------------------------------------------------
    */
    $name = trim((string)($input['name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $address = $input['address'] ?? [];
    $items = $input['items'] ?? [];

    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES DO CLIENTE
    |--------------------------------------------------------------------------
    */
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe seu nome completo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um e-mail válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $phoneNumbers = preg_replace('/\D+/', '', $phone);
    if (!$phoneNumbers || strlen($phoneNumbers) < 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um telefone válido com DDD.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAR ENDEREÇO
    |--------------------------------------------------------------------------
    */
    if (!is_array($address)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Endereço inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $zipCode = trim((string)($address['zip_code'] ?? ''));
    $streetName = trim((string)($address['street_name'] ?? ''));
    $streetNumber = trim((string)($address['street_number'] ?? ''));
    $complement = trim((string)($address['complement'] ?? ''));
    $neighborhood = trim((string)($address['neighborhood'] ?? ''));
    $city = trim((string)($address['city'] ?? ''));
    $federalUnit = strtoupper(trim((string)($address['federal_unit'] ?? '')));

    if ($zipCode === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe o CEP.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($streetName === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe a rua.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($neighborhood === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe o bairro.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($city === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe a cidade.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($federalUnit === '' || strlen($federalUnit) !== 2) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe o estado (UF) corretamente com 2 letras.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDAR CARRINHO E CATÁLOGO
    |--------------------------------------------------------------------------
    */
    if (!is_array($items) || count($items) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'O carrinho está vazio.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $catalogo = [
        'produto1' => [
            'name' => 'Patinete Elétrico Cross Pro 10 - Freio Hidraulico',
            'price' => 89.90
        ],
        'produto2' => [
            'name' => 'Lava e Seca 11kg Hisense 11 Programas de Lavagem, Steam, Wi-fi Titanium',
            'price' => 299.90
        ],
        'produto3' => [
            'name' => 'Apple iPhone 15 (Vitrine Premium)',
            'price' => 599.90
        ],
        'produto4' => [
            'name' => 'Jogo de Panelas Antiaderente 10 Peças',
            'price' => 87.90
        ],
        'produto5' => [
            'name' => 'Scooter Elétrica TUI 1000w | 2 Lugares | Básico | Sem CNH | Autonomia 60km',
            'price' => 199.90
        ],
        'produto6' => [
            'name' => 'Kit completo de árvore de natal',
            'price' => 59.90
        ],
    ];

    $total = 0;
    $produtosValidados = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Item inválido no carrinho.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $id = trim((string)($item['id'] ?? ''));
        $quantity = (int)($item['quantity'] ?? 1);

        if ($id === '' || !isset($catalogo[$id])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Produto inválido ou não encontrado: ' . $id], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($quantity < 1) {
            $quantity = 1;
        }

        $produto = $catalogo[$id];
        $unitPrice = (float)$produto['price'];
        $subtotal = $unitPrice * $quantity;
        $total += $subtotal;

        $produtosValidados[] = [
            'id' => $id,
            'name' => $produto['name'],
            'price' => $unitPrice,
            'quantity' => $quantity,
            'subtotal' => round($subtotal, 2)
        ];
    }

    $total = round($total, 2);

    if ($total <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valor total do pedido inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | SEPARAR NOME E TELEFONE
    |--------------------------------------------------------------------------
    */
    $nameParts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $firstName = $nameParts[0] ?? $name;
    $lastName = '';

    if (count($nameParts) > 1) {
        array_shift($nameParts);
        $lastName = implode(' ', $nameParts);
    }

    $areaCode = '';
    $phoneNumber = $phoneNumbers;

    if (strlen($phoneNumbers) >= 10) {
        $areaCode = substr($phoneNumbers, 0, 2);
        $phoneNumber = substr($phoneNumbers, 2);
    }

    $zipCodeClean = preg_replace('/\D+/', '', $zipCode);
    $streetNumberClean = preg_replace('/\D+/', '', $streetNumber);
    if ($streetNumberClean === '') {
        $streetNumberClean = '0';
    }

    /*
    |--------------------------------------------------------------------------
    | REFERÊNCIAS
    |--------------------------------------------------------------------------
    */
    $randomReference = strtoupper(bin2hex(random_bytes(4)));
    $externalReference = 'ROSA-' . date('YmdHis') . '-' . $randomReference;
    $idempotencyKey = bin2hex(random_bytes(16));

    /*
    |--------------------------------------------------------------------------
    | PAYLOAD DO MERCADO PAGO
    |--------------------------------------------------------------------------
    */
    $paymentData = [
        'transaction_amount' => $total,
        'description' => LOJA_NOME . ' - Pedido ' . $externalReference,
        'payment_method_id' => 'pix',
        'external_reference' => $externalReference,
        'payer' => [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => [
                'area_code' => $areaCode,
                'number' => $phoneNumber
            ],
            'address' => [
                'zip_code' => $zipCodeClean,
                'street_name' => $streetName,
                'street_number' => $streetNumberClean,
                'neighborhood' => $neighborhood,
                'city' => $city,
                'federal_unit' => $federalUnit
            ]
        ]
    ];

    if ($complement !== '') {
        $paymentData['payer']['address']['complement'] = $complement;
    }

    /*
    |--------------------------------------------------------------------------
    | REQUISIÇÃO CURL MERCADO PAGO
    |--------------------------------------------------------------------------
    */
    $ch = curl_init('https://api.mercadopago.com/v1/payments');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($paymentData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . MP_ACCESS_TOKEN,
            'X-Idempotency-Key: ' . $idempotencyKey
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        throw new Exception("Erro de conexão cURL com o Mercado Pago: " . $curlError);
    }

    $result = json_decode($response, true);

    if (!is_array($result)) {
        throw new Exception("Resposta inválida ou vazia recebida da API do Mercado Pago.");
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        http_response_code($httpCode ?: 500);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Não foi possível criar o pagamento no Mercado Pago.',
            'status' => $result['status'] ?? null,
            'cause' => $result['cause'] ?? null
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | EXTRAIR E RETORNAR DADOS DO PIX
    |--------------------------------------------------------------------------
    */
    $transactionData = $result['point_of_interaction']['transaction_data'] ?? [];
    $paymentId = $result['id'] ?? null;
    $qrCode = $transactionData['qr_code'] ?? null;
    $qrCodeBase64 = $transactionData['qr_code_base64'] ?? null;

    if (!$paymentId || (!$qrCode && !$qrCodeBase64)) {
        throw new Exception("O Mercado Pago processou o pedido mas não retornou o QR Code do Pix.");
    }

    echo json_encode([
        'success' => true,
        'payment_id' => $paymentId,
        'status' => $result['status'] ?? null,
        'external_reference' => $externalReference,
        'amount' => $total,
        'qr_code' => $qrCode,
        'qr_code_base64' => $qrCodeBase64,
        'ticket_url' => $transactionData['ticket_url'] ?? null,
        'products' => $produtosValidados
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno no servidor PHP.',
        'error_detail' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}