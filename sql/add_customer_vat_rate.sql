ALTER TABLE customers ADD COLUMN vat_rate TINYINT NOT NULL DEFAULT 8 COMMENT 'Thuế VAT mặc định (%) — 0, 5, 8, 10' AFTER email;
