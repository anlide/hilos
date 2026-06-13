// The chat application's own page keys: the identifier each app page subscribes
// under, mirroring the demo-specific values in backend `PageConstants`. The
// framework's `hilos_*` admin keys are not restated here — they come from
// `@hilos/core` (`HilosPages`/`HILOS_PAGE_ROUTES`), which the router in
// routes.ts merges in. Values must stay byte-for-byte equal to the backend page
// keys — they are the subscription wire identity.

export const PAGE_MAIN = 'main'
export const PAGE_PROFILE = 'profile'
export const PAGE_USER = 'user'
export const PAGE_BOT = 'bot'
export const PAGE_ADMIN_USERS = 'admin_users'
export const PAGE_ADMIN_MODERATOR = 'admin_moderator'
export const PAGE_ADMIN_BOTS = 'admin_bots'
