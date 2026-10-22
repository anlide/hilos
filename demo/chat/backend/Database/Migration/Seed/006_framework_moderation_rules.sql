-- Seed: 006_framework_moderation_rules
-- Idempotent: replaces the own rules of the chat message and profile name kinds in the framework
-- table hilos_moderation_rule with the texts of seeds 002 and 005, in the same order.
-- Rows of every other kind stay untouched. Safe to run multiple times.

DELETE FROM `hilos_moderation_rule` WHERE `kind` IN ('chat_message', 'hilos_profile_name');

INSERT INTO `hilos_moderation_rule` (`kind`, `rule`) VALUES
('chat_message', 'Allow benign. Block: insults, threats, hate speech, sexual, spam. Don''t block greetings, names, neutral.'),
('chat_message', 'Explicit text only; no inference. Uncertain→allow. Attachments never cause a block.'),
('chat_message', 'Reasons: ok, insult, threat, hate_speech, sexual, spam.'),
('hilos_profile_name', 'Allow benign display names. Block: insults, threats, hate speech, sexual, spam, impersonation.'),
('hilos_profile_name', 'Explicit text only; no inference. Uncertain→allow.'),
('hilos_profile_name', 'Reasons: ok, insult, threat, hate_speech, sexual, spam, impersonation.');
