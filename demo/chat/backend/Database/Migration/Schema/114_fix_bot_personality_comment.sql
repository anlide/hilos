ALTER TABLE `bot`
    MODIFY COLUMN `personality` TEXT DEFAULT NULL COMMENT 'Plain-text instructions for the bot system prompt';
