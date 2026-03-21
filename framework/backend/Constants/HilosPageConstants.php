<?php

declare(strict_types=1);

namespace Hilos\Constants;

/**
 * HilosPageConstants - Page constants for Hilos admin section.
 *
 * Defines page identifiers for framework-level Hilos admin pages.
 * Projects inherit these pages via HilosPageFactory.
 */
class HilosPageConstants
{
    /** @var string Hilos dashboard (main page of hilos section) */
    public const string HILOS_DASHBOARD = 'hilos';

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
}
