-- Migration Rollback: Create Hilos change log tables
-- Created: 2026-04-07
-- Index: 016

DROP TABLE IF EXISTS `hilos_change_log_value`;
DROP TABLE IF EXISTS `hilos_change_log`;
DROP TABLE IF EXISTS `hilos_change_log_field`;
DROP TABLE IF EXISTS `hilos_change_log_table`;
