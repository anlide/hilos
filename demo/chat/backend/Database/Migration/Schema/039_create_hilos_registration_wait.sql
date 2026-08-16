-- Activates the framework hilos_registration_wait table (HIL-486) for demo/chat.
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_registration_wait.sql;
-- remembers which browser SESSIONS are waiting on a registration code, so a reloaded
-- tab, a second tab and another device all come back to the code screen instead of an
-- empty identifier field. Several sessions may wait on one address, which is why this
-- is a table and not a column on the reservation; UNIQUE(session_token) keeps one
-- unfinished registration per session, the newest evicting the previous.

CREATE TABLE `hilos_registration_wait` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_registration_wait_session` (`session_token`),
    KEY `idx_registration_wait_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
