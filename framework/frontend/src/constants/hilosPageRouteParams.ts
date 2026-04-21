/**
 * Hilos admin route param keys (Vue Router + WebSocket `page_subscribe.params`).
 * Keep in sync with `framework/backend/Constants/HilosPageRouteParams.php`.
 */
const USER_ID_SEGMENT = 'userId' as const

export const HilosPageRouteParams = {
  /** Page id `hilos_user` — path `/hilos/users/:userId`. */
  HILOS_USER_USER_ID: USER_ID_SEGMENT,
  /** Page id `hilos_sil_user_history` — path `/hilos/sil/users/:userId`. */
  HILOS_SIL_USER_HISTORY_USER_ID: USER_ID_SEGMENT,
} as const
