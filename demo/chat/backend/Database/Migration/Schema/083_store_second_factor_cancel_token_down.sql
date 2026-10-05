-- Migration Rollback: Restore the hashed second-factor cancel token (HIL-1302)
-- Index: 083
--
-- The open token is not the old hash, and ended rows no longer carry one.
-- The table is emptied before the old NOT NULL column returns.

DELETE FROM `hilos_second_factor_reset`;

ALTER TABLE `hilos_second_factor_reset`
    DROP COLUMN `cancel_token`,
    ADD COLUMN `cancel_token_hash` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL AFTER `effective_at`;
