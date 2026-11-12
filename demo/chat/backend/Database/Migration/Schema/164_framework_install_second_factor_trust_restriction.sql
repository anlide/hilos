-- hilos-framework: {"format":1,"kind":"install","units":["second_factor_trust_restriction"],"parts":[{"changeId":"20261112084322628728_HIL-1900_second_factor_trust_restriction","unit":"second_factor_trust_restriction","upSha256":"f5adc60277e32e6aeb9ff4555b11b04b67847630c6bcf21438cefc8f91c36181","downSha256":"da342bdc6bb0ee3fc392d99185c0bc2956ad9664c64f6e49333429ea3f762ea9"}],"upSha256":"f5adc60277e32e6aeb9ff4555b11b04b67847630c6bcf21438cefc8f91c36181","downSha256":"da342bdc6bb0ee3fc392d99185c0bc2956ad9664c64f6e49333429ea3f762ea9"}
-- Migration: Create hilos_second_factor_trust_restriction table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_second_factor_trust_restriction.sql)
--
-- Shortenings of browser trust (HIL-1900): the trusts granted no later than `saved_at` live no
-- longer than `saved_at` + `term_days` days, and a term of 0 takes them out. A row is written
-- when an administrator shortens the trust term or switches the second factor off with the
-- trusted browsers forgotten, and is never changed afterwards.
--
-- No foreign key and no CHECK: a restriction names nobody, and the application checks that
-- `term_days` lies between 0 and the longest trust term before it writes one.
--
-- `saved_at` is a signed BIGINT epoch millisecond, with no time zone and no database writer: the
-- application stamps it.

CREATE TABLE `hilos_second_factor_trust_restriction` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `saved_at` BIGINT NOT NULL,
    `term_days` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_second_factor_trust_restriction_saved` (`saved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
