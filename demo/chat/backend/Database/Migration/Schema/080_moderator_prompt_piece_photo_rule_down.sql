-- Rollback: Remove photo rule pieces before narrowing the section enum.

DELETE FROM `moderator_prompt_piece` WHERE `section` = 'photo_rule';

ALTER TABLE `moderator_prompt_piece`
    MODIFY COLUMN `section` ENUM('name_rule', 'message_rule') NOT NULL;
