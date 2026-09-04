-- Migration: Create hilos_registration_reservation table
-- Created: 2026-08-28
-- Index: 013
-- Description:
--   Reserve-on-submit registration (HIL-415): submitting the form holds the
--   identifier for a TTL instead of creating an account, and the account is
--   created only when the confirmation code comes back. One row = one browser.
--   The companion column pair on `hilos_session` (008) already tells this demo
--   which registration a browser is running; this table is the hold itself.
--
--   Copied from the framework stub
--   Database/Migration/Stub/create_hilos_registration_reservation.sql.
--
-- Reserve-on-submit registration (HIL-415). Submitting the registration form no
-- longer creates an account: it holds the identifier for a TTL and sends one
-- confirmation code, and the account is created only when that code comes back.
-- One row = one browser = one pending registration.
--
-- The hold is a table of its own rather than a column on hilos_user_verification
-- because holding a registration needs a UNIQUE key, which a challenge table can
-- never carry (consumed and expired challenges legitimately pile up per
-- identifier). Uniqueness is on `session_token` (HIL-608): one browser leads one
-- registration at a time, and a submit of another address evicts its own previous
-- hold. `identifier` carries a plain index instead, because several browsers may
-- legitimately be registering the same address at once - the first to prove it
-- wins the account and the rest are told the address is taken. That key is also
-- what makes the hold OWNED: a reservation is landed by the session that started
-- it, so a letter answered in another browser can never land somebody else's
-- password into the account it creates. The same question - "which registration
-- is this browser running" - is answered the same way by the pending-registration
-- columns of hilos_session.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary, and here there is nothing to point at —
-- the reservation exists precisely while the user does not. `session_token` is
-- likewise unconstrained: a swept session leaves a hold that expires on its own.
--
-- `identifier` and `session_token` use utf8mb4_bin so both compare exactly; the
-- writing leaf lowercases the identifier before insert.
--
-- `secret` (bcrypt hash of the password chosen at submit; NULL for the methods
-- that carry no credential) is intentionally NOT mapped in the Entity ORM layer
-- (see @object-exclude on the RegistrationReservation entity): it is written at
-- reserve time and read once, when the confirmed reservation becomes an identity,
-- so the hash never crosses the object, view, frontend, or cross-worker sync
-- boundary.

CREATE TABLE `hilos_registration_reservation` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` ENUM('password', 'magic_link', 'sms') NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `session_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `secret` VARCHAR(255) DEFAULT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_reservation_session` (`session_token`),
    KEY `idx_reservation_identifier` (`identifier`),
    KEY `idx_reservation_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
