-- Migration: Create Hilos analytics tables

CREATE TABLE `hilos_analytics_user_agent` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sha1_hash` BINARY(20) NOT NULL,
    `value` TEXT NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_ua_hash` (`sha1_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_accept_language` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sha1_hash` BINARY(20) NOT NULL,
    `value` TEXT NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_al_hash` (`sha1_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_page` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_name` VARCHAR(100) NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_page_name` (`page_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_page_params` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sha1_hash` BINARY(20) NOT NULL,
    `params_json` JSON NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_page_params_hash` (`sha1_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_action_name` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_action_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_signal_name` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_signal_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_cron_name` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_cron_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_payload_json` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sha1_hash` BINARY(20) NOT NULL,
    `payload_json` JSON NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_payload_hash` (`sha1_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_browser_session` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_token` VARCHAR(100) NOT NULL,
    `user_identity_type` VARCHAR(50) DEFAULT NULL,
    `user_identity_value` VARCHAR(255) DEFAULT NULL,
    `current_user_agent_id` BIGINT UNSIGNED DEFAULT NULL,
    `current_accept_language_id` BIGINT UNSIGNED DEFAULT NULL,
    `first_seen_ts` BIGINT UNSIGNED NOT NULL,
    `last_seen_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_browser_session_token` (`session_token`),
    KEY `idx_ha_browser_identity` (`user_identity_type`, `user_identity_value`),
    KEY `idx_ha_browser_last_seen` (`last_seen_ts`),
    KEY `idx_ha_browser_user_agent` (`current_user_agent_id`),
    KEY `idx_ha_browser_accept_language` (`current_accept_language_id`),
    CONSTRAINT `fk_ha_browser_user_agent` FOREIGN KEY (`current_user_agent_id`) REFERENCES `hilos_analytics_user_agent` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ha_browser_accept_language` FOREIGN KEY (`current_accept_language_id`) REFERENCES `hilos_analytics_accept_language` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_browser_session_user_agent_change` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `browser_session_id` BIGINT UNSIGNED NOT NULL,
    `old_user_agent_id` BIGINT UNSIGNED DEFAULT NULL,
    `new_user_agent_id` BIGINT UNSIGNED NOT NULL,
    `changed_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_browser_ua_change_session_ts` (`browser_session_id`, `changed_ts`),
    KEY `idx_ha_browser_ua_change_old` (`old_user_agent_id`),
    KEY `idx_ha_browser_ua_change_new` (`new_user_agent_id`),
    CONSTRAINT `fk_ha_browser_ua_change_session` FOREIGN KEY (`browser_session_id`) REFERENCES `hilos_analytics_browser_session` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_browser_ua_change_old` FOREIGN KEY (`old_user_agent_id`) REFERENCES `hilos_analytics_user_agent` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ha_browser_ua_change_new` FOREIGN KEY (`new_user_agent_id`) REFERENCES `hilos_analytics_user_agent` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_browser_session_accept_language_change` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `browser_session_id` BIGINT UNSIGNED NOT NULL,
    `old_accept_language_id` BIGINT UNSIGNED DEFAULT NULL,
    `new_accept_language_id` BIGINT UNSIGNED NOT NULL,
    `changed_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_browser_al_change_session_ts` (`browser_session_id`, `changed_ts`),
    KEY `idx_ha_browser_al_change_old` (`old_accept_language_id`),
    KEY `idx_ha_browser_al_change_new` (`new_accept_language_id`),
    CONSTRAINT `fk_ha_browser_al_change_session` FOREIGN KEY (`browser_session_id`) REFERENCES `hilos_analytics_browser_session` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_browser_al_change_old` FOREIGN KEY (`old_accept_language_id`) REFERENCES `hilos_analytics_accept_language` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ha_browser_al_change_new` FOREIGN KEY (`new_accept_language_id`) REFERENCES `hilos_analytics_accept_language` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_ws_connection` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `browser_session_id` BIGINT UNSIGNED DEFAULT NULL,
    `accept_key` VARCHAR(100) NOT NULL,
    `opened_ipv4` INT UNSIGNED DEFAULT NULL,
    `opened_ipv6` BINARY(16) DEFAULT NULL,
    `opened_ts` BIGINT UNSIGNED NOT NULL,
    `closed_ts` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_ws_accept_key` (`accept_key`),
    KEY `idx_ha_ws_browser_session` (`browser_session_id`),
    KEY `idx_ha_ws_opened_ts` (`opened_ts`),
    CONSTRAINT `fk_ha_ws_browser_session` FOREIGN KEY (`browser_session_id`) REFERENCES `hilos_analytics_browser_session` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_ws_connection_ipv4_change` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ws_connection_id` BIGINT UNSIGNED NOT NULL,
    `old_ipv4` INT UNSIGNED DEFAULT NULL,
    `new_ipv4` INT UNSIGNED DEFAULT NULL,
    `changed_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_ws_ipv4_change_conn_ts` (`ws_connection_id`, `changed_ts`),
    CONSTRAINT `fk_ha_ws_ipv4_change_conn` FOREIGN KEY (`ws_connection_id`) REFERENCES `hilos_analytics_ws_connection` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_ws_connection_ipv6_change` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ws_connection_id` BIGINT UNSIGNED NOT NULL,
    `old_ipv6` BINARY(16) DEFAULT NULL,
    `new_ipv6` BINARY(16) DEFAULT NULL,
    `changed_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_ws_ipv6_change_conn_ts` (`ws_connection_id`, `changed_ts`),
    CONSTRAINT `fk_ha_ws_ipv6_change_conn` FOREIGN KEY (`ws_connection_id`) REFERENCES `hilos_analytics_ws_connection` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_page_session` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ws_connection_id` BIGINT UNSIGNED NOT NULL,
    `page_id` BIGINT UNSIGNED NOT NULL,
    `page_params_id` BIGINT UNSIGNED DEFAULT NULL,
    `opened_ts` BIGINT UNSIGNED NOT NULL,
    `closed_ts` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_page_session_conn_ts` (`ws_connection_id`, `opened_ts`),
    KEY `idx_ha_page_session_page` (`page_id`),
    KEY `idx_ha_page_session_params` (`page_params_id`),
    CONSTRAINT `fk_ha_page_session_conn` FOREIGN KEY (`ws_connection_id`) REFERENCES `hilos_analytics_ws_connection` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_page_session_page` FOREIGN KEY (`page_id`) REFERENCES `hilos_analytics_page` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_page_session_params` FOREIGN KEY (`page_params_id`) REFERENCES `hilos_analytics_page_params` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_worker_session` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `worker_index` INT UNSIGNED NOT NULL,
    `is_monopolistic` TINYINT(1) NOT NULL DEFAULT 0,
    `started_ts` BIGINT UNSIGNED NOT NULL,
    `stopped_ts` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_worker_index_started` (`worker_index`, `started_ts`),
    KEY `idx_ha_worker_started_ts` (`started_ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_agent_session` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `worker_session_id` BIGINT UNSIGNED NOT NULL,
    `agent_type` VARCHAR(50) NOT NULL,
    `agent_index` VARCHAR(50) DEFAULT NULL,
    `started_ts` BIGINT UNSIGNED NOT NULL,
    `stopped_ts` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_agent_worker` (`worker_session_id`),
    KEY `idx_ha_agent_type_index` (`agent_type`, `agent_index`),
    KEY `idx_ha_agent_started_ts` (`started_ts`),
    CONSTRAINT `fk_ha_agent_worker_session` FOREIGN KEY (`worker_session_id`) REFERENCES `hilos_analytics_worker_session` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_user_action` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ws_connection_id` BIGINT UNSIGNED NOT NULL,
    `page_session_id` BIGINT UNSIGNED DEFAULT NULL,
    `action_name_id` BIGINT UNSIGNED NOT NULL,
    `payload_json_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_user_action_conn_ts` (`ws_connection_id`, `created_ts`),
    KEY `idx_ha_user_action_page_session` (`page_session_id`),
    KEY `idx_ha_user_action_name` (`action_name_id`),
    KEY `idx_ha_user_action_payload` (`payload_json_id`),
    CONSTRAINT `fk_ha_user_action_conn` FOREIGN KEY (`ws_connection_id`) REFERENCES `hilos_analytics_ws_connection` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_user_action_page_session` FOREIGN KEY (`page_session_id`) REFERENCES `hilos_analytics_page_session` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ha_user_action_name` FOREIGN KEY (`action_name_id`) REFERENCES `hilos_analytics_action_name` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_user_action_payload` FOREIGN KEY (`payload_json_id`) REFERENCES `hilos_analytics_payload_json` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_agent_user_action` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_session_id` BIGINT UNSIGNED NOT NULL,
    `user_action_id` BIGINT UNSIGNED DEFAULT NULL,
    `signal_name_id` BIGINT UNSIGNED NOT NULL,
    `payload_json_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_agent_user_action_agent_ts` (`agent_session_id`, `created_ts`),
    KEY `idx_ha_agent_user_action_user_action` (`user_action_id`),
    KEY `idx_ha_agent_user_action_signal` (`signal_name_id`),
    KEY `idx_ha_agent_user_action_payload` (`payload_json_id`),
    CONSTRAINT `fk_ha_agent_user_action_agent` FOREIGN KEY (`agent_session_id`) REFERENCES `hilos_analytics_agent_session` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_agent_user_action_user_action` FOREIGN KEY (`user_action_id`) REFERENCES `hilos_analytics_user_action` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ha_agent_user_action_signal` FOREIGN KEY (`signal_name_id`) REFERENCES `hilos_analytics_signal_name` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_agent_user_action_payload` FOREIGN KEY (`payload_json_id`) REFERENCES `hilos_analytics_payload_json` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_agent_system_signal` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_session_id` BIGINT UNSIGNED NOT NULL,
    `signal_name_id` BIGINT UNSIGNED NOT NULL,
    `payload_json_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_agent_system_signal_agent_ts` (`agent_session_id`, `created_ts`),
    KEY `idx_ha_agent_system_signal_name` (`signal_name_id`),
    KEY `idx_ha_agent_system_signal_payload` (`payload_json_id`),
    CONSTRAINT `fk_ha_agent_system_signal_agent` FOREIGN KEY (`agent_session_id`) REFERENCES `hilos_analytics_agent_session` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_agent_system_signal_name` FOREIGN KEY (`signal_name_id`) REFERENCES `hilos_analytics_signal_name` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_agent_system_signal_payload` FOREIGN KEY (`payload_json_id`) REFERENCES `hilos_analytics_payload_json` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_agent_cron_signal` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `agent_session_id` BIGINT UNSIGNED NOT NULL,
    `cron_name_id` BIGINT UNSIGNED NOT NULL,
    `payload_json_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_agent_cron_signal_agent_ts` (`agent_session_id`, `created_ts`),
    KEY `idx_ha_agent_cron_signal_name` (`cron_name_id`),
    KEY `idx_ha_agent_cron_signal_payload` (`payload_json_id`),
    CONSTRAINT `fk_ha_agent_cron_signal_agent` FOREIGN KEY (`agent_session_id`) REFERENCES `hilos_analytics_agent_session` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_agent_cron_signal_name` FOREIGN KEY (`cron_name_id`) REFERENCES `hilos_analytics_cron_name` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_agent_cron_signal_payload` FOREIGN KEY (`payload_json_id`) REFERENCES `hilos_analytics_payload_json` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_worker_system_signal` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `worker_session_id` BIGINT UNSIGNED NOT NULL,
    `signal_name_id` BIGINT UNSIGNED NOT NULL,
    `payload_json_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_worker_system_signal_worker_ts` (`worker_session_id`, `created_ts`),
    KEY `idx_ha_worker_system_signal_name` (`signal_name_id`),
    KEY `idx_ha_worker_system_signal_payload` (`payload_json_id`),
    CONSTRAINT `fk_ha_worker_system_signal_worker` FOREIGN KEY (`worker_session_id`) REFERENCES `hilos_analytics_worker_session` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_worker_system_signal_name` FOREIGN KEY (`signal_name_id`) REFERENCES `hilos_analytics_signal_name` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_worker_system_signal_payload` FOREIGN KEY (`payload_json_id`) REFERENCES `hilos_analytics_payload_json` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_api_request` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `browser_session_id` BIGINT UNSIGNED DEFAULT NULL,
    `method` VARCHAR(10) NOT NULL,
    `path` VARCHAR(255) NOT NULL,
    `params_json_id` BIGINT UNSIGNED DEFAULT NULL,
    `status_code` SMALLINT UNSIGNED DEFAULT NULL,
    `duration_ms` INT UNSIGNED DEFAULT NULL,
    `started_ts` BIGINT UNSIGNED NOT NULL,
    `finished_ts` BIGINT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_api_browser_ts` (`browser_session_id`, `started_ts`),
    KEY `idx_ha_api_path` (`path`),
    KEY `idx_ha_api_params` (`params_json_id`),
    CONSTRAINT `fk_ha_api_browser_session` FOREIGN KEY (`browser_session_id`) REFERENCES `hilos_analytics_browser_session` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ha_api_params` FOREIGN KEY (`params_json_id`) REFERENCES `hilos_analytics_page_params` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `hilos_analytics_api_agent_action` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `api_request_id` BIGINT UNSIGNED NOT NULL,
    `agent_session_id` BIGINT UNSIGNED NOT NULL,
    `signal_name_id` BIGINT UNSIGNED NOT NULL,
    `payload_json_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_api_agent_action_api_ts` (`api_request_id`, `created_ts`),
    KEY `idx_ha_api_agent_action_agent` (`agent_session_id`),
    KEY `idx_ha_api_agent_action_signal` (`signal_name_id`),
    KEY `idx_ha_api_agent_action_payload` (`payload_json_id`),
    CONSTRAINT `fk_ha_api_agent_action_api` FOREIGN KEY (`api_request_id`) REFERENCES `hilos_analytics_api_request` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_api_agent_action_agent` FOREIGN KEY (`agent_session_id`) REFERENCES `hilos_analytics_agent_session` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ha_api_agent_action_signal` FOREIGN KEY (`signal_name_id`) REFERENCES `hilos_analytics_signal_name` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_api_agent_action_payload` FOREIGN KEY (`payload_json_id`) REFERENCES `hilos_analytics_payload_json` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
