export const HilosPages = {
  DASHBOARD: 'hilos_dashboard',
} as const

export const HILOS_ROUTE_DECLARATIONS = {
  [HilosPages.DASHBOARD]: { path: '/dashboard', admin: true },
}
