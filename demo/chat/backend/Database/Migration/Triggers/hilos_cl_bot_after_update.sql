-- valid from migration #95
CREATE TRIGGER `hilos_cl_bot_after_update` AFTER UPDATE ON `bot`
FOR EACH ROW BEGIN
    DECLARE v_created_at DATETIME(6);
    DECLARE v_table_id INT UNSIGNED;
    DECLARE v_log_id BIGINT UNSIGNED;
    DECLARE v_field_id INT UNSIGNED;
    DECLARE v_change_id BIGINT UNSIGNED;
    DECLARE v_record_key TEXT;
    SET v_created_at = UTC_TIMESTAMP(6);
    IF NOT (OLD.`id` <=> NEW.`id`) THEN
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
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('bot');
        SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'bot';
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'create');
        SET v_log_id = LAST_INSERT_ID();
    ELSEIF NOT (BINARY OLD.`name` <=> BINARY NEW.`name`) OR NOT (BINARY OLD.`description` <=> BINARY NEW.`description`) OR NOT (BINARY OLD.`style` <=> BINARY NEW.`style`) OR NOT (BINARY OLD.`topics` <=> BINARY NEW.`topics`) OR NOT (BINARY OLD.`personality` <=> BINARY NEW.`personality`) OR NOT (OLD.`active` <=> NEW.`active`) OR NOT (OLD.`reaction_delay_min` <=> NEW.`reaction_delay_min`) OR NOT (OLD.`reaction_delay_max` <=> NEW.`reaction_delay_max`) OR NOT (OLD.`reaction_chance` <=> NEW.`reaction_chance`) OR NOT (OLD.`topic_match_required` <=> NEW.`topic_match_required`) OR NOT (OLD.`cooldown_after_message` <=> NEW.`cooldown_after_message`) OR NOT (OLD.`priority` <=> NEW.`priority`) THEN
        INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_table` (`name`) VALUES ('bot');
        SELECT `id` INTO v_table_id FROM {{change_log_database}}.`hilos_change_log_table` WHERE `name` = 'bot';
        SET v_record_key = JSON_ARRAY(NEW.`id`);
        INSERT INTO {{change_log_database}}.`hilos_change_log`
            (`created_at`, `receipt_id`, `table_id`, `record_key`, `record_key_hash`, `mutation_type`)
            VALUES (v_created_at, @hilos_receipt, v_table_id, v_record_key, UNHEX(SHA2(v_record_key, 256)), 'update');
        SET v_log_id = LAST_INSERT_ID();
        IF NOT (BINARY OLD.`name` <=> BINARY NEW.`name`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'name');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'name';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`name` AS CHAR), CAST(NEW.`name` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`description` <=> BINARY NEW.`description`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'description');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'description';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 1, NULL, NULL);
            SET v_change_id = LAST_INSERT_ID();
            INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
                VALUES (v_created_at, v_change_id, CAST(OLD.`description` AS CHAR), CAST(NEW.`description` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`style` <=> BINARY NEW.`style`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'style');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'style';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`style` AS CHAR), CAST(NEW.`style` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`topics` <=> BINARY NEW.`topics`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'topics');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'topics';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 1, NULL, NULL);
            SET v_change_id = LAST_INSERT_ID();
            INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
                VALUES (v_created_at, v_change_id, CAST(OLD.`topics` AS CHAR), CAST(NEW.`topics` AS CHAR));
        END IF;
        IF NOT (BINARY OLD.`personality` <=> BINARY NEW.`personality`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'personality');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'personality';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'long', 1, 1, NULL, NULL);
            SET v_change_id = LAST_INSERT_ID();
            INSERT INTO {{change_log_database}}.`hilos_change_log_value` (`created_at`, `change_id`, `old_value`, `new_value`)
                VALUES (v_created_at, v_change_id, CAST(OLD.`personality` AS CHAR), CAST(NEW.`personality` AS CHAR));
        END IF;
        IF NOT (OLD.`active` <=> NEW.`active`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'active');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'active';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`active` AS CHAR), CAST(NEW.`active` AS CHAR));
        END IF;
        IF NOT (OLD.`reaction_delay_min` <=> NEW.`reaction_delay_min`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'reaction_delay_min');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'reaction_delay_min';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`reaction_delay_min` AS CHAR), CAST(NEW.`reaction_delay_min` AS CHAR));
        END IF;
        IF NOT (OLD.`reaction_delay_max` <=> NEW.`reaction_delay_max`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'reaction_delay_max');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'reaction_delay_max';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`reaction_delay_max` AS CHAR), CAST(NEW.`reaction_delay_max` AS CHAR));
        END IF;
        IF NOT (OLD.`reaction_chance` <=> NEW.`reaction_chance`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'reaction_chance');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'reaction_chance';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`reaction_chance` AS CHAR), CAST(NEW.`reaction_chance` AS CHAR));
        END IF;
        IF NOT (OLD.`topic_match_required` <=> NEW.`topic_match_required`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'topic_match_required');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'topic_match_required';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`topic_match_required` AS CHAR), CAST(NEW.`topic_match_required` AS CHAR));
        END IF;
        IF NOT (OLD.`cooldown_after_message` <=> NEW.`cooldown_after_message`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'cooldown_after_message');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'cooldown_after_message';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`cooldown_after_message` AS CHAR), CAST(NEW.`cooldown_after_message` AS CHAR));
        END IF;
        IF NOT (OLD.`priority` <=> NEW.`priority`) THEN
            INSERT IGNORE INTO {{change_log_database}}.`hilos_change_log_field` (`table_id`, `name`) VALUES (v_table_id, 'priority');
            SELECT `id` INTO v_field_id FROM {{change_log_database}}.`hilos_change_log_field` WHERE `table_id` = v_table_id AND `name` = 'priority';
            INSERT INTO {{change_log_database}}.`hilos_change_log_change`
                (`created_at`, `log_id`, `field_id`, `kind`, `old_present`, `new_present`, `old_value`, `new_value`)
                VALUES (v_created_at, v_log_id, v_field_id, 'inline', 1, 1, CAST(OLD.`priority` AS CHAR), CAST(NEW.`priority` AS CHAR));
        END IF;
    END IF;
END;
