-- Rollback: Remove message-picture rule pieces before narrowing the section enum.

DELETE FROM `moderator_prompt_piece` WHERE `section` = 'message_picture_rule';

ALTER TABLE `moderator_prompt_piece`
    MODIFY COLUMN `section` ENUM('name_rule', 'message_rule', 'photo_rule') NOT NULL;
