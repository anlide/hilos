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
