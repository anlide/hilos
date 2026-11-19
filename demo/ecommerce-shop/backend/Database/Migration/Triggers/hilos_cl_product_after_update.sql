-- valid from migration #120
CREATE TRIGGER `hilos_cl_product_after_update` AFTER UPDATE ON `product`
FOR EACH ROW BEGIN
    DECLARE v_created_at BIGINT;
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = TIMESTAMPDIFF(MICROSECOND, '1970-01-01 00:00:00', UTC_TIMESTAMP(6)) DIV 1000;
    IF NOT (OLD.`id` <=> NEW.`id`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('product');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'product');
        SET v_record_key = JSON_ARRAY(OLD.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'delete');
        SET v_log_id = LAST_INSERT_ID();
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'name');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'name');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`name` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'category_id');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'category_id');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`category_id` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'kind');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'kind');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`kind` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'price');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'price');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`price` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'description');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'description');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`description` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'picture_file_id');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'picture_file_id');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`picture_file_id` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'slug');
        SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'slug');
        INSERT INTO {{change_log_database}}.`hilos_change_log_change`
            (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
            VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 0, CAST(OLD.`slug` AS CHAR), NULL);
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('product');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'product');
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'create');
        SET v_log_id = LAST_INSERT_ID();
    ELSEIF NOT (BINARY OLD.`name` <=> BINARY NEW.`name`) OR NOT (OLD.`category_id` <=> NEW.`category_id`) OR NOT (BINARY OLD.`kind` <=> BINARY NEW.`kind`) OR NOT (OLD.`price` <=> NEW.`price`) OR NOT (BINARY OLD.`description` <=> BINARY NEW.`description`) OR NOT (OLD.`picture_file_id` <=> NEW.`picture_file_id`) OR NOT (BINARY OLD.`slug` <=> BINARY NEW.`slug`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('product');
        SET v_table_id = (SELECT dt_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_table` AS dt_lookup WHERE dt_lookup.`name` = 'product');
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'update');
        SET v_log_id = LAST_INSERT_ID();
        IF NOT (BINARY OLD.`name` <=> BINARY NEW.`name`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'name');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'name');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`name` AS CHAR), CAST(NEW.`name` AS CHAR));
        END IF;
        IF NOT (OLD.`category_id` <=> NEW.`category_id`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'category_id');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'category_id');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`category_id` AS CHAR), CAST(NEW.`category_id` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`kind` <=> BINARY NEW.`kind`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'kind');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'kind');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`kind` AS CHAR), CAST(NEW.`kind` AS CHAR));
        END IF;
        IF NOT (OLD.`price` <=> NEW.`price`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'price');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'price');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`price` AS CHAR), CAST(NEW.`price` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`description` <=> BINARY NEW.`description`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'description');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'description');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`description` AS CHAR), CAST(NEW.`description` AS CHAR));
        END IF;
        IF NOT (OLD.`picture_file_id` <=> NEW.`picture_file_id`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'picture_file_id');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'picture_file_id');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`picture_file_id` AS CHAR), CAST(NEW.`picture_file_id` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`slug` <=> BINARY NEW.`slug`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'slug');
            SET v_field_id = (SELECT df_lookup.`id` FROM {{change_log_database}}.`hilos_change_log_field` AS df_lookup WHERE df_lookup.`table_id` = v_table_id AND df_lookup.`name` = 'slug');
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`slug` AS CHAR), CAST(NEW.`slug` AS CHAR));
        END IF;
    END IF;
END;
