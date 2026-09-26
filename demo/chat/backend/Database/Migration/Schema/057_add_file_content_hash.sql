-- Adds the content fingerprint to the files registry (HIL-136).
-- Mirrors the framework stub create_hilos_file.sql.
--
-- No backfill: nothing published into the registry before this migration, so the table
-- holds no row to fingerprint.

ALTER TABLE `hilos_file`
    ADD COLUMN `content_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL AFTER `size`,
    ADD KEY `idx_file_owner_hash` (`owner_user_id`, `content_hash`);
