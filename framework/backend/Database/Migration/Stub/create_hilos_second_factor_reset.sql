-- Migration: Create hilos_second_factor_reset table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_second_factor_reset.sql)
--
-- Delayed removal of a person's second factor (HIL-494). Somebody who has lost both the
-- app and the backup codes asks for the factor to be removed and waits: the removal
-- happens at `effective_at`, and until then every channel the installation has keeps
-- announcing it, with a link that cancels it without signing in. The delay is the
-- mechanism - whoever took over the mailbox will not sit through it unnoticed.
--
-- `cancel_token` is the token the cancel link carries, stored as it is (64 hex
-- characters) so every reminder can repeat the same link. It is NULL so a row can
-- be inserted without it and the token written by a targeted update; a live request
-- is never left that way. `notified_at` is the last announcement, which the
-- reminder sweep reads. A request is live while both `canceled_at` and `completed_at`
-- are NULL; the writes that set either carry that condition, so a cancel and the
-- removal racing each other end with exactly one of them.

CREATE TABLE `hilos_second_factor_reset` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `requested_at` TIMESTAMP NOT NULL,
    `effective_at` TIMESTAMP NOT NULL,
    `cancel_token` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL DEFAULT NULL,
    `notified_at` TIMESTAMP NOT NULL,
    `canceled_at` TIMESTAMP NULL DEFAULT NULL,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_second_factor_reset_effective` (`effective_at`),
    KEY `idx_second_factor_reset_user` (`user_id`),
    CONSTRAINT `fk_second_factor_reset_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
