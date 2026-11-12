-- hilos-framework: {"format":1,"kind":"change","units":["second_factor_setting"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor_setting","upSha256":"4751b552baafb5158e62bcfdb4c8221b22dbf83770bfd794edf2a30757e0e104","downSha256":"1272afe53346cf9ace26dd70ed8e9bd2315e469a3063b860bbd95184e7244c9f"}],"upSha256":"4751b552baafb5158e62bcfdb4c8221b22dbf83770bfd794edf2a30757e0e104","downSha256":"1272afe53346cf9ace26dd70ed8e9bd2315e469a3063b860bbd95184e7244c9f"}
ALTER TABLE `hilos_second_factor_setting`
    ADD COLUMN `__hil1922_pending_reset_wait_from` BIGINT NULL AFTER `pending_reset_wait_from`,
    ADD COLUMN `__hil1922_app_code_misses_from` BIGINT NULL AFTER `app_code_misses_from`,
    ADD COLUMN `__hil1922_app_code_locked_until` BIGINT NULL AFTER `app_code_locked_until`,
    ADD COLUMN `__hil1922_updated_at` BIGINT NULL AFTER `updated_at`;
UPDATE `hilos_second_factor_setting` SET
    `__hil1922_pending_reset_wait_from` = UNIX_TIMESTAMP(`pending_reset_wait_from`) * 1000,
    `__hil1922_app_code_misses_from` = UNIX_TIMESTAMP(`app_code_misses_from`) * 1000,
    `__hil1922_app_code_locked_until` = UNIX_TIMESTAMP(`app_code_locked_until`) * 1000,
    `__hil1922_updated_at` = UNIX_TIMESTAMP(`updated_at`) * 1000;
ALTER TABLE `hilos_second_factor_setting`
    DROP COLUMN `pending_reset_wait_from`, ADD COLUMN `pending_reset_wait_from` BIGINT NULL DEFAULT NULL AFTER `__hil1922_pending_reset_wait_from`,
    DROP COLUMN `app_code_misses_from`, ADD COLUMN `app_code_misses_from` BIGINT NULL DEFAULT NULL AFTER `__hil1922_app_code_misses_from`,
    DROP COLUMN `app_code_locked_until`, ADD COLUMN `app_code_locked_until` BIGINT NULL DEFAULT NULL AFTER `__hil1922_app_code_locked_until`,
    DROP COLUMN `updated_at`, ADD COLUMN `updated_at` BIGINT NOT NULL AFTER `__hil1922_updated_at`;
UPDATE `hilos_second_factor_setting` SET
    `pending_reset_wait_from` = `__hil1922_pending_reset_wait_from`,
    `app_code_misses_from` = `__hil1922_app_code_misses_from`,
    `app_code_locked_until` = `__hil1922_app_code_locked_until`,
    `updated_at` = `__hil1922_updated_at`;
ALTER TABLE `hilos_second_factor_setting`
    DROP COLUMN `__hil1922_pending_reset_wait_from`,
    DROP COLUMN `__hil1922_app_code_misses_from`,
    DROP COLUMN `__hil1922_app_code_locked_until`,
    DROP COLUMN `__hil1922_updated_at`;
