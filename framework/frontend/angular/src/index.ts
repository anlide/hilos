// @hilos/angular — the Angular view layer of the Hilos frontend SDK.
//
// A thin adapter over @hilos/core: it bridges the core stores into Angular
// signals and effects. It holds no protocol, store, or table logic of its own
// (docs/agents/frontend/multiframework-core.md). Fills in across rewrite
// step 7, tracking each core capability as it lands; shipped so far: the
// connection-state signal, the core-signal bridge, the entity resolver, the
// navigation declarables, and the application shell.

export {
  connectionStateSignal,
  type ConnectionStateSignalOptions,
} from './connectionStateSignal.js'
export { hilosSignal, type HilosSignalOptions } from './hilosSignal.js'
export { entitySignal } from './entitySignal.js'
export {
  createHilosTrackedAction,
  type HilosTrackedAction,
} from './hilosTrackedAction.js'
export { HILOS_ROUTER } from './hilosRouterToken.js'
export { HilosLink } from './HilosLink.js'
export { HilosView } from './HilosView.js'
export { ErrorPage } from './ErrorPage.js'
export { HilosLayout } from './HilosLayout.js'
export { HilosStaticPage } from './HilosStaticPage.js'
export { LoadingButton } from './LoadingButton.js'
export { HilosBreadcrumb } from './HilosBreadcrumb.js'
export { ConflictHeader } from './ConflictHeader.js'
export {
  ConflictActions,
  type ConflictSaveButtonContext,
} from './ConflictActions.js'
export { HilosAdminPage } from './HilosAdminPage.js'
export { hilosAdminViews } from './admin/hilosAdminViews.js'
export { HilosDashboardPage } from './admin/dashboard/HilosDashboardPage.js'
export { HilosSettingsPage } from './admin/settings/HilosSettingsPage.js'
export { HilosBackupPage } from './admin/backup/HilosBackupPage.js'
export {
  HilosUsersPage,
  type UsersRowActionsContext,
} from './admin/users/HilosUsersPage.js'
export { HilosUserPage } from './admin/users/HilosUserPage.js'
export { HilosModal, type ModalActionsContext } from './HilosModal.js'
export {
  HilosViewportTable,
  type ViewportTableRowContext,
} from './HilosViewportTable.js'
export type { HilosTableColumn } from '@hilos/core'
