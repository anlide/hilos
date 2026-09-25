-- Reverts 022: drops the foreign key from hilos_passkey_credential to hilos_identity.
-- The orphaned credentials 022 deleted do not come back.

ALTER TABLE `hilos_passkey_credential`
    DROP FOREIGN KEY `fk_passkey_credential_identity`;
