/**
 * Action names and payload keys for table-related actions.
 * Must match backend ChatSignalConstants.
 */
export const TableActionConstants = {
  /** Action: update a chat user (name); routed to admin users page handler on the server */
  USER_UPDATE: 'user_update',

  /** Action: update user from Hilos user detail; routed to HILOS_USER page handler on the server */
  HILOS_USER_UPDATE: 'hilos_user_update',
} as const
