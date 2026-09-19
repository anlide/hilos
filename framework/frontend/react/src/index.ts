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
export { useProtectedMode } from './useProtectedMode.js'
export { useRtStaleness } from './useRtStaleness.js'
export { useReconnectDragging } from './useReconnectDragging.js'
export { useFirstFrameHold } from './useFirstFrameHold.js'
export { useSignal } from './useSignal.js'
export { useEntity } from './useEntity.js'
export { useTrackedAction, type TrackedAction } from './useTrackedAction.js'
export { HilosRouterContext } from './hilosRouterContext.js'
export {
  HilosTableSelectionEdgeContext,
  type HilosTableSelectionEdge,
} from './hilosTableSelectionEdge.js'
export { HilosPageHeadingIdContext } from './hilosPageHeadingContext.js'
export { HilosLink, type HilosLinkProps } from './HilosLink.js'
export { HilosView, type HilosViewProps } from './HilosView.js'
export { HilosSkeleton, type HilosSkeletonProps } from './HilosSkeleton.js'
export { ErrorPage, type ErrorPageProps } from './ErrorPage.js'
export {
  HilosMaintenance,
  type HilosMaintenanceProps,
} from './HilosMaintenance.js'
export { HilosLayout, type HilosLayoutProps } from './HilosLayout.js'
export {
  HilosStaticPage,
  type HilosStaticPageProps,
} from './HilosStaticPage.js'
export {
  HilosAboutPage,
  type HilosAboutPageProps,
} from './public/HilosAboutPage.js'
export {
  HilosLicensePage,
  type HilosLicensePageProps,
} from './public/HilosLicensePage.js'
export {
  HilosPrivacyPage,
  type HilosPrivacyPageProps,
} from './public/HilosPrivacyPage.js'
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
  HilosSettingPresetsPage,
  type HilosSettingPresetsPageProps,
} from './admin/settings/HilosSettingPresetsPage.js'
export {
  HilosBackupPage,
  type HilosBackupPageProps,
} from './admin/backup/HilosBackupPage.js'
export {
  HilosCommunicationsPage,
  type HilosCommunicationsPageProps,
} from './admin/communications/HilosCommunicationsPage.js'
export {
  HilosCommunicationsChannelPage,
  type HilosCommunicationsChannelPageProps,
} from './admin/communications/HilosCommunicationsChannelPage.js'
export {
  HilosSecurityOauthPage,
  type HilosSecurityOauthPageProps,
} from './admin/security/HilosSecurityOauthPage.js'
export {
  HilosSecurityOauthProviderPage,
  type HilosSecurityOauthProviderPageProps,
} from './admin/security/HilosSecurityOauthProviderPage.js'
export {
  HilosSecuritySignInMethodsPage,
  type HilosSecuritySignInMethodsPageProps,
} from './admin/security/HilosSecuritySignInMethodsPage.js'
export {
  HilosCommunicationsDeliveriesPage,
  type HilosCommunicationsDeliveriesPageProps,
} from './admin/communications/HilosCommunicationsDeliveriesPage.js'
export {
  HilosLogsPage,
  type HilosLogsPageProps,
} from './admin/logs/HilosLogsPage.js'
export {
  HilosLogsKeysPage,
  type HilosLogsKeysPageProps,
} from './admin/logs/HilosLogsKeysPage.js'
export {
  HilosLogsWorkersPage,
  type HilosLogsWorkersPageProps,
} from './admin/logs/HilosLogsWorkersPage.js'
export {
  HilosLogsRotationsPage,
  type HilosLogsRotationsPageProps,
} from './admin/logs/HilosLogsRotationsPage.js'
export {
  HilosLogsViewPage,
  type HilosLogsViewPageProps,
} from './admin/logs/HilosLogsViewPage.js'
export {
  HilosLogsSettingsPage,
  type HilosLogsSettingsPageProps,
} from './admin/logs/HilosLogsSettingsPage.js'
export {
  HilosUsersPage,
  type HilosUsersPageProps,
} from './admin/users/HilosUsersPage.js'
export {
  HilosUserPage,
  type HilosUserPageProps,
} from './admin/users/HilosUserPage.js'
export {
  HilosNotificationBell,
  type HilosNotificationBellProps,
} from './HilosNotificationBell.js'
export {
  HilosNotificationPreferences,
  type HilosNotificationPreferencesProps,
} from './HilosNotificationPreferences.js'
export {
  HilosPushDeviceToggle,
  type HilosPushDeviceToggleProps,
} from './HilosPushDeviceToggle.js'
export { HilosModal, type HilosModalProps } from './HilosModal.js'
export { HilosLongText, type HilosLongTextProps } from './HilosLongText.js'
export {
  HilosActionError,
  type HilosActionErrorProps,
} from './HilosActionError.js'
export { HilosFormError, type HilosFormErrorProps } from './HilosFormError.js'
export { HilosToastHost, type HilosToastHostProps } from './HilosToastHost.js'
export type { HilosToastCorner } from './hilosToastCorner.js'
export {
  HilosViewportTable,
  type HilosViewportTableProps,
} from './HilosViewportTable.js'
export { HilosDropdown, type HilosDropdownProps } from './HilosDropdown.js'
export type { HilosDropdownOption } from './hilosDropdown.js'
export {
  HilosAuthSurface,
  type HilosAuthSurfaceProps,
} from './auth/HilosAuthSurface.js'
export {
  HilosMagicLinkPage,
  type HilosMagicLinkPageProps,
} from './auth/HilosMagicLinkPage.js'
export {
  HilosOAuthCallbackPage,
  type HilosOAuthCallbackPageProps,
} from './auth/HilosOAuthCallbackPage.js'
export { HilosAuthGateContext } from './auth/hilosAuthGateContext.js'
export type { HilosTableColumn } from '@hilos/core'
