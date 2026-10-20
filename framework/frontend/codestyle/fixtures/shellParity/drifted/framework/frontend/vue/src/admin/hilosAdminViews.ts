export function hilosAdminViews() {
  return {
    [HilosPages.STAGED]: lazyPage(() =>
      import('@hilos/vue/admin/billing').then((m) => m.StagedPage),
    ),
    [HilosPages.LATE]: lazyPage(() =>
      import('@hilos/vue/admin/billing').then((m) => m.LatePage),
    ),
  }
}
