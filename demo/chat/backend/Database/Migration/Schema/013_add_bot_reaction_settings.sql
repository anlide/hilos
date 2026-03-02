-- Migration: Add bot reaction and behavior settings
-- Created: 2026-03-02
-- Index: 013
-- Description: reaction_delay_min/max (AFK emulation), reaction_chance, topic_match_required,
--   cooldown_after_message, priority for bot reply scheduling

ALTER TABLE `bot`
    ADD COLUMN `reaction_delay_min` INT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Min delay before reacting (seconds), AFK emulation' AFTER `active`,
    ADD COLUMN `reaction_delay_max` INT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Max delay before reacting (seconds)' AFTER `reaction_delay_min`,
    ADD COLUMN `reaction_chance` TINYINT UNSIGNED NOT NULL DEFAULT 80 COMMENT 'Probability 0-100 to react when eligible' AFTER `reaction_delay_max`,
    ADD COLUMN `topic_match_required` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Participants: only react when topic matches their topics' AFTER `reaction_chance`,
    ADD COLUMN `cooldown_after_message` INT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'Seconds to wait after own message before next reaction' AFTER `topic_match_required`,
    ADD COLUMN `priority` INT NOT NULL DEFAULT 0 COMMENT 'Lower value = higher priority when multiple bots want to speak' AFTER `cooldown_after_message`;
