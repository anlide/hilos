-- Seed: 002_moderator_message_rules
-- Idempotent: replaces message_rule pieces with compact rules for faster prefill.
-- Safe to run multiple times.

DELETE FROM `moderator_prompt_piece` WHERE `section` = 'message_rule';

INSERT INTO `moderator_prompt_piece` (`section`, `prompt_piece`) VALUES
('message_rule', 'Allow benign. Block: insults, threats, hate speech, sexual, spam. Don''t block greetings, names, neutral.'),
('message_rule', 'Explicit text only; no inference. Uncertain→allow.'),
('message_rule', 'Reasons: ok, insult, threat, hate_speech, sexual, spam.');
