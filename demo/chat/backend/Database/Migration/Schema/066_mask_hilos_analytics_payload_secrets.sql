-- Migration: Mask the secrets analytics recorded in action payloads (HIL-1187)
-- Created: 2026-09-28
-- Index: 066
--
-- Until HIL-1187 the analytics collector wrote every action payload as it came, so
-- hilos_analytics_payload_json holds passwords, confirmation codes, one-time link
-- tokens and OAuth secrets in plain text. The collector now masks the fields each
-- action DTO declares in SECRET_FIELDS; this cleanup does the same to the rows
-- already written, for the same actions and fields, by the same rule: a field is
-- looked for at the top of the payload and one level inside `data`, a null or empty
-- value stays, and any other value becomes "***". A value already masked is not
-- touched again, so a second run changes nothing.
--
-- The sha1_hash of a masked row is replaced rather than recomputed. It was a SHA-1
-- of the JSON with the secret in it, enough to confirm a guessed password, and the
-- JSON text MariaDB keeps differs from json_encode() in whitespace, so the hash the
-- collector would compute cannot be repeated here. The replacement is unique per
-- row and means nothing; a masked payload the collector writes later gets a row of
-- its own.
--
-- The list below is the SECRET_FIELDS of the framework's action DTOs on the day of
-- this migration: action name, then payload field.

CREATE TEMPORARY TABLE `tmp_analytics_secret_field` (
    `action_name` VARCHAR(100) NOT NULL,
    `field` VARCHAR(50) NOT NULL,
    PRIMARY KEY (`action_name`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `tmp_analytics_secret_field` (`action_name`, `field`) VALUES
    ('hilos_account_deletion_start', 'code'),
    ('hilos_complete_password_reset', 'password'),
    ('hilos_complete_registration', 'password'),
    ('hilos_confirm_magic_link', 'token'),
    ('hilos_confirm_magic_link_code', 'code'),
    ('hilos_confirm_password_reset', 'code'),
    ('hilos_confirm_phone_code', 'code'),
    ('hilos_confirm_register', 'code'),
    ('hilos_confirm_second_factor', 'code'),
    ('hilos_link_oauth_after_reauth', 'token'),
    ('hilos_login', 'password'),
    ('hilos_oauth_callback', 'code'),
    ('hilos_oauth_callback', 'tripKey'),
    ('hilos_oauth_resume', 'tripKey'),
    ('hilos_passkey_login_confirm', 'signature'),
    ('profile_add_password_confirm', 'code'),
    ('profile_add_password_confirm', 'newPassword'),
    ('profile_add_sms_confirm', 'code'),
    ('profile_change_password', 'code'),
    ('profile_change_password', 'newPassword'),
    ('profile_change_password_code_confirm', 'code'),
    ('profile_change_email_current_confirm', 'code'),
    ('profile_change_email_new_confirm', 'currentCode'),
    ('profile_change_email_new_confirm', 'code'),
    ('profile_change_email_new_request', 'currentCode'),
    ('profile_set_password', 'newPassword'),
    ('hilos_second_factor_reset_cancel_link', 'token'),
    ('hilos_second_factor_setup_confirm', 'code'),
    ('profile_second_factor_codes_renew', 'proofCode'),
    ('profile_second_factor_codes_show', 'proofCode'),
    ('profile_second_factor_enroll_confirm', 'code'),
    ('profile_second_factor_enroll_start', 'proofCode'),
    ('profile_second_factor_remove', 'proofCode'),
    ('hilos_step_up_confirm', 'code'),
    ('hilos_step_up_confirm', 'password'),
    ('hilos_step_up_confirm', 'passkey'),
    ('security_oauth_provider_set', 'value'),
    ('push_subscribe', 'auth');

-- Every payload row a recorded action of the list points at, with the fields to mask
-- in it: the user action by its action name, the agent's reaction to it and an
-- agent action under an HTTP request by their signal name.
CREATE TEMPORARY TABLE `tmp_analytics_secret_payload` (
    `payload_json_id` BIGINT UNSIGNED NOT NULL,
    `field` VARCHAR(50) NOT NULL,
    PRIMARY KEY (`payload_json_id`, `field`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO `tmp_analytics_secret_payload` (`payload_json_id`, `field`)
SELECT ua.`payload_json_id`, f.`field`
FROM `hilos_analytics_user_action` ua
JOIN `hilos_analytics_action_name` n ON n.`id` = ua.`action_name_id`
JOIN `tmp_analytics_secret_field` f ON f.`action_name` = n.`name`
WHERE ua.`payload_json_id` IS NOT NULL;

INSERT IGNORE INTO `tmp_analytics_secret_payload` (`payload_json_id`, `field`)
SELECT aua.`payload_json_id`, f.`field`
FROM `hilos_analytics_agent_user_action` aua
JOIN `hilos_analytics_signal_name` n ON n.`id` = aua.`signal_name_id`
JOIN `tmp_analytics_secret_field` f ON f.`action_name` = n.`name`
WHERE aua.`payload_json_id` IS NOT NULL;

INSERT IGNORE INTO `tmp_analytics_secret_payload` (`payload_json_id`, `field`)
SELECT aaa.`payload_json_id`, f.`field`
FROM `hilos_analytics_api_agent_action` aaa
JOIN `hilos_analytics_signal_name` n ON n.`id` = aaa.`signal_name_id`
JOIN `tmp_analytics_secret_field` f ON f.`action_name` = n.`name`
WHERE aaa.`payload_json_id` IS NOT NULL;

-- One statement per field and place: a row carrying several secret fields is
-- changed once by each of them.

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.code', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'code')
    AND JSON_EXTRACT(`payload_json`, '$.code') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.code', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'code')
    AND JSON_EXTRACT(`payload_json`, '$.data.code') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.password', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'password')
    AND JSON_EXTRACT(`payload_json`, '$.password') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.password', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'password')
    AND JSON_EXTRACT(`payload_json`, '$.data.password') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.token', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'token')
    AND JSON_EXTRACT(`payload_json`, '$.token') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.token', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'token')
    AND JSON_EXTRACT(`payload_json`, '$.data.token') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.tripKey', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'tripKey')
    AND JSON_EXTRACT(`payload_json`, '$.tripKey') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.tripKey', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'tripKey')
    AND JSON_EXTRACT(`payload_json`, '$.data.tripKey') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.signature', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'signature')
    AND JSON_EXTRACT(`payload_json`, '$.signature') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.signature', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'signature')
    AND JSON_EXTRACT(`payload_json`, '$.data.signature') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.newPassword', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'newPassword')
    AND JSON_EXTRACT(`payload_json`, '$.newPassword') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.newPassword', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'newPassword')
    AND JSON_EXTRACT(`payload_json`, '$.data.newPassword') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.currentCode', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'currentCode')
    AND JSON_EXTRACT(`payload_json`, '$.currentCode') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.currentCode', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'currentCode')
    AND JSON_EXTRACT(`payload_json`, '$.data.currentCode') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.proofCode', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'proofCode')
    AND JSON_EXTRACT(`payload_json`, '$.proofCode') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.proofCode', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'proofCode')
    AND JSON_EXTRACT(`payload_json`, '$.data.proofCode') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.passkey', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'passkey')
    AND JSON_EXTRACT(`payload_json`, '$.passkey') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.passkey', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'passkey')
    AND JSON_EXTRACT(`payload_json`, '$.data.passkey') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.value', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'value')
    AND JSON_EXTRACT(`payload_json`, '$.value') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.value', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'value')
    AND JSON_EXTRACT(`payload_json`, '$.data.value') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.auth', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'auth')
    AND JSON_EXTRACT(`payload_json`, '$.auth') NOT IN ('null', '""', '"***"');

UPDATE `hilos_analytics_payload_json`
SET `payload_json` = JSON_REPLACE(`payload_json`, '$.data.auth', '***'),
    `sha1_hash` = UNHEX(SHA1(CONCAT('HIL-1187 masked ', `id`)))
WHERE `id` IN (SELECT `payload_json_id` FROM `tmp_analytics_secret_payload` WHERE `field` = 'auth')
    AND JSON_EXTRACT(`payload_json`, '$.data.auth') NOT IN ('null', '""', '"***"');

DROP TEMPORARY TABLE `tmp_analytics_secret_payload`;

DROP TEMPORARY TABLE `tmp_analytics_secret_field`;
