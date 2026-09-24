-- Migration: Create hilos_verifier_circle table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_verifier_circle.sql)
--
-- The people an operator named as verifiers of the system after a freeze (HIL-643).
-- One row is one person, named by the same (type, identifier) pair `hilos_identity` is
-- keyed by. A member signed in when the freeze starts is let into the verification
-- window with the tab already open, without being handed a one-time code.
--
-- There is no `user_id` column, and its absence is the point: this table lives in the
-- database a restore rewrites, so a number stored here would name a different person
-- once the archive is in place. The pair is resolved to a number afresh on every read,
-- which is the same reason the session carrier moves a person across the swap by pairs.
--
-- No DB-level foreign key to `hilos_identity` either: a pair is named before it has to
-- exist and stays named after the identity behind it is gone, and the circle is a list
-- of addresses rather than a set of rows hanging off other rows.
--
-- `(identity_type, identifier)` is UNIQUE, and that index is the whole idempotency gate:
-- naming the same address twice is refused by the database rather than by a read before
-- the write, because two admin tabs fit between such a read and its insert.
--
-- `identity_type` and `identifier` mirror the shapes of `hilos_identity`.`type` and
-- `.identifier` exactly, including the utf8mb4_bin collation that makes the lookup
-- exact: a pair that does not compare equal to the identity it was copied from would
-- name nobody, silently and forever.

CREATE TABLE `hilos_verifier_circle` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `identity_type` ENUM('password', 'oauth', 'magic_link', 'sms', 'passkey') NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_verifier_circle_identity` (`identity_type`, `identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
