import Dashboard from './Dashboard.vue'
export function hilosAdminViews() {
  return {
    [HilosPages.DASHBOARD]: Dashboard,
    [HilosPages.BUILT]: lazyPage(() =>
      import('@hilos/vue/admin/change-log').then((m) => m.Built),
    ),
    [HilosPages.STUB]: lazyPage(() =>
      import('@hilos/vue/admin/mcp-skills').then((m) => m.Stub),
    ),
  }
}
