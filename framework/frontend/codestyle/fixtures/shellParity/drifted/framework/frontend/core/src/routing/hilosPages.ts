export const HilosPages = {
  I18N_LANGUAGE: 'hilos_i18n_language',
  STAGED: 'hilos_staged',
  LATE: 'hilos_late',
} as const

export const HILOS_ROUTE_DECLARATIONS = {
  [HilosPages.I18N_LANGUAGE]: {
    path: '/hilos/i18n/languages/{languageCode}',
    admin: true,
  },
  [HilosPages.STAGED]: { path: '/staged', admin: true },
  [HilosPages.LATE]: { path: '/late', admin: true },
}
