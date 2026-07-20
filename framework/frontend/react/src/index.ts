// @hilos/react — the React view layer of the Hilos frontend SDK.
//
// A thin adapter over @hilos/core: it subscribes React to the core stores via
// useSyncExternalStore and wraps the core selectors as hooks. It holds no
// protocol, store, or table logic of its own
// (docs/agents/frontend/multiframework-core.md). Fills in across rewrite
// step 7, tracking each core capability as it lands; shipped so far: the
// connection-state hook, the core-signal bridge, the entity resolver, the
// navigation components, and the application shell.

// The SDK ships Bootstrap so every consumer is styled transitively and never
// imports it itself (styling-rules.md). The library build inlines this
// stylesheet into the bundle, so the import covers both the dev (source) and the
// built (dist) resolution paths.
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
// The Hilos custom style layer: the one home for declarations stock Bootstrap
// utilities cannot express (styling-rules.md). Loaded after Bootstrap so its
// utilities win on equal specificity.
import './hilos-styles.scss'

export { useConnectionState } from './useConnectionState.js'
export { useSignal } from './useSignal.js'
export { useEntity } from './useEntity.js'
export { useTrackedAction, type TrackedAction } from './useTrackedAction.js'
export { HilosRouterContext } from './hilosRouterContext.js'
export { HilosLink, type HilosLinkProps } from './HilosLink.js'
export { HilosView, type HilosViewProps } from './HilosView.js'
export { ErrorPage, type ErrorPageProps } from './ErrorPage.js'
export { HilosLayout, type HilosLayoutProps } from './HilosLayout.js'
export {
  HilosStaticPage,
  type HilosStaticPageProps,
} from './HilosStaticPage.js'
export { LoadingButton, type LoadingButtonProps } from './LoadingButton.js'
export {
  HilosBreadcrumb,
  type HilosBreadcrumbProps,
} from './HilosBreadcrumb.js'
export { ConflictHeader, type ConflictHeaderProps } from './ConflictHeader.js'
export {
  ConflictActions,
  type ConflictActionsProps,
} from './ConflictActions.js'
export { HilosAdminPage, type HilosAdminPageProps } from './HilosAdminPage.js'
export { hilosAdminViews } from './admin/hilosAdminViews.js'
export {
  HilosDashboardPage,
  type HilosDashboardPageProps,
} from './admin/dashboard/HilosDashboardPage.js'
export {
  HilosSettingsPage,
  type HilosSettingsPageProps,
} from './admin/settings/HilosSettingsPage.js'
export {
  HilosBackupPage,
  type HilosBackupPageProps,
} from './admin/backup/HilosBackupPage.js'
export {
  HilosUsersPage,
  type HilosUsersPageProps,
} from './admin/users/HilosUsersPage.js'
export {
  HilosUserPage,
  type HilosUserPageProps,
} from './admin/users/HilosUserPage.js'
export { HilosModal, type HilosModalProps } from './HilosModal.js'
export {
  HilosViewportTable,
  type HilosViewportTableProps,
} from './HilosViewportTable.js'
export type { HilosTableColumn } from '@hilos/core'
