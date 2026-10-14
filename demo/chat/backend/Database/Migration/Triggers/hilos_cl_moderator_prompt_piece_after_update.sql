-- valid from migration #95
CREATE TRIGGER `hilos_cl_moderator_prompt_piece_after_update` AFTER UPDATE ON `moderator_prompt_piece`
FOR EACH ROW BEGIN
    DECLARE v_created_at DATETIME(6);
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = UTC_TIMESTAMP(6);
    IF NOT (OLD.`id` <=> NEW.`id`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('moderator_prompt_piece');
        SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'moderator_prompt_piece';
        SET v_record_key = JSON_ARRAY(OLD.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'delete');
        SET v_log_id = LAST_INSERT_ID();
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'section');
        SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'section';
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`section` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'prompt_piece');
        SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'prompt_piece';
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 0, NULL, NULL);
        SET v_change_id = LAST_INSERT_ID();
        INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
            VALUES (v_created_at, v_change_id, CAST(OLD.`prompt_piece` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('moderator_prompt_piece');
        SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'moderator_prompt_piece';
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'create');
        SET v_log_id = LAST_INSERT_ID();
    ELSEIF NOT (BINARY OLD.`section` <=> BINARY NEW.`section`) OR NOT (BINARY OLD.`prompt_piece` <=> BINARY NEW.`prompt_piece`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('moderator_prompt_piece');
        SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'moderator_prompt_piece';
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'update');
        SET v_log_id = LAST_INSERT_ID();
        IF NOT (BINARY OLD.`section` <=> BINARY NEW.`section`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'section');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'section';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`section` AS CHAR), CAST(NEW.`section` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`prompt_piece` <=> BINARY NEW.`prompt_piece`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'prompt_piece');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'prompt_piece';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 1, NULL, NULL);
            SET v_change_id = LAST_INSERT_ID();
            INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
                VALUES (v_created_at, v_change_id, CAST(OLD.`prompt_piece` AS CHAR), CAST(NEW.`prompt_piece` AS CHAR));
        END IF;
    END IF;
END;
