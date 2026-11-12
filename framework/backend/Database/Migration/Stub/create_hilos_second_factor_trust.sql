-- Migration: Create hilos_second_factor_trust table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_second_factor_trust.sql)
--
-- "Don't ask again on this device" (HIL-494): a browser and a person the second-factor
-- step is skipped for until `trusted_until`.
--
-- The browser is named by the session ROW (`session_id`, hilos_session.id), not by its
-- token: the token rotates on every sign-in, while the row stays - so a trust keyed by
-- the token would die on the very next sign-in it was meant to skip. The person is part
-- of the key because one browser may be used by two people, and trusting it for one of
-- them says nothing about the other. Signing out does not lower a trust; switching the
-- second factor off and a completed reset do.
--
-- `granted_at` is the moment the trust was last granted - written or extended (HIL-1900): a
-- shortening of the trust term reaches the trusts granted no later than it was saved.
--
-- `trusted_until`, `created_at` and `granted_at` are signed BIGINT epoch milliseconds (HIL-1922), with
-- no time zone and no database writer: the application stamps each one. 0 is the epoch rather than absence.

CREATE TABLE `hilos_second_factor_trust` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `trusted_until` BIGINT NOT NULL,
    `created_at` BIGINT NOT NULL,
    `granted_at` BIGINT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_second_factor_trust` (`session_id`, `user_id`),
    KEY `idx_second_factor_trust_user` (`user_id`),
    CONSTRAINT `fk_second_factor_trust_user` FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
