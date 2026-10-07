export const HilosPages = {
  DASHBOARD: 'hilos_dashboard',
  BUILT: 'hilos_built',
  STUB: 'hilos_stub',
  MISSING: 'hilos_missing',
  HUB: 'hilos_hub',
  EMPTY: 'hilos_empty',
  LAYERED: 'hilos_layered',
  PUBLIC: 'hilos_public',
  I18N_LANGUAGES: 'hilos_i18n_languages',
  I18N_LANGUAGE: 'hilos_i18n_language',
  PRESETS: 'hilos_presets',
} as const

export const HILOS_ROUTE_DECLARATIONS = {
  [HilosPages.DASHBOARD]: { path: '/dashboard', admin: true },
  [HilosPages.BUILT]: { path: '/built', admin: true },
  [HilosPages.STUB]: { path: '/stub', admin: true },
  [HilosPages.MISSING]: { path: '/missing', admin: true },
  [HilosPages.HUB]: { path: '/hub', admin: true },
  [HilosPages.EMPTY]: { path: '/empty', admin: true },
  [HilosPages.LAYERED]: { path: '/layered', admin: true },
  [HilosPages.PRESETS]: { path: '/presets', admin: true },
  [HilosPages.PUBLIC]: { path: '/public', admin: false },
  [HilosPages.I18N_LANGUAGES]: { path: '/hilos/i18n/languages', admin: true },
  [HilosPages.I18N_LANGUAGE]: {
    path: '/hilos/i18n/languages/{languageCode}',
    admin: true,
  },
}
