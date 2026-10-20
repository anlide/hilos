export const HilosPages = {
  DASHBOARD: 'hilos_dashboard',
  STAGED: 'hilos_staged',
} as const

export const HILOS_ROUTE_DECLARATIONS = {
  [HilosPages.DASHBOARD]: { path: '/dashboard', admin: true },
  [HilosPages.STAGED]: { path: '/staged', admin: true },
}
