// @hilos/core/admin/i18n: lazy section APIs.

export {
  I18N_OVERVIEW_DATA,
  i18nOverviewSchema,
  createHilosI18nOverview,
  type HilosI18nOverview,
  type HilosI18nOverviewContext,
} from './hilosI18nOverview.js'
export {
  LANGUAGE_CARD_DATA,
  languageCardSchema,
  createHilosI18nLanguageCard,
  type HilosI18nLanguageCard,
  type HilosI18nLanguageContext,
} from './hilosI18nLanguage.js'
export {
  BUILT_IN_CATALOG_DATA,
  BUILT_IN_COUNTRY_CODES_DATA,
  BUILT_IN_LANGUAGES_DATA,
  builtInCatalogSchema,
  builtInCountryCodesSchema,
  builtInLanguageSchema,
  createHilosI18nBuiltInCatalog,
  createHilosI18nBuiltInCountryCodes,
  createHilosI18nBuiltInLanguages,
  type HilosI18nBuiltInCatalog,
  type HilosI18nBuiltInLanguage,
} from './hilosI18nBuiltInCatalog.js'
export {
  HILOS_I18N_LANGUAGE_MARKS,
  HILOS_I18N_LANGUAGES_COLUMNS,
  HILOS_I18N_LANGUAGES_FRAME,
  HILOS_I18N_LANGUAGES_LEGEND,
  HILOS_I18N_LANGUAGES_TABLE,
  HilosI18nLanguageRowKey,
  createHilosI18nLanguagesTable,
  resolveHilosI18nLanguageRow,
  type HilosI18nLanguageRow,
  type HilosI18nLanguagesTable,
  type HilosI18nLanguagesTableView,
} from './hilosI18nLanguages.js'
export {
  HILOS_I18N_LANGUAGE_ADD_ACTION,
  HILOS_I18N_LANGUAGE_ADD_COPY,
  createHilosI18nLanguageAdd,
  hilosI18nLanguageCode,
  type HilosI18nLanguageAdd,
} from './hilosI18nLanguageAdd.js'
export {
  type HilosI18nLegendRow,
  type HilosI18nMark,
} from './hilosI18nMarks.js'
export {
  HILOS_I18N_COUNTRIES_COLUMNS,
  HILOS_I18N_COUNTRIES_FRAME,
  HILOS_I18N_COUNTRIES_LEGEND,
  HILOS_I18N_COUNTRIES_TABLE,
  HILOS_I18N_COUNTRY_MARKS,
  HilosI18nCountryRowKey,
  createHilosI18nCountriesTable,
  resolveHilosI18nCountryRow,
  type HilosI18nCountryLocaleChoice,
  type HilosI18nCountryRow,
  type HilosI18nCountriesTable,
  type HilosI18nCountriesTableView,
} from './hilosI18nCountries.js'
export {
  HILOS_I18N_COUNTRY_ADD_ACTION,
  HILOS_I18N_COUNTRY_ADD_COPY,
  createHilosI18nCountryAdd,
  hilosI18nCountryCode,
  hilosI18nCurrencyCode,
  type HilosI18nCountryAdd,
} from './hilosI18nCountryAdd.js'
export {
  HILOS_I18N_COUNTRY_UPDATE_ACTION,
  HILOS_I18N_COUNTRY_ROW_UPDATE_ACTION,
  HILOS_I18N_COUNTRY_WINDOW_COPY,
  createHilosI18nCountryWindow,
  createHilosI18nCountryRowWindow,
  createHilosI18nCountryCardWindow,
  hilosI18nCountryEditableFromRow,
  hilosI18nCountryEditableFromCard,
  hilosI18nCountryWindowLabel,
  hilosI18nCountryWindowMode,
  hilosI18nCountrySuggestedLocale,
  hilosI18nCountryLocaleOptions,
  type HilosI18nCountryEditable,
  type HilosI18nCountryEditFields,
  type HilosI18nCountryLocaleOption,
  type HilosI18nCountryWindow,
  type HilosI18nCountryWindowMode,
} from './hilosI18nCountryWindow.js'
export {
  HILOS_I18N_LANGUAGE_SWITCH_OFF_ACTION,
  HILOS_I18N_LANGUAGE_SWITCH_OFF_COPY,
  HILOS_I18N_LANGUAGE_SWITCH_ON_ACTION,
  HILOS_I18N_LANGUAGE_SWITCH_ON_COPY,
  createHilosI18nLanguageSwitchOff,
  createHilosI18nLanguageSwitchOn,
  hilosI18nLanguageSwitchOff,
  hilosI18nLanguageSwitchOn,
} from './hilosI18nLanguageSwitch.js'
export {
  HILOS_I18N_LANGUAGE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_ROW_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  createHilosI18nLanguageDelete,
  hilosI18nLanguageDelete,
} from './hilosI18nLanguageDelete.js'
export {
  HILOS_I18N_LANGUAGE_NAME_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_NAME_OVERRIDE_DELETE_ACTION,
  HILOS_I18N_LANGUAGE_NAME_DELETE_COPY,
  createHilosI18nLanguageNameDelete,
  type HilosI18nLanguageNameDeleteTarget,
} from './hilosI18nLanguageNameDelete.js'
export {
  HILOS_I18N_LANGUAGE_NAME_ADD_ACTION,
  HILOS_I18N_LANGUAGE_NAME_UPDATE_ACTION,
  HILOS_I18N_LANGUAGE_NAME_MAX,
  HILOS_I18N_LANGUAGE_NAME_COPY,
  createHilosI18nLanguageNameWindow,
  type HilosI18nLanguageNameTarget,
  type HilosI18nLanguageNameWindow,
} from './hilosI18nLanguageNameWindow.js'
export {
  HILOS_I18N_LANGUAGE_UPDATE_ACTION,
  HILOS_I18N_LANGUAGE_ROW_UPDATE_ACTION,
  HILOS_I18N_LANGUAGE_NATIVE_NAME_MAX,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  createHilosI18nLanguageRowWindow,
  createHilosI18nLanguageCardWindow,
  type HilosI18nLanguageEditable,
  type HilosI18nLanguageEditFields,
  type HilosI18nLanguageWindowMode,
  type HilosI18nLanguageWindow,
} from './hilosI18nLanguageWindow.js'
export {
  HILOS_I18N_COUNTRY_SWITCH_OFF_ACTION,
  HILOS_I18N_COUNTRY_SWITCH_OFF_COPY,
  HILOS_I18N_COUNTRY_SWITCH_ON_ACTION,
  HILOS_I18N_COUNTRY_SWITCH_ON_COPY,
  createHilosI18nCountrySwitchOff,
  createHilosI18nCountrySwitchOn,
  hilosI18nCountryLabel,
  hilosI18nCountrySwitchOn,
} from './hilosI18nCountrySwitch.js'
export {
  HILOS_I18N_COUNTRY_DELETE_ACTION,
  HILOS_I18N_COUNTRY_ROW_DELETE_ACTION,
  HILOS_I18N_COUNTRY_DELETE_COPY,
  createHilosI18nCountryDelete,
  createHilosI18nCountryRowDelete,
  hilosI18nCountryDeleteLabel,
  hilosI18nCountryDeleteReason,
  hilosI18nCountryDeleteFromRow,
  hilosI18nCountryDeleteFromCard,
  type HilosI18nCountryDeleteTarget,
} from './hilosI18nCountryDelete.js'
export {
  HILOS_I18N_COUNTRY_NAME_DELETE_ACTION,
  HILOS_I18N_COUNTRY_NAME_OVERRIDE_DELETE_ACTION,
  HILOS_I18N_COUNTRY_NAME_DELETE_COPY,
  createHilosI18nCountryNameDelete,
  type HilosI18nCountryNameDeleteTarget,
} from './hilosI18nCountryNameDelete.js'
export {
  HILOS_I18N_COUNTRY_NAME_ADD_ACTION,
  HILOS_I18N_COUNTRY_NAME_UPDATE_ACTION,
  HILOS_I18N_COUNTRY_NAME_MAX,
  HILOS_I18N_COUNTRY_NAME_COPY,
  createHilosI18nCountryNameWindow,
  type HilosI18nCountryNameTarget,
  type HilosI18nCountryNameWindow,
} from './hilosI18nCountryNameWindow.js'
export {
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_COUNTRY_NAMES_FILTER,
  HILOS_I18N_COUNTRY_NAMES_TABLE,
  HILOS_I18N_LANGUAGE_NAMES,
  HILOS_I18N_LANGUAGE_NAMES_FILTER,
  HILOS_I18N_LANGUAGE_NAMES_TABLE,
  HILOS_I18N_NAMES_COLUMNS,
  HilosI18nNameCorrectionKey,
  HilosI18nNameRowKey,
  createHilosI18nNamesTable,
  hilosI18nNameRowExpandable,
  resolveHilosI18nNameRow,
  type HilosI18nNameCorrection,
  type HilosI18nNameRow,
  type HilosI18nNamesTable,
  type HilosI18nNamesTarget,
} from './hilosI18nNames.js'
export {
  HILOS_I18N_LANGUAGE_LOCALES_COLUMNS,
  HILOS_I18N_LANGUAGE_LOCALES_FILTER,
  HILOS_I18N_LANGUAGE_LOCALES_FRAME,
  HILOS_I18N_LANGUAGE_LOCALES_TABLE,
  HilosI18nLocaleRowKey,
  hilosI18nLocaleFormatsSchema,
  createHilosI18nLanguageLocalesTable,
  resolveHilosI18nLocaleRow,
  type HilosI18nLanguageLocalesTable,
  type HilosI18nLocaleRow,
  type HilosI18nLocaleFormats,
} from './hilosI18nLocales.js'
export {
  HILOS_I18N_LOCALE_DELETE_ACTION,
  HILOS_I18N_LOCALE_DELETE_COPY,
  createHilosI18nLocaleDelete,
  type HilosI18nLocaleDeleteTarget,
} from './hilosI18nLocaleDelete.js'
export {
  HILOS_I18N_LOCALE_ADD_ACTION,
  HILOS_I18N_LOCALE_UPDATE_ACTION,
  HILOS_I18N_LOCALE_SWITCH_ON_ACTION,
  HILOS_I18N_LOCALE_SWITCH_OFF_ACTION,
  HILOS_I18N_LOCALE_COPY,
  LOCALE_TEMPLATES_DATA,
  localeTemplatesSchema,
  createHilosI18nLocaleTemplates,
  createHilosI18nLocaleEdit,
  createHilosI18nLocaleAdd,
  createHilosI18nLocaleSwitchOn,
  createHilosI18nLocaleSwitchOff,
  hilosI18nLocaleSwitchOff,
  hilosI18nLocaleWindowMode,
  hilosI18nLocaleCountryLabel,
  type HilosI18nLocaleTemplates,
  type HilosI18nLocaleWindowMode,
  type HilosI18nLocaleAddForm,
  type HilosI18nLocaleAdd,
} from './hilosI18nLocaleWindow.js'
export {
  COUNTRY_CARD_DATA,
  countryCardSchema,
  createHilosI18nCountryCard,
  hilosI18nCountryDisplayName,
  type HilosI18nCountryCard,
  type HilosI18nCountryContext,
} from './hilosI18nCountry.js'
