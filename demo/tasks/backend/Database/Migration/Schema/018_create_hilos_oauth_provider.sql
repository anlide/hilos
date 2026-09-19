-- Activates the framework hilos_oauth_provider table (HIL-286) for demo/tasks.
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_oauth_provider.sql;
-- holds what an administrator entered for each OAuth sign-in provider the demo declares
-- (client id, client secret, scope). A NULL column falls back to env and then to the
-- provider's recipe. `client_secret` is not mapped in the ORM and never leaves the
-- provider layer.

CREATE TABLE `hilos_oauth_provider` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider_key` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `client_id` VARCHAR(255) DEFAULT NULL,
    `client_secret` VARCHAR(255) DEFAULT NULL,
    `scope` VARCHAR(255) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_oauth_provider_key` (`provider_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
