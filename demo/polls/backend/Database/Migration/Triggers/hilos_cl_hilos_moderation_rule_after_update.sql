-- valid from migration #70
CREATE TRIGGER `hilos_cl_hilos_moderation_rule_after_update` AFTER UPDATE ON `hilos_moderation_rule`
FOR EACH ROW BEGIN
    DECLARE v_created_at DATETIME(6);
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = UTC_TIMESTAMP(6);
    IF NOT (OLD.`id` <=> NEW.`id`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('hilos_moderation_rule');
        SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'hilos_moderation_rule';
        SET v_record_key = JSON_ARRAY(OLD.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'delete');
        SET v_log_id = LAST_INSERT_ID();
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'kind');
        SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'kind';
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`kind` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'rule');
        SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'rule';
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 0, NULL, NULL);
        SET v_change_id = LAST_INSERT_ID();
        INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
            VALUES (v_created_at, v_change_id, CAST(OLD.`rule` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('hilos_moderation_rule');
        SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'hilos_moderation_rule';
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'create');
        SET v_log_id = LAST_INSERT_ID();
    ELSEIF NOT (BINARY OLD.`kind` <=> BINARY NEW.`kind`) OR NOT (BINARY OLD.`rule` <=> BINARY NEW.`rule`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('hilos_moderation_rule');
        SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'hilos_moderation_rule';
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'update');
        SET v_log_id = LAST_INSERT_ID();
        IF NOT (BINARY OLD.`kind` <=> BINARY NEW.`kind`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'kind');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'kind';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`kind` AS CHAR), CAST(NEW.`kind` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`rule` <=> BINARY NEW.`rule`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'rule');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'rule';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 1, NULL, NULL);
            SET v_change_id = LAST_INSERT_ID();
            INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
                VALUES (v_created_at, v_change_id, CAST(OLD.`rule` AS CHAR), CAST(NEW.`rule` AS CHAR));
        END IF;
    END IF;
END;
