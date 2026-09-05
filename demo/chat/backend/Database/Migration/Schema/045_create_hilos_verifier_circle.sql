-- Activates the framework hilos_verifier_circle table (HIL-643) for demo/chat.
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_verifier_circle.sql;
-- holds the people an operator named as verifiers of the system after a restore, one row
-- per (identity_type, identifier) pair. Only a project that declares the backup feature
-- has anything that reads it, and chat is the one demo that does.
--
-- The pair rather than a user id is deliberate: this table lives in the database a
-- restore rewrites, so a number stored here would name a different person once the
-- archive is in place. UNIQUE on the pair is the idempotency gate - naming the same
-- address twice is refused by the database, not by a read before the write.

CREATE TABLE `hilos_verifier_circle` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identity_type` ENUM('password', 'oauth', 'magic_link', 'sms', 'passkey') NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_verifier_circle_identity` (`identity_type`, `identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
