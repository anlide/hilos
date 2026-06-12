// The chat page keys: the identifier each page subscribes under, mirroring the
// backend `Demo\Chat\Constants\PageConstants` values (the framework `hilos_*`
// keys plus the demo's own application pages). Kept apart from pages.ts so the
// route registry there reads as a registration of these identifiers, not a
// place that also coins them. Values must stay byte-for-byte equal to the
// backend page keys — they are the subscription wire identity.

// Application pages.
export const PAGE_MAIN = 'main'
export const PAGE_PROFILE = 'profile'
export const PAGE_USER = 'user'
export const PAGE_BOT = 'bot'
export const PAGE_ADMIN_USERS = 'admin_users'
export const PAGE_ADMIN_MODERATOR = 'admin_moderator'
export const PAGE_ADMIN_BOTS = 'admin_bots'

// Hilos admin dashboard and its sections.
export const PAGE_HILOS_DASHBOARD = 'hilos'
export const PAGE_HILOS_SETTINGS = 'hilos_settings'
export const PAGE_HILOS_ANALYTICS = 'hilos_analytics'
export const PAGE_HILOS_ROLES = 'hilos_roles'
export const PAGE_HILOS_OPERATIONS = 'hilos_operations'
export const PAGE_HILOS_BACKUP = 'hilos_backup'

// Hilos guardian.
export const PAGE_HILOS_GUARDIAN = 'hilos_guardian'
export const PAGE_HILOS_GUARDIAN_AGENT = 'hilos_guardian_agent'

// Hilos internationalization.
export const PAGE_HILOS_I18N = 'hilos_i18n'
export const PAGE_HILOS_I18N_LANGUAGES = 'hilos_i18n_languages'
export const PAGE_HILOS_I18N_COUNTRIES = 'hilos_i18n_countries'
export const PAGE_HILOS_I18N_ENTITIES = 'hilos_i18n_entities'
export const PAGE_HILOS_I18N_UI_PAGES = 'hilos_i18n_ui_pages'
export const PAGE_HILOS_I18N_GROUPS = 'hilos_i18n_groups'
export const PAGE_HILOS_I18N_ACTIONS = 'hilos_i18n_actions'
export const PAGE_HILOS_I18N_EMAILS = 'hilos_i18n_emails'
export const PAGE_HILOS_I18N_LANGUAGE = 'hilos_i18n_language'
export const PAGE_HILOS_I18N_COUNTRY = 'hilos_i18n_country'
export const PAGE_HILOS_I18N_UI_PAGE = 'hilos_i18n_ui_page'
export const PAGE_HILOS_I18N_GROUP = 'hilos_i18n_group'
export const PAGE_HILOS_I18N_ACTION = 'hilos_i18n_action'
export const PAGE_HILOS_I18N_TRANSLATE_ENTITY = 'hilos_i18n_translate_entity'
export const PAGE_HILOS_I18N_TRANSLATE_UI_PAGE = 'hilos_i18n_translate_ui_page'
export const PAGE_HILOS_I18N_TRANSLATE_UI_PAGE_ITEM = 'hilos_i18n_translate_ui_page_item'
export const PAGE_HILOS_I18N_TRANSLATE_GROUP = 'hilos_i18n_translate_group'
export const PAGE_HILOS_I18N_TRANSLATE_GROUP_ITEM = 'hilos_i18n_translate_group_item'
export const PAGE_HILOS_I18N_TRANSLATE_ACTION_ERROR = 'hilos_i18n_translate_action_error'
export const PAGE_HILOS_I18N_TRANSLATE_EMAIL = 'hilos_i18n_translate_email'

// Hilos change log.
export const PAGE_HILOS_CHANGE_LOG = 'hilos_change_log'
export const PAGE_HILOS_CHANGE_LOG_TABLES = 'hilos_change_log_tables'
export const PAGE_HILOS_CHANGE_LOG_TABLE = 'hilos_change_log_table'

// Hilos daemon.
export const PAGE_HILOS_DAEMON = 'hilos_daemon'
export const PAGE_HILOS_DAEMON_WORKERS = 'hilos_daemon_workers'
export const PAGE_HILOS_DAEMON_AGENTS = 'hilos_daemon_agents'
export const PAGE_HILOS_DAEMON_CRON = 'hilos_daemon_cron'
export const PAGE_HILOS_DAEMON_WEBSOCKETS = 'hilos_daemon_websockets'
export const PAGE_HILOS_DAEMON_HTTP_SERVER = 'hilos_daemon_http_server'

// Hilos logs.
export const PAGE_HILOS_LOGS = 'hilos_logs'
export const PAGE_HILOS_LOGS_KEYS = 'hilos_logs_keys'
export const PAGE_HILOS_LOGS_WORKERS = 'hilos_logs_workers'
export const PAGE_HILOS_LOGS_ROTATIONS = 'hilos_logs_rotations'
export const PAGE_HILOS_LOGS_VIEW = 'hilos_logs_view'

// Hilos users.
export const PAGE_HILOS_USERS = 'hilos_users'
export const PAGE_HILOS_USER = 'hilos_user'

// Hilos MCP and skills.
export const PAGE_HILOS_MCP_SKILLS = 'hilos_mcp_skills'
export const PAGE_HILOS_MCP_SKILLS_MCP = 'hilos_mcp_skills_mcp'
export const PAGE_HILOS_MCP_SKILLS_MCP_LOGS = 'hilos_mcp_skills_mcp_logs'
export const PAGE_HILOS_MCP_SKILLS_MCP_LOGS_VIEW = 'hilos_mcp_skills_mcp_logs_view'

// Hilos system intelligence layer.
export const PAGE_HILOS_SIL = 'hilos_sil'
export const PAGE_HILOS_SIL_REQUESTS = 'hilos_sil_requests'
export const PAGE_HILOS_SIL_USER_HISTORY = 'hilos_sil_user_history'

// Hilos communications.
export const PAGE_HILOS_COMMUNICATIONS = 'hilos_communications'
export const PAGE_HILOS_COMMUNICATIONS_CHANNEL = 'hilos_communications_channel'
export const PAGE_HILOS_COMMUNICATIONS_DELIVERIES = 'hilos_communications_deliveries'

// Hilos security center.
export const PAGE_HILOS_SECURITY = 'hilos_security'
export const PAGE_HILOS_SECURITY_2FA = 'hilos_security_2fa'
export const PAGE_HILOS_SECURITY_OAUTH = 'hilos_security_oauth'
export const PAGE_HILOS_SECURITY_OAUTH_PROVIDER = 'hilos_security_oauth_provider'

// Hilos billing.
export const PAGE_HILOS_BILLING = 'hilos_billing'
export const PAGE_HILOS_BILLING_PROVIDER = 'hilos_billing_provider'
export const PAGE_HILOS_BILLING_PAYMENTS = 'hilos_billing_payments'
export const PAGE_HILOS_BILLING_REFUNDS = 'hilos_billing_refunds'
