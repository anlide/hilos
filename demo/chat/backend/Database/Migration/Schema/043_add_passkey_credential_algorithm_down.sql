-- Reverts 043: drops the `algorithm` column from hilos_passkey_credential.
-- Any RS256 credential is unusable after the rollback - the ceremony it was
-- enrolled by is gone with the column.

ALTER TABLE `hilos_passkey_credential`
    DROP COLUMN `algorithm`;
