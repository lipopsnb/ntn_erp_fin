<?php
// ══════════════════════════════════════════════════════════════
// BKAV eHoaDon — Cấu hình tích hợp
// !! KHÔNG commit file này — thêm vào .gitignore !!
// ══════════════════════════════════════════════════════════════

// ── Môi trường ────────────────────────────────────────────────
define('BKAV_ENV', 'sandbox'); // 'sandbox' | 'production'

// ── URL Webservice ────────────────────────────────────────────
define('BKAV_WS_URL',
    BKAV_ENV === 'production'
        ? 'https://ws.ehoadon.vn/WSPublicEhoadon.asmx'
        : 'https://wsdemo.ehoadon.vn/WSPublicEhoadon.asmx'
);

// ── Xác thực — lấy từ BKAV ──────────────────────────────────
define('BKAV_PARTNER_GUID', '');       // PartnerGUID do BKAV cấp
define('BKAV_AES_KEY',      '');       // Base64 của AES Key (32 bytes)
define('BKAV_AES_IV',       '');       // Base64 của AES IV  (16 bytes)

// ── Mẫu hoá đơn ──────────────────────────────────────────────
define('BKAV_INVOICE_TYPE',     1);           // 1 = Hoá đơn GTGT
define('BKAV_INVOICE_TEMPLATE', '1');         // Mẫu số
define('BKAV_INVOICE_SERIAL',   'C26TYY');    // Ký hiệu

// ── Thanh toán & VAT ─────────────────────────────────────────
define('BKAV_PAYMENT_METHOD', 'Chuyển khoản');
define('VAT_RATE_DEFAULT', 0.08); // 0.08 = 8%

// ── Thông tin công ty (Seller) ───────────────────────────────
define('COMPANY_TAX',     '');   // Mã số thuế
define('COMPANY_NAME',    '');   // Tên công ty
define('COMPANY_ADDRESS', '');   // Địa chỉ
define('COMPANY_BANK',    '');   // Tên ngân hàng
define('COMPANY_ACCOUNT', '');   // Số tài khoản

// ── Timeout (giây) ───────────────────────────────────────────
define('BKAV_TIMEOUT_CONNECT', 10);
define('BKAV_TIMEOUT_READ',    60);
