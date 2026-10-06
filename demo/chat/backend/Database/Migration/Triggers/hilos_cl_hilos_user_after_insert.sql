-- valid from migration #85
CREATE TRIGGER `hilos_cl_hilos_user_after_insert` AFTER INSERT ON `hilos_user`
FOR EACH ROW BEGIN
    DECLARE v_created_at DATETIME(6);
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = UTC_TIMESTAMP(6);
    INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('hilos_user');
    SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'hilos_user';
    SET v_record_key = JSON_ARRAY(NEW.`id`);
    INSERT INTO {{change_log_database}}.`hilos_change_log`
        (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
        VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'create');
    SET v_log_id = LAST_INSERT_ID();
END;
