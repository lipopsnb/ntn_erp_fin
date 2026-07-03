<?php
// ══════════════════════════════════════════════════════════════
// BKAV eHoaDon — Cấu hình tích hợp
// !! KHÔNG commit file này — thêm vào .gitignore !!
// ══════════════════════════════════════════════════════════════

// ── Môi trường ────────────────────────────────────────────────
define('BKAV_ENV', 'production'); // 'sandbox' | 'production'

// ── URL Webservice ────────────────────────────────────────────
define('BKAV_WS_URL',
    BKAV_ENV === 'production'
        ? 'https://ws.ehoadon.vn/WSPublicEhoadon.asmx'
        : 'https://wsdemo.ehoadon.vn/WSPublicEhoadon.asmx'
);

// ── Xác thực — lấy từ BKAV ──────────────────────────────────
define('BKAV_PARTNER_GUID', '4be56c5a-1463-4edb-8494-474f4ab323a3');
define('BKAV_AES_KEY',      'Cv591cx5CsihPj0htLkayayjk3sAUczrpP+FW6EvB7w='); // 32 bytes base64
define('BKAV_AES_IV',       'h7UIF1SfQbgF/OPZCWOxvA==');                      // 16 bytes base64

// ── Mẫu hoá đơn ──────────────────────────────────────────────
define('BKAV_INVOICE_TYPE',     1);         // 1 = Hoá đơn GTGT
define('BKAV_INVOICE_TEMPLATE', '1');       // Mẫu số
define('BKAV_INVOICE_SERIAL',   'C26TYY'); // Ký hiệu

// ── Thanh toán & VAT ─────────────────────────────────────────
define('BKAV_PAYMENT_METHOD', 'Chuyển khoản');
define('VAT_RATE_DEFAULT', 0.08); // 0.08 = 8%

// ── Thông tin công ty (Seller) ───────────────────────────────
define('COMPANY_TAX',     '0111343796');
define('COMPANY_NAME',    'CÔNG TY CỔ PHẦN SẢN XUẤT VÀ CUNG ỨNG NTN VIỆT NAM');
define('COMPANY_ADDRESS', '');  // Điền địa chỉ công ty
define('COMPANY_BANK',    '');  // Điền tên ngân hàng
define('COMPANY_ACCOUNT', '');  // Điền số tài khoản

// ── Timeout (giây) ───────────────────────────────────────────
define('BKAV_TIMEOUT_CONNECT', 10);
define('BKAV_TIMEOUT_READ',    60);
