-- Hangs each passkey on its identity anchor by a foreign key (HIL-1111). Mirrors the
-- framework stub create_hilos_passkey_credential.sql.
--
-- The write right of a set walks up the set tree through the _foreign of the set
-- column: a credential sits in the set of its identity anchor, the anchor in the
-- set of its person. Without the key the credential's parent has no name, and a
-- claim over one person's set could not reach it.
--
-- The orphans go first. Before HIL-722 an identity anchor could be removed while
-- its credential stayed behind, and those rows are still stored; the ALTER below
-- fails on a table that holds one. They are dead already: the login ceremony
-- refuses them, the profile does not list them, and nobody can unlink them.
--
-- ON DELETE RESTRICT and not CASCADE: a credential is removed through its object so
-- the profile screen hears of it, and a cascade would take the row out silently.

DELETE `c` FROM `hilos_passkey_credential` `c`
    LEFT JOIN `hilos_identity` `i` ON `i`.`id` = `c`.`identity_id`
    WHERE `i`.`id` IS NULL;

ALTER TABLE `hilos_passkey_credential`
    ADD CONSTRAINT `fk_passkey_credential_identity` FOREIGN KEY (`identity_id`)
    REFERENCES `hilos_identity` (`id`) ON DELETE RESTRICT;
