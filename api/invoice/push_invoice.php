<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/bkav.php';
header('Content-Type: application/json');
requireLogin();
requireRole('director', 'accountant');

define('BKAV_RAW_RESPONSE_MAX_LENGTH', 65535);

function bkav_log(string $msg): void {
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/erp/tmp';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/bkav_debug.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function sanitize(string $s, int $max): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s));
    return mb_substr($s, 0, $max, 'UTF-8');
}

function bkavEncrypt(string $data): string {
    $key = base64_decode(BKAV_AES_KEY);
    $iv  = base64_decode(BKAV_AES_IV);
    $gz  = gzencode($data, 6);
    $enc = openssl_encrypt($gz, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) throw new RuntimeException('openssl_encrypt thất bại: ' . openssl_error_string());
    return base64_encode($enc);
}

function bkavDecrypt(string $data): string {
    $key = base64_decode(BKAV_AES_KEY);
    $iv  = base64_decode(BKAV_AES_IV);
    $raw = base64_decode($data, true);
    if ($raw === false) throw new RuntimeException('base64_decode thất bại');
    $dec = openssl_decrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($dec === false) throw new RuntimeException('openssl_decrypt thất bại');
    $ungz = @gzdecode($dec);
    return $ungz !== false ? $ungz : $dec;
}

function bkavSoapCall(string $encData): string {
    $wsUrl = BKAV_WS_URL;
    $guid  = BKAV_PARTNER_GUID;
    $isProduction = defined('BKAV_ENV') && BKAV_ENV === 'production';

    $soapBody = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <ExecuteCommand xmlns="http://tempuri.org/">
      <PartnerGUID>' . htmlspecialchars($guid, ENT_XML1) . '</PartnerGUID>
      <EncryptedCommandData>' . htmlspecialchars($encData, ENT_XML1) . '</EncryptedCommandData>
    </ExecuteCommand>
  </soap:Body>
</soap:Envelope>';

    $ch = curl_init($wsUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $soapBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "http://tempuri.org/ExecuteCommand"',
            'Content-Length: ' . strlen($soapBody),
        ],
        CURLOPT_CONNECTTIMEOUT => (int)(defined('BKAV_TIMEOUT_CONNECT') ? BKAV_TIMEOUT_CONNECT : 10),
        CURLOPT_TIMEOUT        => (int)(defined('BKAV_TIMEOUT_READ')    ? BKAV_TIMEOUT_READ    : 60),
        CURLOPT_SSL_VERIFYPEER => $isProduction,
        CURLOPT_SSL_VERIFYHOST => $isProduction ? 2 : 0,
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) throw new RuntimeException('cURL error: ' . $err);
    if ($httpCode !== 200) {
        bkav_log("HTTP {$httpCode}: " . substr((string)$response, 0, 1000));
        throw new RuntimeException("BKAV trả HTTP {$httpCode}");
    }
    return (string)$response;
}

function numberToWordsVN(float $amount): string {
    $amount = (int) round($amount);
    if ($amount === 0) return 'Không đồng';
    $ones  = ['', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
    $teens = ['mười', 'mười một', 'mười hai', 'mười ba', 'mười bốn', 'mười lăm',
              'mười sáu', 'mười bảy', 'mười tám', 'mười chín'];
    $readThree = function(int $n) use ($ones, $teens, &$readThree): string {
        if ($n === 0) return '';
        if ($n < 10) return $ones[$n];
        if ($n < 20) return $teens[$n - 10];
        if ($n < 100) {
            $r = $ones[(int)($n / 10)] . ' mươi'; $u = $n % 10;
            if ($u === 5) $r .= ' lăm'; elseif ($u === 1) $r .= ' mốt'; elseif ($u > 0) $r .= ' ' . $ones[$u];
            return $r;
        }
        $r = $ones[(int)($n / 100)] . ' trăm'; $rem = $n % 100;
        if ($rem > 0 && $rem < 10) $r .= ' linh ' . $ones[$rem];
        elseif ($rem > 0) $r .= ' ' . $readThree($rem);
        return $r;
    };
    $units = ['', ' nghìn', ' triệu', ' tỷ']; $groups = [];
    while ($amount > 0) { $groups[] = $amount % 1000; $amount = (int)($amount / 1000); }
    $parts = [];
    foreach (array_reverse($groups, true) as $i => $g) {
        if ($g > 0) $parts[] = $readThree($g) . $units[$i];
    }
    return ucfirst(implode(' ', $parts)) . ' đồng';
}

// ── Parse XML response BKAV ────────────────────────────────────────────
function parseBkavResponse(string $rawXml): array {
    $rawXml  = preg_replace('/^\xEF\xBB\xBF/', '', trim($rawXml));
    $jsonStr = null;

    // Cách 1: SimpleXML
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string($rawXml);
    if ($xml instanceof SimpleXMLElement) {
        $nodes = $xml->xpath('//*[local-name()="ExecuteCommandResult"]');
        if (!empty($nodes)) $jsonStr = trim((string)$nodes[0]);
    }
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    // Cách 2: DOMDocument
    if ($jsonStr === null || $jsonStr === '') {
        $dom = new DOMDocument();
        if (@$dom->loadXML($rawXml)) {
            $nl = $dom->getElementsByTagNameNS('http://tempuri.org/', 'ExecuteCommandResult');
            if ($nl->length === 0) $nl = $dom->getElementsByTagName('ExecuteCommandResult');
            if ($nl->length > 0) $jsonStr = trim($nl->item(0)->textContent);
        }
    }

    // Cách 3: Regex
    if ($jsonStr === null || $jsonStr === '') {
        if (preg_match('/<ExecuteCommandResult[^>]*>(.*?)<\/ExecuteCommandResult>/s', $rawXml, $m)) {
            $jsonStr = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
    }

    if ($jsonStr === null || $jsonStr === '') {
        throw new RuntimeException('Không thể parse ExecuteCommandResult. Raw (500): ' . substr($rawXml, 0, 500));
    }

    bkav_log("ExecuteCommandResult (300): " . substr($jsonStr, 0, 300));

    // --- Thử 1: JSON trực tiếp (BKAV đôi khi trả plain JSON lỗi)
    $data = json_decode($jsonStr, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        return $data;
    }

    // --- Thử 2: Base64 decode rồi JSON (không decrypt)
    $b64 = base64_decode($jsonStr, true);
    if ($b64 !== false) {
        // Thử JSON sau khi base64 decode (không gzip)
        $d = json_decode($b64, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($d)) {
            bkav_log("Parsed via base64+JSON (no gzip)");
            return $d;
        }
        // Thử gunzip sau base64
        $ungz = @gzdecode($b64);
        if ($ungz !== false) {
            $d = json_decode($ungz, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($d)) {
                bkav_log("Parsed via base64+gzdecode+JSON");
                return $d;
            }
        }
    }

    // --- Thử 3: Decrypt AES rồi JSON (chuẩn BKAV)
    try {
        $dec  = bkavDecrypt($jsonStr);
        bkav_log('Decrypted (300): ' . substr($dec, 0, 300));
        $data = json_decode($dec, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }
        throw new RuntimeException('Decrypted nhưng không phải JSON hợp lệ. Nội dung: ' . substr($dec, 0, 200));
    } catch (Throwable $e) {
        // Nếu decrypt fail, trả raw để debug
        throw new RuntimeException(
            'Không thể parse response BKAV. ' . $e->getMessage() .
            ' | ResultRaw (200): ' . substr($jsonStr, 0, 200)
        );
    }
}

function getInvoiceData(PDO $pdo, int $invoiceId): ?array {
    $stmt = $pdo->prepare("
        SELECT i.*, c.customer_name, c.address, c.tax_code
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return null;
    $stmt2 = $pdo->prepare("
        SELECT ii.*, pc.product_code
        FROM invoice_items ii
        JOIN product_codes pc ON ii.product_code_id = pc.id
        WHERE ii.invoice_id = ? ORDER BY ii.id
    ");
    $stmt2->execute([$invoiceId]);
    $inv['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    return $inv;
}

function buildBkavPayload(array $inv): array {
    $vatRate    = (float)($inv['vat_rate'] ?? 0);
    $vatPct     = (int)round($vatRate);
    $vatDecimal = $vatPct / 100;
    $grandTotal   = (float)($inv['subtotal']     ?? 0);
    $totalTax     = (float)($inv['vat_amount']   ?? 0);
    $grandWithTax = (float)($inv['total_amount'] ?? 0);

    $itemList = []; $sumTax = 0; $cnt = count($inv['items']);
    foreach ($inv['items'] as $idx => $it) {
        $lineAmt = (int)round((float)$it['total_price']);
        $lineTax = ($idx < $cnt - 1)
            ? (int)round($lineAmt * $vatDecimal)
            : (int)$totalTax - $sumTax;
        $sumTax += $lineTax;
        $itemList[] = [
            'ItemName'                  => sanitize((string)($it['description'] ?? ''), 150),
            'UnitName'                  => sanitize((string)($it['unit']        ?? ''), 20),
            'UnitPrice'                 => (float)$it['unit_price'],
            'Quantity'                  => (float)$it['quantity'],
            'ItemTotalAmountWithoutTax' => $lineAmt,
            'TaxPercentage'             => $vatPct,
            'TaxAmount'                 => $lineTax,
            'ItemTotalAmount'           => $lineAmt + $lineTax,
            'IsIncreaseItem'            => true,
        ];
    }

    return [
        'CmdType' => 100,
        'Invoice' => [
            'InvoiceType'                  => BKAV_INVOICE_TYPE,
            'InvoiceTemplateCode'          => BKAV_INVOICE_TEMPLATE,
            'InvoiceSerial'                => BKAV_INVOICE_SERIAL,
            'InvoiceIssuedDate'            => date('Y-m-d\TH:i:s', strtotime($inv['invoice_date'] ?? 'now')),
            'CurrencyUnit'                 => 'VND',
            'ExchangeRate'                 => 1,
            'PaymentMethodName'            => BKAV_PAYMENT_METHOD,
            'BuyerName'                    => sanitize($inv['customer_name'] ?? '', 120),
            'BuyerTaxCode'                 => sanitize($inv['tax_code']      ?? '', 50),
            'BuyerUnitName'                => sanitize($inv['customer_name'] ?? '', 120),
            'BuyerAddress'                 => sanitize($inv['address']       ?? '', 200),
            'SellerTaxCode'                => defined('COMPANY_TAX')     ? COMPANY_TAX     : '',
            'SellerLegalName'              => sanitize(defined('COMPANY_NAME')    ? COMPANY_NAME    : '', 200),
            'SellerAddress'                => sanitize(defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : '', 250),
            'SellerBankAccount'            => defined('COMPANY_ACCOUNT') ? preg_replace('/[^0-9]/', '', COMPANY_ACCOUNT) : '',
            'SellerBankName'               => sanitize(defined('COMPANY_BANK') ? COMPANY_BANK : '', 150),
            'InvoiceGroupItemList'         => $itemList,
            'InvoiceTaxBreakdowns'         => [[
                'TaxPercentage' => $vatPct,
                'TaxableAmount' => (int)$grandTotal,
                'TaxAmount'     => (int)$totalTax,
            ]],
            'InvoiceTotalAmountWithoutTax' => (int)$grandTotal,
            'InvoiceTotalTaxAmount'        => (int)$totalTax,
            'InvoiceTotalAmount'           => (int)$grandWithTax,
            'InvoiceTotalAmountInWords'    => numberToWordsVN($grandWithTax),
        ],
    ];
}

// ════════════════════════════════════════════════════════
$pdo    = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['preview'])) {
    $invoiceId = (int)($_GET['invoice_id'] ?? 0);
    if (!$invoiceId) { echo json_encode(['success' => false, 'message' => 'Thiếu invoice_id']); exit; }
    $inv = getInvoiceData($pdo, $invoiceId);
    if (!$inv) { echo json_encode(['success' => false, 'message' => 'Không tìm thấy hoá đơn']); exit; }
    $payload = buildBkavPayload($inv);
    $inv2    = $payload['Invoice'];
    echo json_encode([
        'success' => true,
        'preview' => [
            'invoice_no'     => $inv['invoice_no'],
            'invoice_date'   => $inv['invoice_date'],
            'customer_name'  => $inv['customer_name'],
            'address'        => $inv['address']  ?? '',
            'tax_code'       => $inv['tax_code'] ?? '',
            'subtotal'       => $inv2['InvoiceTotalAmountWithoutTax'],
            'vat_rate'       => $inv['vat_rate'],
            'vat_amount'     => $inv2['InvoiceTotalTaxAmount'],
            'total_amount'   => $inv2['InvoiceTotalAmount'],
            'total_words'    => $inv2['InvoiceTotalAmountInWords'],
            'items'          => $inv['items'],
            'seller_name'    => $inv2['SellerLegalName'],
            'seller_tax'     => $inv2['SellerTaxCode'],
            'seller_address' => $inv2['SellerAddress'],
            'bkav_invoice_no'=> $inv['bkav_invoice_no'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) { echo json_encode(['success' => false, 'message' => 'Request body không hợp lệ']); exit; }
    if (!verifyCSRF($body['csrf_token'] ?? '')) { echo json_encode(['success' => false, 'message' => 'CSRF không hợp lệ']); exit; }
    $invoiceId = (int)($body['invoice_id'] ?? 0);
    if (!$invoiceId) { echo json_encode(['success' => false, 'message' => 'Thiếu invoice_id']); exit; }
    $inv = getInvoiceData($pdo, $invoiceId);
    if (!$inv) { echo json_encode(['success' => false, 'message' => 'Không tìm thấy hoá đơn']); exit; }

    try {
        $payload  = buildBkavPayload($inv);
        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encData  = bkavEncrypt($jsonData);

        bkav_log("=== START invoice_id={$invoiceId} env=" . BKAV_ENV);
        bkav_log("Payload (500): " . substr($jsonData, 0, 500));

        $rawXml = bkavSoapCall($encData);
        bkav_log("Raw SOAP (800): " . substr($rawXml, 0, 800));

        $result = parseBkavResponse($rawXml);
        bkav_log("Parsed: " . json_encode($result, JSON_UNESCAPED_UNICODE));

        $status    = $result['Status'] ?? $result['ErrorCode'] ?? $result['error_code'] ?? null;
        $isSuccess = ($status === 0 || $status === '0');

        $bkavInvoiceNo = $result['InvoiceNo']
            ?? $result['invoiceNo']
            ?? ($result['Object']['InvoiceNo'] ?? null)
            ?? ($result['data']['InvoiceNo']   ?? null);

        if (!$isSuccess) {
            $errMsg = $result['Object'] ?? $result['Description'] ?? $result['Message'] ?? $result['message'] ?? json_encode($result, JSON_UNESCAPED_UNICODE);
            if (is_array($errMsg)) $errMsg = json_encode($errMsg, JSON_UNESCAPED_UNICODE);
            bkav_log("BKAV error: " . $errMsg);
            $pdo->prepare("UPDATE invoices SET bkav_status='error', bkav_raw_response=? WHERE id=?")
                ->execute([substr($rawXml, 0, BKAV_RAW_RESPONSE_MAX_LENGTH), $invoiceId]);
            echo json_encode(['success' => false, 'message' => 'BKAV trả lỗi: ' . $errMsg], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->prepare("UPDATE invoices SET bkav_invoice_no=?, bkav_status='issued', bkav_issued_at=NOW(), bkav_raw_response=? WHERE id=?")
            ->execute([$bkavInvoiceNo, substr($rawXml, 0, BKAV_RAW_RESPONSE_MAX_LENGTH), $invoiceId]);

        bkav_log("Success: bkav_invoice_no={$bkavInvoiceNo}");
        echo json_encode(['success' => true, 'invoiceNo' => $bkavInvoiceNo, 'message' => 'Xuất hoá đơn thành công'], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        bkav_log("Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ'], JSON_UNESCAPED_UNICODE);
