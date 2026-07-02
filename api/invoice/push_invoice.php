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
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($dir . '/bkav_debug.log', $line, FILE_APPEND | LOCK_EX);
}

function sanitize(string $s, int $max): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s));
    return mb_substr($s, 0, $max, 'UTF-8');
}

function bkavEncrypt(string $data): string {
    $key  = base64_decode(BKAV_AES_KEY);
    $iv   = base64_decode(BKAV_AES_IV);
    $comp = gzcompress($data, 9);
    $enc  = openssl_encrypt($comp, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($enc === false) {
        throw new RuntimeException('openssl_encrypt thất bại: ' . openssl_error_string());
    }
    return base64_encode($enc);
}

function bkavDecrypt(string $data): string {
    $key  = base64_decode(BKAV_AES_KEY);
    $iv   = base64_decode(BKAV_AES_IV);
    $raw  = base64_decode($data);
    $dec  = openssl_decrypt($raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($dec === false) {
        throw new RuntimeException('openssl_decrypt thất bại');
    }
    $ungz = @gzuncompress($dec);
    return $ungz !== false ? $ungz : $dec;
}

function bkavSoapCall(string $encData): string {
    $wsUrl = BKAV_WS_URL;
    $guid  = BKAV_PARTNER_GUID;

    // Tắt SSL verify khi ở môi trường sandbox / XAMPP localhost
    // vì PHP không có CA bundle → "SSL certificate problem: unable to get local issuer certificate"
    $isProduction = defined('BKAV_ENV') && BKAV_ENV === 'production';

    $soapBody = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <ImportAndPublishInvoice xmlns="http://tempuri.org/">
      <strPartnerGUID>' . htmlspecialchars($guid, ENT_XML1) . '</strPartnerGUID>
      <strSendData>' . htmlspecialchars($encData, ENT_XML1) . '</strSendData>
    </ImportAndPublishInvoice>
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
            'SOAPAction: "http://tempuri.org/ImportAndPublishInvoice"',
            'Content-Length: ' . strlen($soapBody),
        ],
        CURLOPT_CONNECTTIMEOUT => (int)(defined('BKAV_TIMEOUT_CONNECT') ? BKAV_TIMEOUT_CONNECT : 10),
        CURLOPT_TIMEOUT        => (int)(defined('BKAV_TIMEOUT_READ')    ? BKAV_TIMEOUT_READ    : 60),
        // Production: verify SSL | Sandbox/dev: bỏ qua để tránh lỗi CA trên XAMPP
        CURLOPT_SSL_VERIFYPEER => $isProduction,
        CURLOPT_SSL_VERIFYHOST => $isProduction ? 2 : 0,
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('cURL error: ' . $err);
    }
    if ($httpCode !== 200) {
        bkav_log("HTTP {$httpCode}: " . substr((string)$response, 0, 500));
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
            $r = $ones[(int)($n / 10)] . ' mươi';
            $u = $n % 10;
            if ($u === 5)      $r .= ' lăm';
            elseif ($u === 1)  $r .= ' mốt';
            elseif ($u > 0)    $r .= ' ' . $ones[$u];
            return $r;
        }
        $r   = $ones[(int)($n / 100)] . ' trăm';
        $rem = $n % 100;
        if ($rem > 0 && $rem < 10) $r .= ' linh ' . $ones[$rem];
        elseif ($rem > 0)           $r .= ' ' . $readThree($rem);
        return $r;
    };

    $units  = ['', ' nghìn', ' triệu', ' tỷ'];
    $groups = [];
    while ($amount > 0) {
        $groups[] = $amount % 1000;
        $amount   = (int)($amount / 1000);
    }
    $parts = [];
    foreach (array_reverse($groups, true) as $i => $g) {
        if ($g > 0) $parts[] = $readThree($g) . $units[$i];
    }
    return ucfirst(implode(' ', $parts)) . ' đồng';
}

function parseBkavResponse(string $rawXml): array {
    $jsonStr = null;

    // Cách 1: SimpleXML xpath
    try {
        $xml = @simplexml_load_string($rawXml);
        if ($xml) {
            $nodes = $xml->xpath('//ImportAndPublishInvoiceResult') ?:
                     $xml->xpath('//*[local-name()="ImportAndPublishInvoiceResult"]');
            if (!empty($nodes)) {
                $jsonStr = (string) $nodes[0];
            }
        }
    } catch (Throwable $e) {
        bkav_log('SimpleXML parse error: ' . $e->getMessage());
    }

    // Cách 2: DOMDocument
    if ($jsonStr === null) {
        try {
            $dom = new DOMDocument();
            if (@$dom->loadXML($rawXml)) {
                $nodes = $dom->getElementsByTagName('ImportAndPublishInvoiceResult');
                if ($nodes->length > 0) {
                    $jsonStr = $nodes->item(0)->textContent;
                }
            }
        } catch (Throwable $e) {
            bkav_log('DOMDocument parse error: ' . $e->getMessage());
        }
    }

    // Cách 3: Regex fallback
    if ($jsonStr === null) {
        if (preg_match('/<ImportAndPublishInvoiceResult[^>]*>(.*?)<\/ImportAndPublishInvoiceResult>/s', $rawXml, $m)) {
            $jsonStr = html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
        }
    }

    if ($jsonStr === null) {
        throw new RuntimeException('Không thể parse XML response từ BKAV');
    }

    $data = json_decode($jsonStr, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        try {
            $decrypted = bkavDecrypt($jsonStr);
            $data = json_decode($decrypted, true);
        } catch (Throwable $e) {
            throw new RuntimeException('Không thể parse JSON response: ' . $e->getMessage());
        }
    }

    if (!is_array($data)) {
        throw new RuntimeException('Response không phải JSON hợp lệ');
    }
    return $data;
}

function getInvoiceData(PDO $pdo, int $invoiceId): ?array {
    $stmt = $pdo->prepare("
        SELECT i.*,
               c.customer_name, c.address, c.tax_code
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) return null;

    $stmtItems = $pdo->prepare("
        SELECT ii.*, pc.product_code
        FROM invoice_items ii
        JOIN product_codes pc ON ii.product_code_id = pc.id
        WHERE ii.invoice_id = ?
        ORDER BY ii.id
    ");
    $stmtItems->execute([$invoiceId]);
    $inv['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    return $inv;
}

function buildBkavPayload(array $inv): array {
    $vatRate = (float)($inv['vat_rate'] ?? 0);
    $items   = [];
    foreach ($inv['items'] as $idx => $it) {
        $items[] = [
            'ItemCode'     => sanitize($it['product_code'] ?? '', 50),
            'ItemName'     => sanitize($it['description']  ?? '', 500),
            'UnitName'     => sanitize($it['unit']         ?? '', 50),
            'Quantity'     => (float) $it['quantity'],
            'UnitPrice'    => (float) $it['unit_price'],
            'Amount'       => (float) $it['total_price'],
            'TaxRateValue' => $vatRate,
            'TaxAmount'    => round((float)$it['total_price'] * ($vatRate / 100)),
            'IsDiscount'   => false,
            'ItemOrder'    => $idx + 1,
        ];
    }

    return [
        'InvoiceType'                  => BKAV_INVOICE_TYPE,
        'InvoiceTemplate'              => BKAV_INVOICE_TEMPLATE,
        'InvoiceSerial'                => BKAV_INVOICE_SERIAL,
        'InvoiceDate'                  => date('Y-m-d\TH:i:s', strtotime($inv['invoice_date'] ?? 'now')),
        'PaymentMethodName'            => BKAV_PAYMENT_METHOD,
        'BuyerName'                    => sanitize($inv['customer_name'] ?? '', 200),
        'BuyerUnitName'                => sanitize($inv['customer_name'] ?? '', 200),
        'BuyerAddress'                 => sanitize($inv['address']       ?? '', 500),
        'BuyerTaxCode'                 => sanitize($inv['tax_code']      ?? '', 50),
        'InvoiceTotalAmountWithoutTax' => (float)($inv['subtotal']     ?? 0),
        'InvoiceTotalTaxAmount'        => (float)($inv['vat_amount']   ?? 0),
        'InvoiceTotalAmount'           => (float)($inv['total_amount'] ?? 0),
        'InvoiceTotalAmountInWords'    => numberToWordsVN((float)($inv['total_amount'] ?? 0)),
        'SellerTaxCode'                => defined('COMPANY_TAX')     ? COMPANY_TAX     : '',
        'SellerFullName'               => defined('COMPANY_NAME')    ? COMPANY_NAME    : '',
        'SellerAddress'                => defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : '',
        'SellerBankName'               => defined('COMPANY_BANK')    ? COMPANY_BANK    : '',
        'SellerBankAccount'            => defined('COMPANY_ACCOUNT') ? COMPANY_ACCOUNT : '',
        'InvoiceGroupItemList'         => $items,
        'ExternalRefNumber'            => sanitize($inv['invoice_no'] ?? '', 50),
    ];
}

// ════════════════════════════════════════════════════════
// MAIN
// ════════════════════════════════════════════════════════
$pdo    = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET preview ──────────────────────────────────────────
if ($method === 'GET' && isset($_GET['preview'])) {
    $invoiceId = (int)($_GET['invoice_id'] ?? 0);
    if (!$invoiceId) {
        echo json_encode(['success' => false, 'message' => 'Thiếu invoice_id']); exit;
    }

    $inv = getInvoiceData($pdo, $invoiceId);
    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy hoá đơn']); exit;
    }

    $payload = buildBkavPayload($inv);

    echo json_encode([
        'success' => true,
        'preview' => [
            'invoice_no'     => $inv['invoice_no'],
            'invoice_date'   => $inv['invoice_date'],
            'customer_name'  => $inv['customer_name'],
            'address'        => $inv['address']       ?? '',
            'tax_code'       => $inv['tax_code']      ?? '',
            'subtotal'       => $inv['subtotal'],
            'vat_rate'       => $inv['vat_rate'],
            'vat_amount'     => $inv['vat_amount'],
            'total_amount'   => $inv['total_amount'],
            'total_words'    => $payload['InvoiceTotalAmountInWords'],
            'items'          => $inv['items'],
            'seller_name'    => defined('COMPANY_NAME')    ? COMPANY_NAME    : '',
            'seller_tax'     => defined('COMPANY_TAX')     ? COMPANY_TAX     : '',
            'seller_address' => defined('COMPANY_ADDRESS') ? COMPANY_ADDRESS : '',
            'bkav_invoice_no'=> $inv['bkav_invoice_no'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST gửi BKAV ────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        echo json_encode(['success' => false, 'message' => 'Request body không hợp lệ']); exit;
    }

    if (!verifyCSRF($body['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'CSRF không hợp lệ']); exit;
    }

    $invoiceId = (int)($body['invoice_id'] ?? 0);
    if (!$invoiceId) {
        echo json_encode(['success' => false, 'message' => 'Thiếu invoice_id']); exit;
    }

    $inv = getInvoiceData($pdo, $invoiceId);
    if (!$inv) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy hoá đơn']); exit;
    }

    try {
        $payload  = buildBkavPayload($inv);
        $jsonData = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encData  = bkavEncrypt($jsonData);

        bkav_log("Sending invoice_id={$invoiceId} invoice_no={$inv['invoice_no']} env=" . (defined('BKAV_ENV') ? BKAV_ENV : 'unknown'));

        $rawXml  = bkavSoapCall($encData);
        bkav_log("Raw response (first 500): " . substr($rawXml, 0, 500));

        $result  = parseBkavResponse($rawXml);
        bkav_log("Parsed result: " . json_encode($result, JSON_UNESCAPED_UNICODE));

        $bkavInvoiceNo = $result['InvoiceNo']
            ?? $result['invoiceNo']
            ?? $result['invoice_no']
            ?? ($result['data']['InvoiceNo'] ?? null);

        $isSuccess = isset($result['ErrorCode']) && (int)$result['ErrorCode'] === 0;
        if (!$isSuccess && isset($result['error_code'])) {
            $isSuccess = (int)$result['error_code'] === 0;
        }

        if (!$isSuccess) {
            $errMsg = $result['Description'] ?? $result['message'] ?? $result['Message'] ?? 'Lỗi không xác định';
            bkav_log("BKAV error: " . $errMsg);
            $pdo->prepare("UPDATE invoices SET bkav_status='error', bkav_raw_response=? WHERE id=?")
                ->execute([substr($rawXml, 0, BKAV_RAW_RESPONSE_MAX_LENGTH), $invoiceId]);
            echo json_encode(['success' => false, 'message' => 'BKAV trả lỗi: ' . $errMsg], JSON_UNESCAPED_UNICODE); exit;
        }

        $pdo->prepare("
            UPDATE invoices
            SET bkav_invoice_no=?, bkav_status='issued', bkav_issued_at=NOW(), bkav_raw_response=?
            WHERE id=?
        ")->execute([$bkavInvoiceNo, substr($rawXml, 0, BKAV_RAW_RESPONSE_MAX_LENGTH), $invoiceId]);

        bkav_log("Success: bkav_invoice_no={$bkavInvoiceNo}");

        echo json_encode([
            'success'   => true,
            'invoiceNo' => $bkavInvoiceNo,
            'message'   => 'Xuất hoá đơn thành công',
        ], JSON_UNESCAPED_UNICODE);

    } catch (Throwable $e) {
        bkav_log("Exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Lỗi hệ thống: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ'], JSON_UNESCAPED_UNICODE);
