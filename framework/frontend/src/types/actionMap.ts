/**
 * HilosActionMap — a compile-time registry of client-initiated actions and their payload shapes.
 *
 * Maps each action name (sent as `{type:'action', action, data}`) to its payload type.
 * `sendAction<K extends keyof HilosActionMap>(ws, action: K, data: HilosActionMap[K])`
 * then type-checks action names and payload shapes at every call site.
 *
 * Downstream projects (e.g. demo/chat) augment this interface via TypeScript
 * declaration merging to add their own actions:
 *
 *   declare module '@hilos/sdk/types/actionMap' {
 *     interface HilosActionMap {
 *       rename: { newName: string }
 *       // ...
 *     }
 *   }
 *
 * Keep action names in sync with backend constants (framework: HilosSignalConstants,
 * demo/chat: ChatSignalConstants).
 */
export interface HilosActionMap {
  /** Add a new setting (Hilos settings admin page). */
  setting_add: { key: string; value?: string | null }

  /** Update an existing setting. */
  setting_update: { key: string; value: string | null }

  /** Delete a setting by key. */
  setting_delete: { key: string }

  /** Start a guardian agent run. */
  guardian_agent_run_start: { agentId: string | number }

  /** Stop a guardian agent run. */
  guardian_agent_run_stop: { agentId: string | number }

  /** Update a user from the Hilos user-detail page. */
  hilos_user_update: { id: number; name: string }
}
