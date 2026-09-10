-- ============================================================
-- NeuroCheckout Connector
-- Script de désinstallation
-- ============================================================

DROP TABLE IF EXISTS `PREFIX_neurocheckout_event`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_order_event`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_telemetry_event`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_customer_journey_event`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_cron_log`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_nonce`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_circuit_breaker`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_lock`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_coupon`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_recovery_token`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_security_rate_limit`;
DROP TABLE IF EXISTS `PREFIX_neurocheckout_payload_alias`;
