-- valid from migration #85
CREATE TRIGGER `hilos_cl_hilos_setting_after_delete` AFTER DELETE ON `hilos_setting`
FOR EACH ROW BEGIN
    DECLARE v_created_at DATETIME(6);
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = UTC_TIMESTAMP(6);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('hilos_setting');
    SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'hilos_setting';
    SET v_record_key = JSON_ARRAY(OLD.`id`);
    INSERT INTO {{change_log_database}}.`hilos_change_log`
        (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
        VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'delete');
    SET v_log_id = LAST_INSERT_ID();
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'key');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'key';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`key` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'type');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'type';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`type` AS CHAR), NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'value');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'value';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'fact', 1, 0, NULL, NULL);
END;
