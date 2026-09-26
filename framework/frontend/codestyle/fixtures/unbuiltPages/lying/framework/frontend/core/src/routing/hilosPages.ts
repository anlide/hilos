export const HilosPages = {
  DASHBOARD: 'hilos_dashboard',
  BUILT: 'hilos_built',
  STUB: 'hilos_stub',
  MISSING: 'hilos_missing',
  HUB: 'hilos_hub',
  EMPTY: 'hilos_empty',
  LAYERED: 'hilos_layered',
  PUBLIC: 'hilos_public',
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
}
