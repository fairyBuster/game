-- ============================================================
-- RoguePay Payment Gateway — integration SQL (idempotent)
-- Pay-in (QRIS / VA / e-wallet), pay-out (bank transfer),
-- webhook callbacks signed with HMAC-SHA256.
-- Base URL: https://api.roguecdn.online
-- ============================================================

-- 1) Gateway record (automatic gateway, code 511)
INSERT INTO gateways (form_id, code, name, alias, image, status, gateway_parameters, supported_currencies, crypto, extra, description, created_at, updated_at)
SELECT 0, 511, 'RoguePay', 'RoguePay', NULL, 1,
'{"api_key":{"title":"API Key","global":true,"value":""},"secret_key":{"title":"Secret Key","global":true,"value":""},"base_url":{"title":"Base URL","global":true,"value":"https://api.roguecdn.online"},"default_method":{"title":"Default Payment Method","global":true,"value":"QRIS"}}',
'{"IDR":"Rp"}', 0,
'{"cron":{"title":"Cron Job URL","value":"ipn.RoguePay"},"webhook":{"title":"Webhook URL (set this URL in the RoguePay dashboard)","value":"ipn.RoguePay"}}',
'RoguePay payment aggregator: pay-in (QRIS/VA/e-wallet) & pay-out (bank transfer) with HMAC-SHA256 signed API.',
NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM gateways WHERE alias = 'RoguePay');

-- 2) Default currency (IDR)
INSERT INTO gateway_currencies (name, currency, symbol, method_code, gateway_alias, min_amount, max_amount, percent_charge, fixed_charge, rate, gateway_parameter, created_at, updated_at)
SELECT 'RoguePay - IDR', 'IDR', 'Rp', 511, 'RoguePay', 10000, 100000000, 0, 0, 1,
'{"api_key":"","secret_key":"","base_url":"https://api.roguecdn.online","default_method":"QRIS"}',
NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM gateway_currencies WHERE method_code = 511 AND currency = 'IDR');

-- 3) Track the RoguePay payout reference on withdrawals
ALTER TABLE withdrawals ADD COLUMN pg_ref VARCHAR(64) NULL DEFAULT NULL AFTER trx;
