-- valid from migration #95
CREATE TRIGGER `hilos_cl_bot_after_delete` AFTER DELETE ON `bot`
FOR EACH ROW BEGIN
    DECLARE v_created_at DATETIME(6);
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = UTC_TIMESTAMP(6);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('bot');
    SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'bot';
    SET v_record_key = JSON_ARRAY(OLD.`id`);
    INSERT INTO {{change_log_database}}.`hilos_change_log`
        (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
        VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'delete');
    SET v_log_id = LAST_INSERT_ID();
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'name');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'name';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`name` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'description');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'description';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 0, NULL, NULL);
    SET v_change_id = LAST_INSERT_ID();
    INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
        VALUES (v_created_at, v_change_id, CAST(OLD.`description` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'style');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'style';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`style` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'topics');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'topics';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 0, NULL, NULL);
    SET v_change_id = LAST_INSERT_ID();
    INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
        VALUES (v_created_at, v_change_id, CAST(OLD.`topics` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'personality');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'personality';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 0, NULL, NULL);
    SET v_change_id = LAST_INSERT_ID();
    INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
        VALUES (v_created_at, v_change_id, CAST(OLD.`personality` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'active');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'active';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`active` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'reaction_delay_min');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'reaction_delay_min';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`reaction_delay_min` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'reaction_delay_max');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'reaction_delay_max';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`reaction_delay_max` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'reaction_chance');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'reaction_chance';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`reaction_chance` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'topic_match_required');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'topic_match_required';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`topic_match_required` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'cooldown_after_message');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'cooldown_after_message';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`cooldown_after_message` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'priority');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'priority';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`priority` AS CHAR), NULL);
END;
