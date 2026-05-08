-- Seed: 003_chat_settings
-- Idempotent: inserts or updates chat LLM settings and one orphan record for testing.
-- Safe to run multiple times.
-- Orphan key `orphan_test_demo` is NOT in SettingsCatalog — use to test orphan detection/removal.

INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES
('default_bot_model', 'string', 'qwen2.5:3b'),
('default_bot_url', 'string', ''),
('default_bot_provider', 'string', 'local'),
('default_bot_timeout_sec', 'float', '90'),
('chat_bot_model', 'string', NULL),
('chat_bot_url', 'string', NULL),
('chat_bot_provider', 'string', NULL),
('chat_bot_timeout_sec', 'float', NULL),
('chat_bot_language', 'string', 'ru'),
('chat_moderation_model', 'string', NULL),
('chat_moderation_url', 'string', NULL),
('chat_moderation_provider', 'string', NULL),
('chat_moderation_timeout_sec', 'float', NULL),
('orphan_test_demo', 'string', 'leftover_from_migration')
ON DUPLICATE KEY UPDATE `type` = VALUES(`type`), `value` = VALUES(`value`);
