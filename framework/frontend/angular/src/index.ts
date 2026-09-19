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
export {
  HILOS_TABLE_SELECTION_EDGE,
  type HilosTableSelectionEdge,
} from './hilosTableSelectionEdge.js'
export { HILOS_PAGE_HEADING_ID } from './hilosPageHeadingToken.js'
export { HILOS_AUTH_GATE } from './auth/hilosAuthGateToken.js'
export { HilosAuthSurface } from './auth/HilosAuthSurface.js'
export { HilosMagicLinkPage } from './auth/HilosMagicLinkPage.js'
export { HilosOAuthCallbackPage } from './auth/HilosOAuthCallbackPage.js'
export { HilosLink } from './HilosLink.js'
export { HilosView } from './HilosView.js'
export { ErrorPage } from './ErrorPage.js'
export { HilosMaintenance } from './HilosMaintenance.js'
export { HilosLayout } from './HilosLayout.js'
export { HilosStaticPage } from './HilosStaticPage.js'
export { HilosAboutPage } from './public/HilosAboutPage.js'
export { HilosLicensePage } from './public/HilosLicensePage.js'
export { HilosPrivacyPage } from './public/HilosPrivacyPage.js'
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
export { HilosSettingPresetsPage } from './admin/settings/HilosSettingPresetsPage.js'
export { HilosBackupPage } from './admin/backup/HilosBackupPage.js'
export { HilosLogsPage } from './admin/logs/HilosLogsPage.js'
export { HilosLogsKeysPage } from './admin/logs/HilosLogsKeysPage.js'
export { HilosLogsWorkersPage } from './admin/logs/HilosLogsWorkersPage.js'
export { HilosLogsRotationsPage } from './admin/logs/HilosLogsRotationsPage.js'
export { HilosLogsViewPage } from './admin/logs/HilosLogsViewPage.js'
export { HilosLogsSettingsPage } from './admin/logs/HilosLogsSettingsPage.js'
export { HilosCommunicationsPage } from './admin/communications/HilosCommunicationsPage.js'
export { HilosCommunicationsChannelPage } from './admin/communications/HilosCommunicationsChannelPage.js'
export { HilosSecurityOauthPage } from './admin/security/HilosSecurityOauthPage.js'
export { HilosSecurityOauthProviderPage } from './admin/security/HilosSecurityOauthProviderPage.js'
export { HilosCommunicationsDeliveriesPage } from './admin/communications/HilosCommunicationsDeliveriesPage.js'
export {
  HilosUsersPage,
  type UsersRowActionsContext,
} from './admin/users/HilosUsersPage.js'
export { HilosUserPage } from './admin/users/HilosUserPage.js'
export { HilosNotificationBell } from './HilosNotificationBell.js'
export { HilosNotificationPreferences } from './HilosNotificationPreferences.js'
export { HilosPushDeviceToggle } from './HilosPushDeviceToggle.js'
export { HilosModal, type ModalActionsContext } from './HilosModal.js'
export { HilosLongText } from './HilosLongText.js'
export { HilosActionError } from './HilosActionError.js'
export { HilosFormError } from './HilosFormError.js'
export { HilosToastHost } from './HilosToastHost.js'
export type { HilosToastCorner } from './hilosToastCorner.js'
export {
  HilosViewportTable,
  type BulkUntouchedContext,
  type ViewportTableProgressContext,
  type ViewportTableRowContext,
  type ViewportTableRowProgressContext,
} from './HilosViewportTable.js'
export { HilosTableCell, type HilosTableCellContext } from './HilosTableCell.js'
export { HilosTableDetail } from './HilosTableDetail.js'
export {
  HilosDropdown,
  type DropdownToggleContext,
  type DropdownOptionContext,
} from './HilosDropdown.js'
export type { HilosDropdownOption } from './hilosDropdownOption.js'
export type { HilosTableColumn } from '@hilos/core'
