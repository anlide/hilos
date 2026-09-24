-- Reverts 019: drops the five second-factor tables and the session's second-factor wait.
-- A sign-in waiting on its second factor when the rollback runs is dropped with the columns;
-- the browser comes back to the address field on its next handshake.

ALTER TABLE `hilos_session`
    DROP KEY `idx_session_pending_second_factor`,
    DROP COLUMN `pending_second_factor_ack`,
    DROP COLUMN `pending_second_factor_attempts`,
    DROP COLUMN `pending_second_factor_until`,
    DROP COLUMN `pending_second_factor_mode`,
    DROP COLUMN `pending_second_factor_user_id`;

DROP TABLE IF EXISTS `hilos_second_factor_setting`;
DROP TABLE IF EXISTS `hilos_second_factor_reset`;
DROP TABLE IF EXISTS `hilos_second_factor_trust`;
DROP TABLE IF EXISTS `hilos_second_factor_backup_code`;
DROP TABLE IF EXISTS `hilos_second_factor`;
