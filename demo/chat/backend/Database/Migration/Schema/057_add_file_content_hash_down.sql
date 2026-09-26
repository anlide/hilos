-- Reverts 057: drops the content fingerprint of the files registry.

ALTER TABLE `hilos_file`
    DROP KEY `idx_file_owner_hash`,
    DROP COLUMN `content_hash`;
