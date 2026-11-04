ALTER TABLE `bot`
    MODIFY COLUMN `personality` TEXT DEFAULT NULL COMMENT 'JSON object describing bot personality traits';
