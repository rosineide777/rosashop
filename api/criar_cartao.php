<?php

// Desativa a exibição de erros/avisos HTML para não sujar a resposta JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

/**
 * Função para validar os dígitos verificadores do CPF
 */
function validarCPF(string $cpf): bool {
    $cpf = preg_replace('/\D+/', '', $cpf);
    
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += (int)$cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int)$cpf[$c] !== $d) {
            return false;
        }
    }

    return true;
}

try {
    require_once __DIR__ . '/config.php';

    if (!defined('MP_ACCESS_TOKEN') || empty(MP_ACCESS_TOKEN) || MP_ACCESS_TOKEN === 'oculto') {
        throw new Exception("Access Token do Mercado Pago não configurado em config.php.");
    }

    if (!defined('LOJA_NOME')) {
        define('LOJA_NOME', 'Rosa Shop');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Dados inválidos recebidos pelo servidor.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Dados do Pagamento por Cartão
    $token = trim((string)($input['token'] ?? ''));
    $paymentMethodId = trim((string)($input['payment_method_id'] ?? 'visa'));
    $installments = (int)($input['installments'] ?? 1);
    $issuerId = !empty($input['issuer_id']) ? (string)$input['issuer_id'] : null;
    $deviceId = trim((string)($input['device_id'] ?? ''));

    if (empty($token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token do cartão não fornecido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Dados do Comprador
    $name = trim((string)($input['name'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $phone = trim((string)($input['phone'] ?? ''));
    $cpf = preg_replace('/\D+/', '', (string)($input['cpf'] ?? ''));
    $items = $input['items'] ?? [];

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

    if (!validarCPF($cpf)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um CPF válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // CATÁLOGO IDÊNTICO AO CRIAR_PIX.PHP
    $catalogo = [
        'produto1' => ['name' => 'Jogo de Panelas Antiaderente 10 Peças', 'price' => 87.90],
        'produto2' => ['name' => 'Lava e Seca 11kg Hisense 11 Programas de Lavagem, Steam, Wi-fi Titanium', 'price' => 299.90],
        'produto3' => ['name' => 'Apple iPhone 15 (Vitrine Premium)', 'price' => 599.90],
        'produto4' => ['name' => 'Patinete Elétrico Cross Pro 10 - Freio Hidraulico', 'price' => 499.90],
        'produto5' => ['name' => 'Scooter Elétrica TUI 1000w | 2 Lugares | Básico | Sem CNH | Autonomia 60km', 'price' => 699.90],
        'produto6' => ['name' => 'Kit completo de árvore de natal', 'price' => 59.90],
        'produto7' => ['name' => 'Chuveiro Acqua Duo Lorenzetti', 'price' => 69.90],
        'produto8' => ['name' => 'Bicicleta Aro 29 GT Sprint MX7 24V index Freio Disco Alumínio Suspensão Aero MTB', 'price' => 399.90],
        'produto9' => ['name' => 'Sofá 3 Lugares Retrátil e Reclinável Cama inBox Senses 2,00m Velusoft Cinza ', 'price' => 299.90],
        'produto10' => ['name' => 'Cooktop Itatiaia Essencial 4 Bocas cor Preto', 'price' => 99.90],
        'produto11' => ['name' => 'Jogo de Panelas Cerâmica Kit 5 Peças Antiaderente Tampa Vidro', 'price' => 49.90],
        'produto12' => ['name' => 'Smart Tv Samsung 85 - 4k Crystal Ultra Hd Led Lh85befhSmart Tv Samsung 85 - 4k Crystal Ultra Hd Led Lh85befh', 'price' => 799.90],
        'produto13' => ['name' => 'Terapia Ortopédica 4 em 1 para Joelho', 'price' => 99.90],
        'produto14' => ['name' => 'iPhone 17 Pro Max 256GB', 'price' => 999.90],
    ];

    if (!is_array($items) || count($items) === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'O carrinho está vazio.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $total = 0;
    $produtosValidados = [];
    $mpItems = [];

    foreach ($items as $item) {
        if (!is_array($item)) continue;

        $id = trim((string)($item['id'] ?? ''));
        $quantity = (int)($item['quantity'] ?? 1);

        if ($id === '' || !isset($catalogo[$id])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Produto inválido ou não encontrado: ' . $id], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($quantity < 1) $quantity = 1;

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

        // Itens formatados para o antifraude do Mercado Pago
        $mpItems[] = [
            'id' => $id,
            'title' => $produto['name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice
        ];
    }

    $total = round($total, 2);

    if ($total <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valor total do pedido inválido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

        // Tratamento de Nome e Telefone
    $nameParts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $firstName = $nameParts[0] ?? $name;
    $lastName = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : 'Cliente';

    $phoneNumbers = preg_replace('/\D+/', '', $phone);
    $areaCode = strlen($phoneNumbers) >= 10 ? substr($phoneNumbers, 0, 2) : '';
    $phoneNumber = strlen($phoneNumbers) >= 10 ? substr($phoneNumbers, 2) : $phoneNumbers;

    // --- ATUALIZAÇÃO BEMOB: CAPTURA O SUBID ENVIADO PELO JAVASCRIPT ---
    $subid = isset($input['subid']) ? trim((string)$input['subid']) : '';

    $randomReference = strtoupper(bin2hex(random_bytes(4)));
    
    // Concatenamos o subid na referência se ele existir
    if (!empty($subid)) {
        $externalReference = $subid . '___ROSA-CARD-' . date('YmdHis') . '-' . $randomReference;
    } else {
        $externalReference = 'ROSA-CARD-' . date('YmdHis') . '-' . $randomReference;
    }
    // -----------------------------------------------------------------
    
    $idempotencyKey = bin2hex(random_bytes(16));

    // Captura o IP do cliente para avaliação de risco
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
    if ($clientIp && strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    // Payload de Pagamento por Cartão no Mercado Pago
    $paymentData = [
        'transaction_amount' => $total,
        'token' => $token,
        'description' => LOJA_NOME . ' - Pedido ' . $externalReference,
        'installments' => $installments,
        'payment_method_id' => $paymentMethodId,
        'external_reference' => $externalReference,
        'payer' => [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'identification' => [
                'type' => 'CPF',
                'number' => $cpf
            ]
        ],
        'additional_info' => [
            'items' => $mpItems,
            'ip_address' => $clientIp
        ]
    ];

    if ($issuerId !== null) {
        $paymentData['issuer_id'] = $issuerId;
    }

    if ($areaCode && $phoneNumber) {
        $paymentData['payer']['phone'] = [
            'area_code' => $areaCode,
            'number' => $phoneNumber
        ];
    }

    // Configuração dos Cabeçalhos HTTP
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'X-Idempotency-Key: ' . $idempotencyKey
    ];

    // Se o Device ID veio do frontend, envia o cabeçalho antifraude do Mercado Pago
    if (!empty($deviceId)) {
        $headers[] = 'X-Melidata-Session-Id: ' . $deviceId;
    }

    // Requisição cURL
    $ch = curl_init('https://api.mercadopago.com/v1/payments');

    curl_setopt_array($ch, [
        CURLMock => false, // Linha padrão apenas para consistência, ignorar
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($paymentData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);

    // Remove item inválido se adicionado por engano na limpeza
    if(isset($curl_options[CURLMock])) { unset($curl_options[CURLMock]); }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        throw new Exception("Erro de conexão cURL com o Mercado Pago: " . $curlError);
    }

    $result = json_decode($response, true);

    if (!is_array($result)) {
        throw new Exception("Resposta inválida recebida da API do Mercado Pago.");
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        http_response_code($httpCode ?: 500);
        
        // Ajustado para evitar o erro de 'Notice: Undefined offset: 0' que ocorria no código original
        $erroDescricao = null;
        if (isset($result['cause']) && is_array($result['cause']) && isset($result['cause'][0]['description'])) {
            $erroDescricao = $result['cause'][0]['description'];
        } elseif (isset($result['cause']['description'])) {
            $erroDescricao = $result['cause']['description'];
        }

        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Não foi possível aprovar o pagamento no cartão.',
            'error_detail' => $erroDescricao ?? ($result['status_detail'] ?? null)
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'payment_id' => $result['id'] ?? null,
        'status' => $result['status'] ?? null,
        'status_detail' => $result['status_detail'] ?? null,
        'external_reference' => $externalReference,
        'amount' => $total,
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
