<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * Signal names used by framework-level Hilos admin pages.
 */
class HilosSignalConstants
{
    /** Subscription signal for Hilos dashboard page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DASHBOARD = 'subscription_page_hilos';

    /** Subscription signal for Hilos settings page. */
    public const string SUBSCRIPTION_PAGE_HILOS_SETTINGS = 'subscription_page_hilos_settings';

    /** Subscription signal for Hilos i18n hub page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N = 'subscription_page_hilos_i18n';

    /** Subscription signal for Hilos i18n languages list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGES = 'subscription_page_hilos_i18n_languages';

    /** Subscription signal for Hilos i18n countries list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRIES = 'subscription_page_hilos_i18n_countries';

    /** Subscription signal for Hilos i18n entities list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ENTITIES = 'subscription_page_hilos_i18n_entities';

    /** Subscription signal for Hilos i18n UI pages list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGES = 'subscription_page_hilos_i18n_ui_pages';

    /** Subscription signal for Hilos i18n groups list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_GROUPS = 'subscription_page_hilos_i18n_groups';

    /** Subscription signal for Hilos i18n actions list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ACTIONS = 'subscription_page_hilos_i18n_actions';

    /** Subscription signal for Hilos i18n emails list. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_EMAILS = 'subscription_page_hilos_i18n_emails';

    /** Subscription signal for Hilos i18n language detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE = 'subscription_page_hilos_i18n_language';

    /** Subscription signal for Hilos i18n country detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY = 'subscription_page_hilos_i18n_country';

    /** Subscription signal for Hilos i18n UI page detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGE = 'subscription_page_hilos_i18n_ui_page';

    /** Subscription signal for Hilos i18n group detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_GROUP = 'subscription_page_hilos_i18n_group';

    /** Subscription signal for Hilos i18n action detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ACTION = 'subscription_page_hilos_i18n_action';

    /** Subscription signal for Hilos i18n translate entity page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_ENTITY = 'subscription_page_hilos_i18n_translate_entity';

    /** Subscription signal for Hilos i18n translate UI page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_UI_PAGE = 'subscription_page_hilos_i18n_translate_ui_page';

    /** Subscription signal for Hilos i18n translate UI page item. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_UI_PAGE_ITEM = 'subscription_page_hilos_i18n_translate_ui_page_item';

    /** Subscription signal for Hilos i18n translate group page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_GROUP = 'subscription_page_hilos_i18n_translate_group';

    /** Subscription signal for Hilos i18n translate group item. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_GROUP_ITEM = 'subscription_page_hilos_i18n_translate_group_item';

    /** Subscription signal for Hilos i18n translate action error page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_ACTION_ERROR = 'subscription_page_hilos_i18n_translate_action_error';

    /** Subscription signal for Hilos i18n translate email page. */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_EMAIL = 'subscription_page_hilos_i18n_translate_email';

    /** Subscription signal for Hilos guardian page. */
    public const string SUBSCRIPTION_PAGE_HILOS_GUARDIAN = 'subscription_page_hilos_guardian';

    /** Subscription signal for Hilos guardian AI agent page. */
    public const string SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT = 'subscription_page_hilos_guardian_agent';

    /** Push signal for guardian agent status updates. */
    public const string GUARDIAN_AGENT_STATUS_UPDATE = 'guardian_agent_status_update';

    /** RT guardian agent status changed; project mapper expands this to frontend updates. */
    public const string EMIT_HILOS_GUARDIAN_AGENT_STATUS_UPDATED = 'emit_hilos_guardian_agent_status_updated';

    /** Subscription signal for Hilos analytics page. */
    public const string SUBSCRIPTION_PAGE_HILOS_ANALYTICS = 'subscription_page_hilos_analytics';

    /** Subscription signal for Hilos backup page. */
    public const string SUBSCRIPTION_PAGE_HILOS_BACKUP = 'subscription_page_hilos_backup';

    /** Subscription signal for Hilos daemon dashboard. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON = 'subscription_page_hilos_daemon';

    /** Subscription signal for Hilos daemon workers page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_WORKERS = 'subscription_page_hilos_daemon_workers';

    /** Subscription signal for Hilos daemon agents page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_AGENTS = 'subscription_page_hilos_daemon_agents';

    /** Subscription signal for Hilos daemon cron page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_CRON = 'subscription_page_hilos_daemon_cron';

    /** Subscription signal for Hilos daemon websockets page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_WEBSOCKETS = 'subscription_page_hilos_daemon_websockets';

    /** Subscription signal for Hilos daemon HTTP server page. */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_HTTP_SERVER = 'subscription_page_hilos_daemon_http_server';

    /** Subscription signal for Hilos logs overview page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS = 'subscription_page_hilos_logs';

    /** Subscription signal for Hilos logs by key page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS = 'subscription_page_hilos_logs_keys';

    /** Subscription signal for Hilos logs by worker page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS = 'subscription_page_hilos_logs_workers';

    /** Subscription signal for Hilos logs rotations page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS = 'subscription_page_hilos_logs_rotations';

    /** Subscription signal for Hilos logs viewer page. */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW = 'subscription_page_hilos_logs_view';

    /** Subscription signal for Hilos operations page. */
    public const string SUBSCRIPTION_PAGE_HILOS_OPERATIONS = 'subscription_page_hilos_operations';

    /** Subscription signal for Hilos users list. */
    public const string SUBSCRIPTION_PAGE_HILOS_USERS = 'subscription_page_hilos_users';

    /** Subscription signal for Hilos single user page. */
    public const string SUBSCRIPTION_PAGE_HILOS_USER = 'subscription_page_hilos_user';

    /** Subscription signal for Hilos roles list. */
    public const string SUBSCRIPTION_PAGE_HILOS_ROLES = 'subscription_page_hilos_roles';

    /** Subscription signal for Hilos MCP and Skills hub. */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS = 'subscription_page_hilos_mcp_skills';

    /** Subscription signal for Hilos single MCP page. */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP = 'subscription_page_hilos_mcp_skills_mcp';

    /** Subscription signal for Hilos MCP log overview page. */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP_LOGS = 'subscription_page_hilos_mcp_skills_mcp_logs';

    /** Subscription signal for Hilos MCP log viewer page. */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP_LOGS_VIEW = 'subscription_page_hilos_mcp_skills_mcp_logs_view';

    /** Subscription signal for Hilos SIL dashboard. */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL = 'subscription_page_hilos_sil';

    /** Subscription signal for Hilos SIL requests list. */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL_REQUESTS = 'subscription_page_hilos_sil_requests';

    /** Subscription signal for Hilos SIL user history page. */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL_USER_HISTORY = 'subscription_page_hilos_sil_user_history';

    /** Subscription signal for Hilos communications hub. */
    public const string SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS = 'subscription_page_hilos_communications';

    /** Subscription signal for Hilos communications channel page. */
    public const string SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL = 'subscription_page_hilos_communications_channel';

    /** Subscription signal for Hilos communications deliveries page. */
    public const string SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_DELIVERIES = 'subscription_page_hilos_communications_deliveries';

    /** Subscription signal for Hilos security hub. */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY = 'subscription_page_hilos_security';

    /** Subscription signal for Hilos security 2FA page. */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY_2FA = 'subscription_page_hilos_security_2fa';

    /** Subscription signal for Hilos OAuth providers list. */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY_OAUTH = 'subscription_page_hilos_security_oauth';

    /** Subscription signal for Hilos OAuth provider detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_SECURITY_OAUTH_PROVIDER = 'subscription_page_hilos_security_oauth_provider';

    /** Subscription signal for Hilos billing hub. */
    public const string SUBSCRIPTION_PAGE_HILOS_BILLING = 'subscription_page_hilos_billing';

    /** Subscription signal for Hilos billing provider detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_BILLING_PROVIDER = 'subscription_page_hilos_billing_provider';

    /** Subscription signal for Hilos billing payments page. */
    public const string SUBSCRIPTION_PAGE_HILOS_BILLING_PAYMENTS = 'subscription_page_hilos_billing_payments';

    /** Subscription signal for Hilos billing refunds page. */
    public const string SUBSCRIPTION_PAGE_HILOS_BILLING_REFUNDS = 'subscription_page_hilos_billing_refunds';

    /** Subscription signal for Hilos change log overview. */
    public const string SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG = 'subscription_page_hilos_change_log';

    /** Subscription signal for Hilos change log tables list. */
    public const string SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLES = 'subscription_page_hilos_change_log_tables';

    /** Subscription signal for Hilos change log table detail. */
    public const string SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE = 'subscription_page_hilos_change_log_table';

    /** Wire signal name for incremental table row mutations. */
    public const string TABLE_MUTATION = 'table_mutation';
}
