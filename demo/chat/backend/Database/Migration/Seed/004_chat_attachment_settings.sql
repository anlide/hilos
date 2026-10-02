-- Seed: 004_chat_attachment_settings
-- Attachment size limits (bytes): the chat's per-file limit and the files registry's total (HIL-144).

INSERT INTO `hilos_setting` (`key`, `type`, `value`) VALUES
('chat_attachment_max_file_bytes', 'integer', '10485760'),
('files.max_total_bytes', 'integer', '104857600')
ON DUPLICATE KEY UPDATE `type` = VALUES(`type`), `value` = VALUES(`value`);
