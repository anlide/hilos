-- Migration: Attribute analytics events to authenticated people (HIL-1283)
-- Created: 2026-10-05
-- Index: 084

CREATE TABLE `hilos_analytics_person_event` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `subject_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `session_id` BIGINT UNSIGNED DEFAULT NULL,
    `browser_session_id` BIGINT UNSIGNED DEFAULT NULL,
    `event_kind` VARCHAR(24) NOT NULL,
    `action_name_id` BIGINT UNSIGNED DEFAULT NULL,
    `page_id` BIGINT UNSIGNED DEFAULT NULL,
    `page_params_id` BIGINT UNSIGNED DEFAULT NULL,
    `ipv4` INT UNSIGNED DEFAULT NULL,
    `ipv6` BINARY(16) DEFAULT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_person_user_ts_id` (`user_id`, `created_ts`, `id`),
    KEY `idx_ha_person_session_ts_id` (`session_id`, `created_ts`, `id`),
    KEY `idx_ha_person_browser` (`browser_session_id`),
    KEY `idx_ha_person_action` (`action_name_id`),
    KEY `idx_ha_person_page` (`page_id`),
    KEY `idx_ha_person_params` (`page_params_id`),
    CONSTRAINT `fk_ha_person_browser` FOREIGN KEY (`browser_session_id`) REFERENCES `hilos_analytics_browser_session` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ha_person_action` FOREIGN KEY (`action_name_id`) REFERENCES `hilos_analytics_action_name` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_person_page` FOREIGN KEY (`page_id`) REFERENCES `hilos_analytics_page` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ha_person_params` FOREIGN KEY (`page_params_id`) REFERENCES `hilos_analytics_page_params` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
