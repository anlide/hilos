-- Migration Rollback: Drop Hilos analytics tables

DROP TABLE IF EXISTS `hilos_analytics_api_agent_action`;
DROP TABLE IF EXISTS `hilos_analytics_api_request`;
DROP TABLE IF EXISTS `hilos_analytics_worker_system_signal`;
DROP TABLE IF EXISTS `hilos_analytics_agent_cron_signal`;
DROP TABLE IF EXISTS `hilos_analytics_agent_system_signal`;
DROP TABLE IF EXISTS `hilos_analytics_agent_user_action`;
DROP TABLE IF EXISTS `hilos_analytics_user_action`;
DROP TABLE IF EXISTS `hilos_analytics_agent_session`;
DROP TABLE IF EXISTS `hilos_analytics_worker_session`;
DROP TABLE IF EXISTS `hilos_analytics_page_session`;
DROP TABLE IF EXISTS `hilos_analytics_ws_connection_ipv6_change`;
DROP TABLE IF EXISTS `hilos_analytics_ws_connection_ipv4_change`;
DROP TABLE IF EXISTS `hilos_analytics_ws_connection`;
DROP TABLE IF EXISTS `hilos_analytics_browser_session_accept_language_change`;
DROP TABLE IF EXISTS `hilos_analytics_browser_session_user_agent_change`;
DROP TABLE IF EXISTS `hilos_analytics_browser_session`;
DROP TABLE IF EXISTS `hilos_analytics_payload_json`;
DROP TABLE IF EXISTS `hilos_analytics_cron_name`;
DROP TABLE IF EXISTS `hilos_analytics_signal_name`;
DROP TABLE IF EXISTS `hilos_analytics_action_name`;
DROP TABLE IF EXISTS `hilos_analytics_page_params`;
DROP TABLE IF EXISTS `hilos_analytics_page`;
DROP TABLE IF EXISTS `hilos_analytics_accept_language`;
DROP TABLE IF EXISTS `hilos_analytics_user_agent`;
