// @hilos/vue — the Vue view layer over @hilos/core (the Hilos frontend SDK).
//
// Thin Vue adapters and the slot-extensible SDK components (tier 1 universal
// components and tier 2 page chunks) live here; all protocol, store, and table
// logic stays in @hilos/core. Fills in across rewrite step 7; shipped so far:
// the connection-state composable, the core-signal bridge, the entity resolver,
// and the application shell.

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
export { useSignal } from './useSignal.js'
export { useEntity } from './useEntity.js'
export { useTrackedAction, type TrackedAction } from './useTrackedAction.js'
export { hilosRouterKey } from './hilosRouterKey.js'
export { default as HilosAdminPage } from './HilosAdminPage.vue'
export { default as HilosBreadcrumb } from './HilosBreadcrumb.vue'
export { default as HilosLayout } from './HilosLayout.vue'
export { default as HilosLink } from './HilosLink.vue'
export { default as HilosStaticPage } from './HilosStaticPage.vue'
export { default as HilosView } from './HilosView.vue'
export { default as ErrorPage } from './ErrorPage.vue'
export { default as HilosMaintenance } from './HilosMaintenance.vue'
export { default as HilosViewportTable } from './HilosViewportTable.vue'
export type { HilosTableColumn } from '@hilos/core'
export { default as HilosDropdown } from './HilosDropdown.vue'
export type { HilosDropdownOption } from './hilosDropdown.js'
export { default as HilosNotificationBell } from './HilosNotificationBell.vue'
export { default as HilosNotificationPreferences } from './HilosNotificationPreferences.vue'
export { default as HilosPushDeviceToggle } from './HilosPushDeviceToggle.vue'
export { default as ConflictActions } from './ConflictActions.vue'
export { default as ConflictHeader } from './ConflictHeader.vue'
export { default as LoadingButton } from './LoadingButton.vue'
export { default as HilosModal } from './HilosModal.vue'
export { default as HilosToastHost } from './HilosToastHost.vue'
export { hilosAdminViews } from './admin/hilosAdminViews.js'
export { default as HilosUsersPage } from './admin/users/HilosUsersPage.vue'
export { default as HilosUserPage } from './admin/users/HilosUserPage.vue'
export { default as HilosSettingsPage } from './admin/settings/HilosSettingsPage.vue'
export { default as HilosAuthSurface } from './auth/HilosAuthSurface.vue'
export { default as HilosMagicLinkPage } from './auth/HilosMagicLinkPage.vue'
export { default as HilosOAuthCallbackPage } from './auth/HilosOAuthCallbackPage.vue'
export { hilosAuthGateKey } from './auth/hilosAuthGateKey.js'
export { default as HilosBackupPage } from './admin/backup/HilosBackupPage.vue'
export { default as HilosCommunicationsPage } from './admin/communications/HilosCommunicationsPage.vue'
export { default as HilosCommunicationsChannelPage } from './admin/communications/HilosCommunicationsChannelPage.vue'
export { default as HilosCommunicationsDeliveriesPage } from './admin/communications/HilosCommunicationsDeliveriesPage.vue'
export { default as HilosDashboardPage } from './admin/dashboard/HilosDashboardPage.vue'
