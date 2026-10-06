-- valid from migration #85
CREATE TRIGGER `hilos_cl_hilos_second_factor_after_delete` AFTER DELETE ON `hilos_second_factor`
FOR EACH ROW BEGIN
    DECLARE v_created_at DATETIME(6);
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = UTC_TIMESTAMP(6);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('hilos_second_factor');
    SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'hilos_second_factor';
    SET v_record_key = JSON_ARRAY(OLD.`id`);
    INSERT INTO {{change_log_database}}.`hilos_change_log`
        (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
        VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'delete');
    SET v_log_id = LAST_INSERT_ID();
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'user_id');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'user_id';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'fact', 1, 0, NULL, NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'label');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'label';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'fact', 1, 0, NULL, NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'confirmed_at');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'confirmed_at';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'fact', 1, 0, NULL, NULL);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'created_at');
    SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'created_at';
    INSERT INTO {{change_log_database}}.`hilos_change_log_change`
        (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
        VALUES (v_created_at, v_log_id, v_field_id, 'fact', 1, 0, NULL, NULL);
END;
