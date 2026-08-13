-- Activates the framework hilos_registration_reservation table (HIL-415) for demo/chat.
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_registration_reservation.sql;
-- holds an identifier between the registration submit and the confirmation code, so
-- the account is created only when the code comes back. One row = one held address =
-- one pending registration; UNIQUE(identifier) is what makes a second submit of the
-- same address converge on the existing code instead of mailing a second one.

CREATE TABLE `hilos_registration_reservation` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` ENUM('password', 'magic_link', 'sms') NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `secret` VARCHAR(255) DEFAULT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_reservation_identifier` (`identifier`),
    KEY `idx_reservation_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
