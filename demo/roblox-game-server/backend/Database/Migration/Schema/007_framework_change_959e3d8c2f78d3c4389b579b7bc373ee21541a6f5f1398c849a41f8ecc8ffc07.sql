-- hilos-framework: {"format":1,"kind":"change","units":["second_factor_trust"],"parts":[{"changeId":"20261111235717678237_HIL-1922_second_factor_moments","unit":"second_factor_trust","upSha256":"418c137c3d6c2b7ef5e6630ded73bfcd08be2887e0dcb73ca253b1268bc09913","downSha256":"72c5f64129fa520c3451a2a4d4141117d762357c029a5d735b2aafc56db9b971"}],"upSha256":"418c137c3d6c2b7ef5e6630ded73bfcd08be2887e0dcb73ca253b1268bc09913","downSha256":"72c5f64129fa520c3451a2a4d4141117d762357c029a5d735b2aafc56db9b971"}
ALTER TABLE `hilos_second_factor_trust`
    ADD COLUMN `__hil1922_trusted_until` BIGINT NULL AFTER `trusted_until`,
    ADD COLUMN `__hil1922_created_at` BIGINT NULL AFTER `created_at`;
UPDATE `hilos_second_factor_trust` SET
    `__hil1922_trusted_until` = UNIX_TIMESTAMP(`trusted_until`) * 1000,
    `__hil1922_created_at` = UNIX_TIMESTAMP(`created_at`) * 1000;
ALTER TABLE `hilos_second_factor_trust`
    DROP COLUMN `trusted_until`, ADD COLUMN `trusted_until` BIGINT NOT NULL AFTER `__hil1922_trusted_until`,
    DROP COLUMN `created_at`, ADD COLUMN `created_at` BIGINT NOT NULL AFTER `__hil1922_created_at`;
UPDATE `hilos_second_factor_trust` SET
    `trusted_until` = `__hil1922_trusted_until`,
    `created_at` = `__hil1922_created_at`;
ALTER TABLE `hilos_second_factor_trust`
    DROP COLUMN `__hil1922_trusted_until`,
    DROP COLUMN `__hil1922_created_at`;
