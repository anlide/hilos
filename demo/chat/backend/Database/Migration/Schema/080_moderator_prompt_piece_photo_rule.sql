-- Migration: Allow photo rules beside the moderator's message and name rules.
-- Index: 080 (078 is the person foreign-key migration from HIL-1202).

ALTER TABLE `moderator_prompt_piece`
    MODIFY COLUMN `section` ENUM('name_rule', 'message_rule', 'photo_rule') NOT NULL;
