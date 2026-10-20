export function hilosAdminViews() {
  return {
    [HilosPages.STAGED]: lazyPage(() =>
      import('@hilos/vue/admin/billing').then((m) => m.StagedPage),
    ),
  }
}
