<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HilosSignalConstants - Signal constants for Hilos admin pages.
 *
 * Defines signal names used by framework-level Hilos admin pages.
 */
class HilosSignalConstants
{
    /** @var string Subscription signal for Hilos dashboard page */
    public const string SUBSCRIPTION_PAGE_HILOS_DASHBOARD = 'subscription_page_hilos';

    /** @var string Subscription signal for Hilos settings page */
    public const string SUBSCRIPTION_PAGE_HILOS_SETTINGS = 'subscription_page_hilos_settings';

    /** @var string Subscription signal for Hilos i18n hub page */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N = 'subscription_page_hilos_i18n';

    /** @var string Subscription signal for Hilos i18n languages list */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGES = 'subscription_page_hilos_i18n_languages';

    /** @var string Subscription signal for Hilos i18n countries list */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRIES = 'subscription_page_hilos_i18n_countries';

    /** @var string Subscription signal for Hilos i18n entities list */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ENTITIES = 'subscription_page_hilos_i18n_entities';

    /** @var string Subscription signal for Hilos i18n UI pages list */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGES = 'subscription_page_hilos_i18n_ui_pages';

    /** @var string Subscription signal for Hilos i18n groups list */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_GROUPS = 'subscription_page_hilos_i18n_groups';

    /** @var string Subscription signal for Hilos i18n actions list */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ACTIONS = 'subscription_page_hilos_i18n_actions';

    /** @var string Subscription signal for Hilos i18n emails list */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_EMAILS = 'subscription_page_hilos_i18n_emails';

    /** @var string Subscription signal for Hilos i18n language detail */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_LANGUAGE = 'subscription_page_hilos_i18n_language';

    /** @var string Subscription signal for Hilos i18n country detail */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_COUNTRY = 'subscription_page_hilos_i18n_country';

    /** @var string Subscription signal for Hilos i18n UI page detail */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_UI_PAGE = 'subscription_page_hilos_i18n_ui_page';

    /** @var string Subscription signal for Hilos i18n group detail */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_GROUP = 'subscription_page_hilos_i18n_group';

    /** @var string Subscription signal for Hilos i18n action detail */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_ACTION = 'subscription_page_hilos_i18n_action';

    /** @var string Subscription signal for Hilos i18n translate entity */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_ENTITY = 'subscription_page_hilos_i18n_translate_entity';

    /** @var string Subscription signal for Hilos i18n translate UI page */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_UI_PAGE = 'subscription_page_hilos_i18n_translate_ui_page';

    /** @var string Subscription signal for Hilos i18n translate UI page item */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_UI_PAGE_ITEM = 'subscription_page_hilos_i18n_translate_ui_page_item';

    /** @var string Subscription signal for Hilos i18n translate group */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_GROUP = 'subscription_page_hilos_i18n_translate_group';

    /** @var string Subscription signal for Hilos i18n translate group item */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_GROUP_ITEM = 'subscription_page_hilos_i18n_translate_group_item';

    /** @var string Subscription signal for Hilos i18n translate action error */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_ACTION_ERROR = 'subscription_page_hilos_i18n_translate_action_error';

    /** @var string Subscription signal for Hilos i18n translate email */
    public const string SUBSCRIPTION_PAGE_HILOS_I18N_TRANSLATE_EMAIL = 'subscription_page_hilos_i18n_translate_email';

    /** @var string Subscription signal for Hilos guardian page */
    public const string SUBSCRIPTION_PAGE_HILOS_GUARDIAN = 'subscription_page_hilos_guardian';

    /** @var string Subscription signal for Hilos guardian AI agent page */
    public const string SUBSCRIPTION_PAGE_HILOS_GUARDIAN_AGENT = 'subscription_page_hilos_guardian_agent';

    /** @var string Subscription signal for Hilos analytics page */
    public const string SUBSCRIPTION_PAGE_HILOS_ANALYTICS = 'subscription_page_hilos_analytics';

    /** @var string Subscription signal for Hilos backup page */
    public const string SUBSCRIPTION_PAGE_HILOS_BACKUP = 'subscription_page_hilos_backup';

    /** @var string Subscription signal for Hilos daemon dashboard */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON = 'subscription_page_hilos_daemon';

    /** @var string Subscription signal for Hilos daemon workers */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_WORKERS = 'subscription_page_hilos_daemon_workers';

    /** @var string Subscription signal for Hilos daemon agents */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_AGENTS = 'subscription_page_hilos_daemon_agents';

    /** @var string Subscription signal for Hilos daemon cron */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_CRON = 'subscription_page_hilos_daemon_cron';

    /** @var string Subscription signal for Hilos daemon websockets */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_WEBSOCKETS = 'subscription_page_hilos_daemon_websockets';

    /** @var string Subscription signal for Hilos daemon HTTP server */
    public const string SUBSCRIPTION_PAGE_HILOS_DAEMON_HTTP_SERVER = 'subscription_page_hilos_daemon_http_server';

    /** @var string Subscription signal for Hilos logs overview */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS = 'subscription_page_hilos_logs';

    /** @var string Subscription signal for Hilos logs by key */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS = 'subscription_page_hilos_logs_keys';

    /** @var string Subscription signal for Hilos logs by worker */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS = 'subscription_page_hilos_logs_workers';

    /** @var string Subscription signal for Hilos logs rotations */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS = 'subscription_page_hilos_logs_rotations';

    /** @var string Subscription signal for Hilos logs viewer */
    public const string SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW = 'subscription_page_hilos_logs_view';

    /** @var string Subscription signal for Hilos operations page */
    public const string SUBSCRIPTION_PAGE_HILOS_OPERATIONS = 'subscription_page_hilos_operations';

    /** @var string Subscription signal for Hilos users list */
    public const string SUBSCRIPTION_PAGE_HILOS_USERS = 'subscription_page_hilos_users';

    /** @var string Subscription signal for Hilos single user page */
    public const string SUBSCRIPTION_PAGE_HILOS_USER = 'subscription_page_hilos_user';

    /** @var string Subscription signal for Hilos roles list */
    public const string SUBSCRIPTION_PAGE_HILOS_ROLES = 'subscription_page_hilos_roles';

    /** @var string Subscription signal for Hilos MCP and Skills hub */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS = 'subscription_page_hilos_mcp_skills';

    /** @var string Subscription signal for Hilos single MCP page */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP = 'subscription_page_hilos_mcp_skills_mcp';

    /** @var string Subscription signal for Hilos MCP log overview */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP_LOGS = 'subscription_page_hilos_mcp_skills_mcp_logs';

    /** @var string Subscription signal for Hilos MCP log viewer */
    public const string SUBSCRIPTION_PAGE_HILOS_MCP_SKILLS_MCP_LOGS_VIEW = 'subscription_page_hilos_mcp_skills_mcp_logs_view';

    /** @var string Subscription signal for Hilos SIL dashboard */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL = 'subscription_page_hilos_sil';

    /** @var string Subscription signal for Hilos SIL requests list */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL_REQUESTS = 'subscription_page_hilos_sil_requests';

    /** @var string Subscription signal for Hilos SIL user history */
    public const string SUBSCRIPTION_PAGE_HILOS_SIL_USER_HISTORY = 'subscription_page_hilos_sil_user_history';
}
