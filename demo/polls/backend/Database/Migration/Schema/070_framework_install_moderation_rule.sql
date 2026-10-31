-- hilos-framework: {"format":1,"kind":"install","units":["moderation_rule"],"parts":[{"changeId":"20261022205657000000_HIL-1624_add_moderation_rules","unit":"moderation_rule","upSha256":"de30787b88b5e0f945159688f8427e7eb278a2c77a0477c9ff40a0eae303064f","downSha256":"62a45b5fb2c723f7d5126e3eeb0df8871ed775735829fe3f1b7ab0630e7d0379"}],"upSha256":"19b2663be034f5f0c658e17a4f387a05ec4b3be8d6023f618bfc73d2238ba5dc","downSha256":"62a45b5fb2c723f7d5126e3eeb0df8871ed775735829fe3f1b7ab0630e7d0379"}
-- Migration: Create hilos_moderation_rule table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_moderation_rule.sql)
--
-- An administrator's own rules for one kind of moderation check (HIL-1624). The kind is the
-- string key of the PHP moderation kind directory - not an ENUM and not a foreign key: the
-- directory declares the kinds, and one kind holds many rules. While a kind has no row here
-- its built-in rules apply; its own rows, read by id, replace them whole.
CREATE TABLE `hilos_moderation_rule` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `rule` TEXT NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_moderation_rule_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
