<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HilosPageConstants - Page constants for Hilos admin section.
 *
 * Defines page identifiers for framework-level Hilos admin pages.
 * Projects inherit these pages via HilosPageFactory.
 */
final class HilosPageConstants
{
    /** @var string Hilos dashboard (main page of hilos section) */
    public const string HILOS_DASHBOARD = 'hilos';

    /** @var string Hilos current-user profile page */
    public const string HILOS_PROFILE = 'hilos_profile';

    /** @var string Hilos settings page */
    public const string HILOS_SETTINGS = 'hilos_settings';

    /** @var string Hilos internationalization hub */
    public const string HILOS_I18N = 'hilos_i18n';

    /** @var string Hilos I18n — languages list */
    public const string HILOS_I18N_LANGUAGES = 'hilos_i18n_languages';

    /** @var string Hilos I18n — countries list */
    public const string HILOS_I18N_COUNTRIES = 'hilos_i18n_countries';

    /** @var string Hilos I18n — entities list */
    public const string HILOS_I18N_ENTITIES = 'hilos_i18n_entities';

    /** @var string Hilos I18n — UI pages list */
    public const string HILOS_I18N_UI_PAGES = 'hilos_i18n_ui_pages';

    /** @var string Hilos I18n — groups list */
    public const string HILOS_I18N_GROUPS = 'hilos_i18n_groups';

    /** @var string Hilos I18n — actions list */
    public const string HILOS_I18N_ACTIONS = 'hilos_i18n_actions';

    /** @var string Hilos I18n — emails list */
    public const string HILOS_I18N_EMAILS = 'hilos_i18n_emails';

    /** @var string Hilos I18n — single language */
    public const string HILOS_I18N_LANGUAGE = 'hilos_i18n_language';

    /** @var string Hilos I18n — single country */
    public const string HILOS_I18N_COUNTRY = 'hilos_i18n_country';

    /** @var string Hilos I18n — single UI page */
    public const string HILOS_I18N_UI_PAGE = 'hilos_i18n_ui_page';

    /** @var string Hilos I18n — single group */
    public const string HILOS_I18N_GROUP = 'hilos_i18n_group';

    /** @var string Hilos I18n — single action */
    public const string HILOS_I18N_ACTION = 'hilos_i18n_action';

    /** @var string Hilos I18n — translate entity */
    public const string HILOS_I18N_TRANSLATE_ENTITY = 'hilos_i18n_translate_entity';

    /** @var string Hilos I18n — translate UI page */
    public const string HILOS_I18N_TRANSLATE_UI_PAGE = 'hilos_i18n_translate_ui_page';

    /** @var string Hilos I18n — translate UI page item */
    public const string HILOS_I18N_TRANSLATE_UI_PAGE_ITEM = 'hilos_i18n_translate_ui_page_item';

    /** @var string Hilos I18n — translate group */
    public const string HILOS_I18N_TRANSLATE_GROUP = 'hilos_i18n_translate_group';

    /** @var string Hilos I18n — translate group item */
    public const string HILOS_I18N_TRANSLATE_GROUP_ITEM = 'hilos_i18n_translate_group_item';

    /** @var string Hilos I18n — translate action error */
    public const string HILOS_I18N_TRANSLATE_ACTION_ERROR = 'hilos_i18n_translate_action_error';

    /** @var string Hilos I18n — translate email */
    public const string HILOS_I18N_TRANSLATE_EMAIL = 'hilos_i18n_translate_email';

    /** @var string Hilos guardian page (project validation robots) */
    public const string HILOS_GUARDIAN = 'hilos_guardian';

    /** @var string Hilos guardian AI agent page */
    public const string HILOS_GUARDIAN_AGENT = 'hilos_guardian_agent';

    /** @var string Hilos analytics page (visit statistics) */
    public const string HILOS_ANALYTICS = 'hilos_analytics';

    /** @var string Hilos backups list */
    public const string HILOS_BACKUP = 'hilos_backup';

    /** @var string Hilos daemon dashboard */
    public const string HILOS_DAEMON = 'hilos_daemon';

    /** @var string Hilos daemon — workers list */
    public const string HILOS_DAEMON_WORKERS = 'hilos_daemon_workers';

    /** @var string Hilos daemon — agents list */
    public const string HILOS_DAEMON_AGENTS = 'hilos_daemon_agents';

    /** @var string Hilos daemon — cron list */
    public const string HILOS_DAEMON_CRON = 'hilos_daemon_cron';

    /** @var string Hilos daemon — websocket connections list */
    public const string HILOS_DAEMON_WEBSOCKETS = 'hilos_daemon_websockets';

    /** @var string Hilos daemon — HTTP server detail */
    public const string HILOS_DAEMON_HTTP_SERVER = 'hilos_daemon_http_server';

    /** @var string Hilos logs overview */
    public const string HILOS_LOGS = 'hilos_logs';

    /** @var string Hilos logs — by key */
    public const string HILOS_LOGS_KEYS = 'hilos_logs_keys';

    /** @var string Hilos logs — by worker */
    public const string HILOS_LOGS_WORKERS = 'hilos_logs_workers';

    /** @var string Hilos logs — rotation history */
    public const string HILOS_LOGS_ROTATIONS = 'hilos_logs_rotations';

    /** @var string Hilos logs — viewer */
    public const string HILOS_LOGS_VIEW = 'hilos_logs_view';

    /** @var string Hilos — maintenance operations (sitemap, static build, etc.) */
    public const string HILOS_OPERATIONS = 'hilos_operations';

    /** @var string Hilos — users list */
    public const string HILOS_USERS = 'hilos_users';

    /** @var string Hilos — single user (view/edit) */
    public const string HILOS_USER = 'hilos_user';

    /** @var string Hilos — current-user notification center (bell + list) */
    public const string HILOS_NOTIFICATIONS = 'hilos_notifications';

    /** @var string Hilos — roles list */
    public const string HILOS_ROLES = 'hilos_roles';

    /** @var string Hilos — MCP and Skills hub */
    public const string HILOS_MCP_SKILLS = 'hilos_mcp_skills';

    /** @var string Hilos — single MCP (detail) */
    public const string HILOS_MCP_SKILLS_MCP = 'hilos_mcp_skills_mcp';

    /** @var string Hilos — MCP usage / log overview */
    public const string HILOS_MCP_SKILLS_MCP_LOGS = 'hilos_mcp_skills_mcp_logs';

    /** @var string Hilos — MCP log viewer */
    public const string HILOS_MCP_SKILLS_MCP_LOGS_VIEW = 'hilos_mcp_skills_mcp_logs_view';

    /** @var string Hilos — System Intelligence Layer dashboard */
    public const string HILOS_SIL = 'hilos_sil';

    /** @var string Hilos — SIL requests list */
    public const string HILOS_SIL_REQUESTS = 'hilos_sil_requests';

    /** @var string Hilos — SIL user history */
    public const string HILOS_SIL_USER_HISTORY = 'hilos_sil_user_history';

    /** @var string Hilos — communications channels hub */
    public const string HILOS_COMMUNICATIONS = 'hilos_communications';

    /** @var string Hilos — single communication channel (detail) */
    public const string HILOS_COMMUNICATIONS_CHANNEL = 'hilos_communications_channel';

    /** @var string Hilos — channel deliveries log */
    public const string HILOS_COMMUNICATIONS_DELIVERIES = 'hilos_communications_deliveries';

    /** @var string Hilos — security center overview */
    public const string HILOS_SECURITY = 'hilos_security';

    /** @var string Hilos — two-factor authentication */
    public const string HILOS_SECURITY_2FA = 'hilos_security_2fa';

    /** @var string Hilos — OAuth login providers list */
    public const string HILOS_SECURITY_OAUTH = 'hilos_security_oauth';

    /** @var string Hilos — single OAuth provider (detail) */
    public const string HILOS_SECURITY_OAUTH_PROVIDER = 'hilos_security_oauth_provider';

    /** @var string Hilos — billing (payment providers) hub */
    public const string HILOS_BILLING = 'hilos_billing';

    /** @var string Hilos — single payment provider (config) */
    public const string HILOS_BILLING_PROVIDER = 'hilos_billing_provider';

    /** @var string Hilos — payments for a provider */
    public const string HILOS_BILLING_PAYMENTS = 'hilos_billing_payments';

    /** @var string Hilos — refunds for a provider */
    public const string HILOS_BILLING_REFUNDS = 'hilos_billing_refunds';

    /** @var string Hilos — change log dashboard (audit triggers / config) */
    public const string HILOS_CHANGE_LOG = 'hilos_change_log';

    /** @var string Hilos — change log tracked tables list */
    public const string HILOS_CHANGE_LOG_TABLES = 'hilos_change_log_tables';

    /** @var string Hilos — change log single tracked table detail */
    public const string HILOS_CHANGE_LOG_TABLE = 'hilos_change_log_table';

    /** @var string Hilos — public About page (static, content-only) */
    public const string HILOS_ABOUT = 'hilos_about';

    /** @var string Hilos — public Terms page (static, content-only) */
    public const string HILOS_TERMS = 'hilos_terms';

    /** @var string Hilos — public Privacy page (static, content-only) */
    public const string HILOS_PRIVACY = 'hilos_privacy';

    /** @var string Hilos — public License page (static, content-only) */
    public const string HILOS_LICENSE = 'hilos_license';
}
