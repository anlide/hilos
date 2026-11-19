-- valid from migration #121
CREATE TRIGGER `hilos_cl_product_warehouse_stock_after_update` AFTER UPDATE ON `product_warehouse_stock`
FOR EACH ROW BEGIN
    DECLARE v_created_at BIGINT;
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP(6)) DIV 1000;
    IF NOT (OLD.`id` <=> NEW.`id`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('product_warehouse_stock');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'product_warehouse_stock');
        SET v_record_key = JSON_ARRAY(OLD.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'delete');
        SET v_log_id = LAST_INSERT_ID();
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'product_id');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'product_id');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`product_id` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'warehouse_id');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'warehouse_id');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`warehouse_id` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'units');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'units');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`units` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('product_warehouse_stock');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'product_warehouse_stock');
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'create');
        SET v_log_id = LAST_INSERT_ID();
    ELSEIF NOT (OLD.`product_id` <=> NEW.`product_id`) OR NOT (OLD.`warehouse_id` <=> NEW.`warehouse_id`) OR NOT (OLD.`units` <=> NEW.`units`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('product_warehouse_stock');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'product_warehouse_stock');
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'update');
        SET v_log_id = LAST_INSERT_ID();
        IF NOT (OLD.`product_id` <=> NEW.`product_id`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'product_id');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'product_id');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`product_id` AS CHAR), CAST(NEW.`product_id` AS CHAR));
        END IF;
        IF NOT (OLD.`warehouse_id` <=> NEW.`warehouse_id`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'warehouse_id');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'warehouse_id');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`warehouse_id` AS CHAR), CAST(NEW.`warehouse_id` AS CHAR));
        END IF;
        IF NOT (OLD.`units` <=> NEW.`units`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'units');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'units');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`units` AS CHAR), CAST(NEW.`units` AS CHAR));
        END IF;
    END IF;
END;
