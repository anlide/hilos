import { Dashboard } from './Dashboard.js'
export function hilosAdminViews() {
  return {
    [HilosPages.DASHBOARD]: Dashboard,
    [HilosPages.BUILT]: lazyPage(() =>
      import('@hilos/react/admin/change-log').then((m) => m.Built),
    ),
    [HilosPages.STUB]: lazyPage(() =>
      import('@hilos/react/admin/mcp-skills').then((m) => m.Stub),
    ),
  }
}
