-- Restore the empty legacy structure and nullable overrides, without recovering retired data.

CREATE TABLE `moderator_prompt_piece` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `section` ENUM('name_rule', 'message_rule', 'photo_rule', 'message_picture_rule') NOT NULL,
    `prompt_piece` TEXT NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES
('chat_moderation_model', 'string', NULL),
('chat_moderation_url', 'string', NULL),
('chat_moderation_provider', 'string', NULL),
('chat_moderation_timeout_sec', 'float', NULL);
