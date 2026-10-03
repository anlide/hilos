-- Migration: Add framework foreign keys onto hilos_user (HIL-1202).
-- Index: 026
-- Remove orphan rows before adding keys: a pre-key archive imports old rows
-- before this migration runs, so a refused orphan would fail restore halfway.
-- Rows about a person are deleted; mentions in a surviving row are cleared.

-- Children whose parents are themselves orphaned leave first.
DELETE `c` FROM `hilos_passkey_credential` `c`
    JOIN `hilos_identity` `i` ON `i`.`id` = `c`.`identity_id`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `i`.`user_id`
    WHERE `u`.`id` IS NULL;

-- Rows of people no longer present leave before RESTRICT is added.
DELETE `t` FROM `hilos_notification` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_notification_preference` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_passkey_credential` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_identity` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_user_verification` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_second_factor` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_second_factor_backup_code` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_second_factor_reset` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_second_factor_setting` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_second_factor_trust` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_step_up` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_legal_acceptance` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_access_log` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `t` FROM `hilos_data_export` `t`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `t`.`user_id`
    WHERE `t`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `s` FROM `hilos_session` `s`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `s`.`user_id`
    WHERE `s`.`user_id` IS NOT NULL AND `u`.`id` IS NULL;

DELETE `s` FROM `hilos_session` `s`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `s`.`impersonator_user_id`
    WHERE `s`.`impersonator_user_id` IS NOT NULL AND `u`.`id` IS NULL;

-- A surviving anonymous session may only mention the missing person.
UPDATE `hilos_session` `s`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `s`.`pending_second_factor_user_id`
    SET `s`.`pending_second_factor_user_id` = NULL,
        `s`.`pending_second_factor_mode` = NULL,
        `s`.`pending_second_factor_until` = NULL,
        `s`.`pending_second_factor_attempts` = 0,
        `s`.`pending_second_factor_ack` = NULL
    WHERE `s`.`pending_second_factor_user_id` IS NOT NULL AND `u`.`id` IS NULL;

UPDATE `hilos_session` `s`
    LEFT JOIN `hilos_user` `u` ON `u`.`id` = `s`.`blocked_user_id`
    SET `s`.`blocked_user_id` = NULL, `s`.`blocked_signed_in` = 0
    WHERE `s`.`blocked_user_id` IS NOT NULL AND `u`.`id` IS NULL;

-- A forgotten person row now refuses deletion until account erasure removes it.
ALTER TABLE `hilos_notification` ADD CONSTRAINT `fk_notification_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_notification_preference` ADD CONSTRAINT `fk_notification_preference_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_passkey_credential` ADD CONSTRAINT `fk_passkey_credential_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_identity` ADD CONSTRAINT `fk_identity_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_user_verification` ADD CONSTRAINT `fk_user_verification_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_second_factor` ADD CONSTRAINT `fk_second_factor_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_second_factor_backup_code` ADD CONSTRAINT `fk_second_factor_backup_code_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_second_factor_reset` ADD CONSTRAINT `fk_second_factor_reset_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_second_factor_setting` ADD CONSTRAINT `fk_second_factor_setting_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_second_factor_trust` ADD CONSTRAINT `fk_second_factor_trust_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_step_up` ADD CONSTRAINT `fk_step_up_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_legal_acceptance` ADD CONSTRAINT `fk_legal_acceptance_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_access_log` ADD CONSTRAINT `fk_access_log_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_data_export` ADD CONSTRAINT `fk_data_export_user`
    FOREIGN KEY (`user_id`) REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;

ALTER TABLE `hilos_session`
    ADD CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`)
        REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    ADD CONSTRAINT `fk_session_impersonator` FOREIGN KEY (`impersonator_user_id`)
        REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    ADD CONSTRAINT `fk_session_pending_second_factor` FOREIGN KEY (`pending_second_factor_user_id`)
        REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT,
    ADD CONSTRAINT `fk_session_blocked` FOREIGN KEY (`blocked_user_id`)
        REFERENCES `hilos_user` (`id`) ON DELETE RESTRICT;
