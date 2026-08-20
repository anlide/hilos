// The tasks application's own page keys: the identifier each app page subscribes
// under, mirroring the demo-specific values in backend `PageConstants`. The
// framework's `hilos_*` admin keys are not restated here — they come from
// `@hilos/core` (`HilosPages`/`HILOS_PAGE_ROUTES`), which the router in
// routes.ts merges in. Values must stay byte-for-byte equal to the backend page
// keys — they are the subscription wire identity.

export const PAGE_MAIN = 'main'
