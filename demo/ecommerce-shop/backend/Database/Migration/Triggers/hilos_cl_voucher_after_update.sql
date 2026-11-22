-- valid from migration #125
CREATE TRIGGER `hilos_cl_voucher_after_update` AFTER UPDATE ON `voucher`
FOR EACH ROW BEGIN
    DECLARE v_created_at BIGINT;
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP(6)) DIV 1000;
    IF NOT (OLD.`id` <=> NEW.`id`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('voucher');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'voucher');
        SET v_record_key = JSON_ARRAY(OLD.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'delete');
        SET v_log_id = LAST_INSERT_ID();
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'code');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'code');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`code` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'discount_percent');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'discount_percent');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`discount_percent` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'active');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'active');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`active` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('voucher');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'voucher');
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'create');
        SET v_log_id = LAST_INSERT_ID();
    ELSEIF NOT (BINARY OLD.`code` <=> BINARY NEW.`code`) OR NOT (OLD.`discount_percent` <=> NEW.`discount_percent`) OR NOT (OLD.`active` <=> NEW.`active`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('voucher');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'voucher');
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'update');
        SET v_log_id = LAST_INSERT_ID();
        IF NOT (BINARY OLD.`code` <=> BINARY NEW.`code`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'code');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'code');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`code` AS CHAR), CAST(NEW.`code` AS CHAR));
        END IF;
        IF NOT (OLD.`discount_percent` <=> NEW.`discount_percent`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'discount_percent');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'discount_percent');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`discount_percent` AS CHAR), CAST(NEW.`discount_percent` AS CHAR));
        END IF;
        IF NOT (OLD.`active` <=> NEW.`active`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'active');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'active');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`active` AS CHAR), CAST(NEW.`active` AS CHAR));
        END IF;
    END IF;
END;
