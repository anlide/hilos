-- Migration: Create hilos_second_factor table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_second_factor.sql)
--
-- The authenticator apps a person enrolled as a second factor (HIL-494). One row per
-- app: a person may enrol several, and a code from any of them passes the step.
--
-- `secret` is the shared TOTP secret in base32 (RFC 4648, no padding). It has to stay
-- readable - every code is computed from it - so it is not hashed; it leaves the
-- framework exactly once, in the answer that starts the enrolment. utf8mb4_bin keeps
-- the alphabet exact. It is DB-only, like the password hash of hilos_identity: absent
-- from the ORM columns, so it never rides a cross-worker sync frame, and written by a
-- targeted UPDATE right after the row is inserted - which is why it is NULL-able. A row
-- without one is an enrolment that never started and matches no code.
--
-- `confirmed_at` NULL means the enrolment is not finished: the secret was shown but no
-- code from it came back yet. Such a row does not count as a second factor anywhere,
-- and a new enrolment of the same person replaces it.
--
-- `last_used_step` is the replay guard: the 30-second step of the last accepted code.
-- A code is accepted only for a step above it, and the write carries that condition,
-- so two workers accepting the same code race in the database and one of them loses.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK across
-- the framework/project boundary.

CREATE TABLE `hilos_second_factor` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `label` VARCHAR(64) NOT NULL,
    `secret` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
    `last_used_step` BIGINT UNSIGNED DEFAULT NULL,
    `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `last_used_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_second_factor_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
