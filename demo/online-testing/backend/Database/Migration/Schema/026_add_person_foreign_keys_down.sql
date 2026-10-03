-- Migration (down): Remove person foreign keys added by HIL-1202.
-- Index: 026
-- hilos_file.owner_user_id stays nullable: a cleared owner cannot be restored.

ALTER TABLE `hilos_notification` DROP FOREIGN KEY `fk_notification_user`;
ALTER TABLE `hilos_notification_preference` DROP FOREIGN KEY `fk_notification_preference_user`;
ALTER TABLE `hilos_passkey_credential` DROP FOREIGN KEY `fk_passkey_credential_user`;
ALTER TABLE `hilos_identity` DROP FOREIGN KEY `fk_identity_user`;
ALTER TABLE `hilos_user_verification` DROP FOREIGN KEY `fk_user_verification_user`;
ALTER TABLE `hilos_second_factor` DROP FOREIGN KEY `fk_second_factor_user`;
ALTER TABLE `hilos_second_factor_backup_code` DROP FOREIGN KEY `fk_second_factor_backup_code_user`;
ALTER TABLE `hilos_second_factor_reset` DROP FOREIGN KEY `fk_second_factor_reset_user`;
ALTER TABLE `hilos_second_factor_setting` DROP FOREIGN KEY `fk_second_factor_setting_user`;
ALTER TABLE `hilos_second_factor_trust` DROP FOREIGN KEY `fk_second_factor_trust_user`;
ALTER TABLE `hilos_step_up` DROP FOREIGN KEY `fk_step_up_user`;
ALTER TABLE `hilos_legal_acceptance` DROP FOREIGN KEY `fk_legal_acceptance_user`;
ALTER TABLE `hilos_access_log` DROP FOREIGN KEY `fk_access_log_user`;
ALTER TABLE `hilos_data_export` DROP FOREIGN KEY `fk_data_export_user`;

ALTER TABLE `hilos_session`
    DROP FOREIGN KEY `fk_session_user`,
    DROP FOREIGN KEY `fk_session_impersonator`,
    DROP FOREIGN KEY `fk_session_pending_second_factor`,
    DROP FOREIGN KEY `fk_session_blocked`;
