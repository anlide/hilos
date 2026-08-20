-- Migration: Create hilos_registration_wait table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_registration_wait.sql)
--
-- Memory of a registration that was started and not finished (HIL-486). One row =
-- one browser SESSION waiting on one identifier's code. It is what lets a reloaded
-- tab, a second tab, or another device come back to the code screen instead of the
-- empty identifier field: the step is served from the server on the handshake, so
-- it survives a closed tab and a restarted daemon.
--
-- Separate from `hilos_registration_reservation` because the two answer different
-- questions. The reservation holds the credential and the deadline of ONE browser's
-- attempt; the wait names the SESSIONS watching an address, and there are several —
-- a desktop and a phone can both be on the code screen for one address, each with a
-- reservation of its own since HIL-608. A column on the reservation could only ever
-- remember the first of them.
--
-- UNIQUE is on the session, not on the pair: a person runs one registration at a
-- time, so a new one evicts the previous row of that session rather than adding a
-- second the surface would then have to choose between. That is the same key the
-- reservation carries, and deliberately so — two structures answering "which
-- registration is this browser running" must not disagree about what identifies it.
--
-- `identifier` uses utf8mb4_bin so the address compares exactly, matching the
-- reservation it shadows; the writing leaf lowercases it before insert.
--
-- No DB-level foreign key: framework stubs never FK across the framework/project
-- boundary, and the session row this names is framework-owned but the account it
-- will become does not exist yet.

CREATE TABLE `hilos_registration_wait` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_registration_wait_session` (`session_token`),
    KEY `idx_registration_wait_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
