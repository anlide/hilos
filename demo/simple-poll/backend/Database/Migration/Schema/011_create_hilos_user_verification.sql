-- Migration: Create hilos_user_verification table
-- Created: 2026-08-28
-- Index: 011
-- Description:
--   The typed verification challenge (HIL-365) behind every ceremony this demo
--   turns on with the AUTH feature: the code that confirms a new address, the
--   one that recovers a password, the one a phone receives, and the pair a
--   magic-link letter carries (link plus hand-typed code, HIL-606).
--
--   Copied from the framework stub
--   Database/Migration/Stub/create_hilos_user_verification.sql.
--
-- Framework-standardized verification challenge (HIL-365). One typed row backs
-- both email-confirmation (register_confirm) and password-recovery
-- (password_reset); email_change is reserved for the Profile-cluster consumer
-- (HIL-298). This replaces the reference stack's four copy-pasted per-flow tables.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary. `user_id` is nullable because a request
-- may be issued before the owning user is resolved; the INDEX on it supports the
-- application-side cascade.
--
-- `identifier` uses utf8mb4_bin so the target email compares exactly; the writing
-- leaf lowercases it before insert.
--
-- `code_hash` (bcrypt hash of the delivered code) is intentionally NOT mapped in
-- the Entity ORM layer (see @object-exclude on the UserVerification entity): it is
-- written and verified only inside the verification layer, so the hash never
-- crosses the object, view, frontend, or cross-worker sync boundary.
--
-- ONE ceremony may hold TWO live rows, and magic-link sign-in is the case (HIL-606):
-- the letter carries a clickable link (`magic_link`) and the same-letter code a person
-- on another device types by hand (`magic_link_code`). They are two rows rather than one
-- because they are two secrets with two attempt ceilings - guessing the six digits must
-- not spend the link's budget - and because the type is what every lookup keys on.
-- Answering either one consumes the other, so the letter stays single-use as promised.
-- `magic_link_code` sits at the END of the ENUM rather than beside `magic_link`: appending
-- is the only widening MySQL performs in place, and a fresh table built from this stub has
-- to end up with the same member order as one that got there by ALTER.
--
-- `channel` is the delivery channel a phone code was explicitly sent over (HIL-492),
-- and it is a free VARCHAR rather than an ENUM because the set of channels is a code
-- registry a project composes, not a fixed list the schema can name. NULL means the
-- challenge was delivered by its type's own rule (every email type, and the profile
-- add-phone flow), so nothing about the existing rows changes; a non-null value is
-- what a resend reads to repeat the channel the person actually chose.

CREATE TABLE `hilos_user_verification` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `type` ENUM('register_confirm', 'password_reset', 'email_change', 'sms_login', 'magic_link', 'sms_add', 'email_add', 'magic_link_code') NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `channel` VARCHAR(32) DEFAULT NULL,
    `code_hash` VARCHAR(255) DEFAULT NULL,
    `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `expires_at` TIMESTAMP NOT NULL,
    `consumed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_uv_type_identifier` (`type`, `identifier`),
    KEY `idx_uv_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
