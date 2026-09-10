-- ============================================================
-- NeuroCheckout Connector
-- INSTALL.SQL — ENTERPRISE AUTO-HEALING VERSION
-- Snapshot unique par panier
-- Backoff exponentiel + Dead letter + Cleared
-- 100k shops ready
-- ============================================================


-- ============================================================
-- TABLE 1 : PREFIX_neurocheckout_event
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_event` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `cart_id` VARCHAR(64) NOT NULL,

  `event_hash` VARCHAR(64) NOT NULL DEFAULT '',

  `payload` LONGTEXT NOT NULL,

  -- 🔥 FSM Enterprise
  -- pending     = prêt à envoyer
  -- processing  = verrouillé
  -- sent        = envoyé
  -- cleared     = panier supprimé / vidé
  -- dead        = retries dépassés
  `status` ENUM('pending','processing','sent','cleared','dead')
      NOT NULL DEFAULT 'pending',

  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  -- 🔥 Retry différé (backoff exponentiel)
  `next_retry_at` DATETIME NULL DEFAULT NULL,

  `priority` TINYINT UNSIGNED NOT NULL DEFAULT 0,

  `created_at` DATETIME NOT NULL,

  `last_attempt_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_shop_cart`
      (`shop_id`, `cart_id`),

  -- 🔥 Dispatch haute performance
  KEY `idx_dispatch`
      (`status`, `priority`, `created_at`),

  -- 🔥 Retry intelligent
  KEY `idx_retry`
      (`status`, `next_retry_at`),

  -- 🔥 Monitoring failures
  KEY `idx_attempts`
      (`status`, `attempts`),

  KEY `idx_shop`
      (`shop_id`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE 7 : PREFIX_neurocheckout_coupon
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_coupon` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `request_uid` VARCHAR(120) NOT NULL,

  `decision_id` VARCHAR(64) NOT NULL,

  `action_id` VARCHAR(64) DEFAULT NULL,

  `cart_id` VARCHAR(64) NOT NULL,

  `customer_email` VARCHAR(255) NOT NULL,

  `cart_rule_id` INT UNSIGNED NOT NULL,

  `coupon_code` VARCHAR(64) NOT NULL,

  `discount_percent` DECIMAL(5,2) NOT NULL,

  `cart_fingerprint` VARCHAR(64) DEFAULT NULL,

  `recovery_url` TEXT DEFAULT NULL,

  `expires_at` DATETIME NOT NULL,

  `created_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_shop_request_uid`
      (`shop_id`, `request_uid`),

  KEY `idx_shop_decision`
      (`shop_id`, `decision_id`),

  KEY `idx_coupon_code`
      (`coupon_code`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE 7B : PREFIX_neurocheckout_recovery_token
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_recovery_token` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `token_hash` VARCHAR(64) NOT NULL,

  `cart_id` VARCHAR(64) NOT NULL,

  `customer_email` VARCHAR(255) NOT NULL,

  `coupon_code` VARCHAR(64) DEFAULT NULL,

  `cart_fingerprint` VARCHAR(64) DEFAULT NULL,

  `mode` VARCHAR(32) NOT NULL DEFAULT 'cart',

  `customer_id` INT UNSIGNED DEFAULT NULL,

  `target_url` TEXT DEFAULT NULL,

  `expires_at` DATETIME NOT NULL,

  `used_at` DATETIME DEFAULT NULL,

  `created_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_token_hash`
      (`token_hash`),

  KEY `idx_shop_expires`
      (`shop_id`, `expires_at`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE 7C : PREFIX_neurocheckout_security_rate_limit
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_security_rate_limit` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `endpoint` VARCHAR(32) NOT NULL,

  `client_ip` VARCHAR(64) NOT NULL,

  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,

  `window_started_at` DATETIME NOT NULL,

  `blocked_until` DATETIME DEFAULT NULL,

  `last_error` VARCHAR(255) DEFAULT NULL,

  `updated_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_scope`
      (`shop_id`, `endpoint`, `client_ip`),

  KEY `idx_blocked_until`
      (`blocked_until`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;



-- ============================================================
-- TABLE 2 : PREFIX_neurocheckout_order_event
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_order_event` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `order_id` VARCHAR(64) NOT NULL,

  `cart_id` VARCHAR(64) NOT NULL,

  `payload` LONGTEXT NOT NULL,

  `status` ENUM('pending','sent','dead')
      NOT NULL DEFAULT 'pending',

  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  `next_retry_at` DATETIME NULL DEFAULT NULL,

  `last_error` VARCHAR(255) DEFAULT NULL,

  `created_at` DATETIME NOT NULL,

  `updated_at` DATETIME NOT NULL,

  `sent_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_shop_order`
      (`shop_id`, `order_id`),

  KEY `idx_dispatch`
      (`status`, `next_retry_at`, `created_at`),

  KEY `idx_shop_status`
      (`shop_id`, `status`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE 2B : PREFIX_neurocheckout_telemetry_event
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_telemetry_event` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `event_id` VARCHAR(120) NOT NULL,

  `event_type` VARCHAR(120) NOT NULL,

  `cart_id` VARCHAR(64) DEFAULT NULL,

  `event_hash` VARCHAR(64) NOT NULL,

  `payload` LONGTEXT NOT NULL,

  `status` ENUM('pending','processing','sent','dead')
      NOT NULL DEFAULT 'pending',

  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  `next_retry_at` DATETIME NULL DEFAULT NULL,

  `last_attempt_at` DATETIME NULL DEFAULT NULL,

  `last_error` VARCHAR(255) DEFAULT NULL,

  `created_at` DATETIME NOT NULL,

  `updated_at` DATETIME NOT NULL,

  `sent_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_shop_event_id`
      (`shop_id`, `event_id`),

  KEY `idx_dispatch`
      (`shop_id`, `status`, `next_retry_at`, `created_at`),

  KEY `idx_event_type`
      (`event_type`, `created_at`),

  KEY `idx_cart`
      (`shop_id`, `cart_id`, `created_at`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE 2C : PREFIX_neurocheckout_customer_journey_event
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_customer_journey_event` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `event_id` VARCHAR(120) NOT NULL,

  `event_type` VARCHAR(140) NOT NULL,

  `visitor_id` VARCHAR(120) DEFAULT NULL,

  `session_id` VARCHAR(120) DEFAULT NULL,

  `cart_id` VARCHAR(64) DEFAULT NULL,

  `customer_ref` VARCHAR(120) DEFAULT NULL,

  `event_hash` VARCHAR(64) NOT NULL,

  `payload` LONGTEXT NOT NULL,

  `status` ENUM('pending','processing','sent','dead')
      NOT NULL DEFAULT 'pending',

  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  `next_retry_at` DATETIME NULL DEFAULT NULL,

  `last_attempt_at` DATETIME NULL DEFAULT NULL,

  `last_error` VARCHAR(255) DEFAULT NULL,

  `created_at` DATETIME NOT NULL,

  `updated_at` DATETIME NOT NULL,

  `sent_at` DATETIME NULL DEFAULT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_shop_event_id`
      (`shop_id`, `event_id`),

  KEY `idx_dispatch`
      (`shop_id`, `status`, `next_retry_at`, `created_at`),

  KEY `idx_visitor`
      (`shop_id`, `visitor_id`, `created_at`),

  KEY `idx_session`
      (`shop_id`, `session_id`, `created_at`),

  KEY `idx_cart`
      (`shop_id`, `cart_id`, `created_at`),

  KEY `idx_event_type`
      (`event_type`, `created_at`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLE 3 : PREFIX_neurocheckout_cron_log
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_cron_log` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `executed_at` DATETIME NOT NULL,

  `status` ENUM('success','error','blocked') NOT NULL,

  `processed_events` INT UNSIGNED DEFAULT 0,

  `execution_time_ms` INT UNSIGNED DEFAULT 0,

  `ip_address` VARCHAR(45) DEFAULT NULL,

  `error_message` VARCHAR(255) DEFAULT NULL,

  PRIMARY KEY (`id`),

  KEY `idx_shop_executed`
      (`shop_id`, `executed_at`),

  KEY `idx_status_shop`
      (`status`, `shop_id`),

  KEY `idx_executed_at`
      (`executed_at`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;



-- ============================================================
-- TABLE 4 : PREFIX_neurocheckout_nonce
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_nonce` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `nonce` VARCHAR(64) NOT NULL,

  `expires_at` INT UNSIGNED NOT NULL,

  `shop_id` INT UNSIGNED NOT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_nonce_shop`
      (`nonce`, `shop_id`),

  KEY `idx_expires`
      (`expires_at`),

  KEY `idx_shop`
      (`shop_id`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;



-- ============================================================
-- TABLE 5 : PREFIX_neurocheckout_lock
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_lock` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `lock_key` VARCHAR(64) NOT NULL,

  `locked_until` DATETIME NOT NULL,

  `created_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_shop_lock`
      (`shop_id`, `lock_key`),

  KEY `idx_locked_until`
      (`locked_until`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;



-- ============================================================
-- TABLE 6 : PREFIX_neurocheckout_circuit_breaker
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_circuit_breaker` (

  `shop_id` INT UNSIGNED NOT NULL,

  `state` ENUM('closed','open','half_open') NOT NULL,

  `failure_count` INT UNSIGNED NOT NULL DEFAULT 0,

  `updated_at` DATETIME NOT NULL,

  PRIMARY KEY (`shop_id`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;



-- ============================================================
-- TABLE 7 : PREFIX_neurocheckout_payload_alias
-- ============================================================

CREATE TABLE IF NOT EXISTS `PREFIX_neurocheckout_payload_alias` (

  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `shop_id` INT UNSIGNED NOT NULL,

  `original_key` VARCHAR(100) NOT NULL,

  `alias_key` VARCHAR(20) NOT NULL,

  `schema_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,

  `created_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),

  UNIQUE KEY `uniq_shop_original`
      (`shop_id`, `original_key`),

  UNIQUE KEY `uniq_shop_alias`
      (`shop_id`, `alias_key`),

  KEY `idx_shop`
      (`shop_id`)

)
ENGINE=InnoDB
ROW_FORMAT=DYNAMIC
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
