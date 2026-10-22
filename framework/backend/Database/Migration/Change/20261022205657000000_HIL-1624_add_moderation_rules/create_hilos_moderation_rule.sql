CREATE TABLE `hilos_moderation_rule` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `rule` TEXT NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_moderation_rule_kind` (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
