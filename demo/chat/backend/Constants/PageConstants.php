<?php

declare(strict_types=1);

namespace Demo\Chat\Constants;

use Hilos\Constants\HilosPageConstants;

/**
 * PageConstants - Page constants for chat demo.
 *
 * Defines page identifiers used for user subscriptions.
 */
final class PageConstants
{
    /** @var string Chat main page */
    public const string MAIN = 'main';

    /** @var string User profile page */
    public const string PROFILE = 'profile';

    /** @var string User page */
    public const string USER = 'user';

    /** @var string Bot page */
    public const string BOT = 'bot';

    /** @var string Moderator page */
    public const string MODERATOR = 'moderator';

    /** @var string Admin page */
    public const string ADMIN = 'admin';

    /** @var string Admin users page */
    public const string ADMIN_USERS = 'admin_users';

    /** @var string Admin moderator page */
    public const string ADMIN_MODERATOR = 'admin_moderator';

    /** @var string Admin bots page */
    public const string ADMIN_BOTS = 'admin_bots';

    /** @var string Hilos dashboard page */
    public const string HILOS_DASHBOARD = HilosPageConstants::HILOS_DASHBOARD;

    /** @var string Hilos settings page */
    public const string HILOS_SETTINGS = HilosPageConstants::HILOS_SETTINGS;

    /** @var string Hilos internationalization page */
    public const string HILOS_I18N = HilosPageConstants::HILOS_I18N;

    /** @var string Hilos guardian page */
    public const string HILOS_GUARDIAN = HilosPageConstants::HILOS_GUARDIAN;

    /** @var string Hilos guardian AI agent page */
    public const string HILOS_GUARDIAN_AGENT = HilosPageConstants::HILOS_GUARDIAN_AGENT;

    /** @var string Hilos analytics page */
    public const string HILOS_ANALYTICS = HilosPageConstants::HILOS_ANALYTICS;

    public const string HILOS_I18N_LANGUAGES = HilosPageConstants::HILOS_I18N_LANGUAGES;

    public const string HILOS_I18N_COUNTRIES = HilosPageConstants::HILOS_I18N_COUNTRIES;

    public const string HILOS_I18N_ENTITIES = HilosPageConstants::HILOS_I18N_ENTITIES;

    public const string HILOS_I18N_UI_PAGES = HilosPageConstants::HILOS_I18N_UI_PAGES;

    public const string HILOS_I18N_GROUPS = HilosPageConstants::HILOS_I18N_GROUPS;

    public const string HILOS_I18N_ACTIONS = HilosPageConstants::HILOS_I18N_ACTIONS;

    public const string HILOS_I18N_EMAILS = HilosPageConstants::HILOS_I18N_EMAILS;

    public const string HILOS_I18N_LANGUAGE = HilosPageConstants::HILOS_I18N_LANGUAGE;

    public const string HILOS_I18N_COUNTRY = HilosPageConstants::HILOS_I18N_COUNTRY;

    public const string HILOS_I18N_UI_PAGE = HilosPageConstants::HILOS_I18N_UI_PAGE;

    public const string HILOS_I18N_GROUP = HilosPageConstants::HILOS_I18N_GROUP;

    public const string HILOS_I18N_ACTION = HilosPageConstants::HILOS_I18N_ACTION;

    public const string HILOS_I18N_TRANSLATE_ENTITY = HilosPageConstants::HILOS_I18N_TRANSLATE_ENTITY;

    public const string HILOS_I18N_TRANSLATE_UI_PAGE = HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE;

    public const string HILOS_I18N_TRANSLATE_UI_PAGE_ITEM = HilosPageConstants::HILOS_I18N_TRANSLATE_UI_PAGE_ITEM;

    public const string HILOS_I18N_TRANSLATE_GROUP = HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP;

    public const string HILOS_I18N_TRANSLATE_GROUP_ITEM = HilosPageConstants::HILOS_I18N_TRANSLATE_GROUP_ITEM;

    public const string HILOS_I18N_TRANSLATE_ACTION_ERROR = HilosPageConstants::HILOS_I18N_TRANSLATE_ACTION_ERROR;

    public const string HILOS_I18N_TRANSLATE_EMAIL = HilosPageConstants::HILOS_I18N_TRANSLATE_EMAIL;

    public const string HILOS_BACKUP = HilosPageConstants::HILOS_BACKUP;

    public const string HILOS_DAEMON = HilosPageConstants::HILOS_DAEMON;

    public const string HILOS_DAEMON_WORKERS = HilosPageConstants::HILOS_DAEMON_WORKERS;

    public const string HILOS_DAEMON_AGENTS = HilosPageConstants::HILOS_DAEMON_AGENTS;

    public const string HILOS_DAEMON_CRON = HilosPageConstants::HILOS_DAEMON_CRON;

    public const string HILOS_DAEMON_WEBSOCKETS = HilosPageConstants::HILOS_DAEMON_WEBSOCKETS;

    public const string HILOS_DAEMON_HTTP_SERVER = HilosPageConstants::HILOS_DAEMON_HTTP_SERVER;

    public const string HILOS_LOGS = HilosPageConstants::HILOS_LOGS;

    public const string HILOS_LOGS_KEYS = HilosPageConstants::HILOS_LOGS_KEYS;

    public const string HILOS_LOGS_WORKERS = HilosPageConstants::HILOS_LOGS_WORKERS;

    public const string HILOS_LOGS_ROTATIONS = HilosPageConstants::HILOS_LOGS_ROTATIONS;

    public const string HILOS_LOGS_VIEW = HilosPageConstants::HILOS_LOGS_VIEW;

    public const string HILOS_OPERATIONS = HilosPageConstants::HILOS_OPERATIONS;

    public const string HILOS_USERS = HilosPageConstants::HILOS_USERS;

    public const string HILOS_USER = HilosPageConstants::HILOS_USER;

    public const string HILOS_ROLES = HilosPageConstants::HILOS_ROLES;

    public const string HILOS_MCP_SKILLS = HilosPageConstants::HILOS_MCP_SKILLS;

    public const string HILOS_MCP_SKILLS_MCP = HilosPageConstants::HILOS_MCP_SKILLS_MCP;

    public const string HILOS_MCP_SKILLS_MCP_LOGS = HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS;

    public const string HILOS_MCP_SKILLS_MCP_LOGS_VIEW = HilosPageConstants::HILOS_MCP_SKILLS_MCP_LOGS_VIEW;

    public const string HILOS_SIL = HilosPageConstants::HILOS_SIL;

    public const string HILOS_SIL_REQUESTS = HilosPageConstants::HILOS_SIL_REQUESTS;

    public const string HILOS_SIL_USER_HISTORY = HilosPageConstants::HILOS_SIL_USER_HISTORY;

    public const string HILOS_COMMUNICATIONS = HilosPageConstants::HILOS_COMMUNICATIONS;

    public const string HILOS_COMMUNICATIONS_CHANNEL = HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL;

    public const string HILOS_COMMUNICATIONS_DELIVERIES = HilosPageConstants::HILOS_COMMUNICATIONS_DELIVERIES;

    public const string HILOS_SECURITY = HilosPageConstants::HILOS_SECURITY;

    public const string HILOS_SECURITY_2FA = HilosPageConstants::HILOS_SECURITY_2FA;

    public const string HILOS_SECURITY_OAUTH = HilosPageConstants::HILOS_SECURITY_OAUTH;

    public const string HILOS_SECURITY_OAUTH_PROVIDER = HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER;

    public const string HILOS_BILLING = HilosPageConstants::HILOS_BILLING;

    public const string HILOS_BILLING_PROVIDER = HilosPageConstants::HILOS_BILLING_PROVIDER;

    public const string HILOS_BILLING_PAYMENTS = HilosPageConstants::HILOS_BILLING_PAYMENTS;

    public const string HILOS_BILLING_REFUNDS = HilosPageConstants::HILOS_BILLING_REFUNDS;

    public const string HILOS_CHANGE_LOG = HilosPageConstants::HILOS_CHANGE_LOG;

    public const string HILOS_CHANGE_LOG_TABLES = HilosPageConstants::HILOS_CHANGE_LOG_TABLES;

    public const string HILOS_CHANGE_LOG_TABLE = HilosPageConstants::HILOS_CHANGE_LOG_TABLE;

    /** @var string Hilos public About page */
    public const string HILOS_ABOUT = HilosPageConstants::HILOS_ABOUT;

    /** @var string Hilos public Terms page */
    public const string HILOS_TERMS = HilosPageConstants::HILOS_TERMS;

    /** @var string Hilos public Privacy page */
    public const string HILOS_PRIVACY = HilosPageConstants::HILOS_PRIVACY;

    /** @var string Hilos public Licence page */
    public const string HILOS_LICENCE = HilosPageConstants::HILOS_LICENCE;
}
