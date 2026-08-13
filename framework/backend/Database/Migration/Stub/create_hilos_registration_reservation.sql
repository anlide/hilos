-- Migration: Create hilos_registration_reservation table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_registration_reservation.sql)
--
-- Reserve-on-submit registration (HIL-415). Submitting the registration form no
-- longer creates an account: it holds the identifier for a TTL and sends one
-- confirmation code, and the account is created only when that code comes back.
-- One row = one held address = one pending registration.
--
-- The hold is a table of its own rather than a column on hilos_user_verification
-- because holding an address needs UNIQUE(identifier), which a challenge table can
-- never carry (consumed and expired challenges legitimately pile up per
-- identifier). Uniqueness is on the identifier ALONE, not on (type, identifier) as
-- on hilos_identity: one address is one reservation and one code whatever method
-- reserved it, otherwise a password and a magic-link submit would hold the same
-- address and mail two codes for it. It is also what settles the race between two
-- simultaneous submits — the loser is answered "already reserved" and converges on
-- the code step instead of overwriting a hold that already mailed its code.
--
-- No DB-level foreign key to the project `user` table: framework stubs never FK
-- across the framework/project boundary, and here there is nothing to point at —
-- the reservation exists precisely while the user does not.
--
-- `identifier` uses utf8mb4_bin so the held address compares exactly; the writing
-- leaf lowercases it before insert.
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
    `secret` VARCHAR(255) DEFAULT NULL,
    `expires_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_reservation_identifier` (`identifier`),
    KEY `idx_reservation_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
