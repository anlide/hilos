-- Migration: Allow message-picture rules beside the moderator's other prompt sections.
-- Index: 095

ALTER TABLE `moderator_prompt_piece`
    MODIFY COLUMN `section` ENUM('name_rule', 'message_rule', 'photo_rule', 'message_picture_rule') NOT NULL;
