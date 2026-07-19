-- Migration (down): Restore the legacy user.session_token column
-- Index: 024

ALTER TABLE `user`
    ADD COLUMN `session_token` VARCHAR(64) DEFAULT NULL AFTER `block`,
    ADD UNIQUE KEY `session_token` (`session_token`);
