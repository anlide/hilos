-- Retire the legacy moderator without changing framework rules or journal history.

DROP TRIGGER IF EXISTS `hilos_cl_moderator_prompt_piece_after_insert`;
DROP TRIGGER IF EXISTS `hilos_cl_moderator_prompt_piece_after_update`;
DROP TRIGGER IF EXISTS `hilos_cl_moderator_prompt_piece_after_delete`;

DROP TABLE `moderator_prompt_piece`;

DELETE FROM `hilos_setting` WHERE `key` IN (
    'chat_moderation_model',
    'chat_moderation_url',
    'chat_moderation_provider',
    'chat_moderation_timeout_sec'
);
