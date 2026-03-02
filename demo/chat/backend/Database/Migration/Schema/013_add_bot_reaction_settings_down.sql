-- Migration Rollback: Add bot reaction and behavior settings
-- Index: 013

ALTER TABLE `bot`
    DROP COLUMN `reaction_delay_min`,
    DROP COLUMN `reaction_delay_max`,
    DROP COLUMN `reaction_chance`,
    DROP COLUMN `topic_match_required`,
    DROP COLUMN `cooldown_after_message`,
    DROP COLUMN `priority`;
