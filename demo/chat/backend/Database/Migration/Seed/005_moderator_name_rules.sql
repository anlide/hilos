-- Seed: 005_moderator_name_rules
-- Idempotent: replaces name_rule pieces with compact rules for rename moderation.
-- Safe to run multiple times.

DELETE FROM `moderator_prompt_piece` WHERE `section` = 'name_rule';

INSERT INTO `moderator_prompt_piece` (`section`, `prompt_piece`) VALUES
('name_rule', 'Allow benign display names. Block: insults, threats, hate speech, sexual, spam, impersonation.'),
('name_rule', 'Explicit text only; no inference. Uncertain→allow.'),
('name_rule', 'Reasons: ok, insult, threat, hate_speech, sexual, spam, impersonation.');
