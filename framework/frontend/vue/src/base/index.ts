// @hilos/vue/base — the Vue view layer over @hilos/core (the Hilos frontend SDK).
//
// Thin Vue adapters and the slot-extensible SDK components (tier 1 universal
// components and tier 2 page chunks) live here; all protocol, store, and table
// logic stays in @hilos/core. Fills in across rewrite step 7; shipped so far:
// the connection-state composable, the core-signal bridge, the entity resolver,
// and the application shell.

// The SDK ships Bootstrap so every consumer is styled transitively and never
// imports it itself (styling-rules.md). The library build attaches its emitted
// stylesheet to the bundle, so the import covers both the dev (source) and the
// built (dist) resolution paths.
import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap-icons/font/bootstrap-icons.css'
// The Hilos custom style layer: the one home for declarations stock Bootstrap
// utilities cannot express (styling-rules.md). Loaded after Bootstrap so its
// utilities win on equal specificity.
import '../hilos-styles.scss'

export { useConnectionState } from '../useConnectionState.js'
export { useProtectedMode } from '../useProtectedMode.js'
export { useRtStaleness } from '../useRtStaleness.js'
export { useReconnectDragging } from '../useReconnectDragging.js'
export { useFirstFrameHold } from '../useFirstFrameHold.js'
export { useSignal } from '../useSignal.js'
export {
  hilosConnectionInteractionKey,
  useConnectionInteraction,
  type ConnectionInteraction,
} from '../hilosConnectionInteraction.js'
export { useEntity } from '../useEntity.js'
export { useTrackedAction, type TrackedAction } from '../useTrackedAction.js'
export { hilosRouterKey } from '../hilosRouterKey.js'
export { HILOS_VIEW_LAYER } from '../hilosViewLayer.js'
export { hilosPageHeadingIdKey } from '../hilosPageHeading.js'
export {
  hilosAdminViewModeKey,
  hilosTakeoverViewOnlyKey,
  useAdminViewMode,
  useLookOnly,
  type LookOnly,
} from '../hilosAdminViewMode.js'
export {
  hilosTableSelectionEdgeKey,
  type HilosTableSelectionEdge,
} from '../hilosTableSelectionEdge.js'
export { default as HilosAdminPage } from '../HilosAdminPage.vue'
export { default as HilosPageHead } from '../HilosPageHead.vue'
export { default as HilosPageHeading } from '../HilosPageHeading.vue'
export { default as HilosProfileHead } from '../profile/HilosProfileHead.vue'
export { default as HilosProfilePage } from '../profile/HilosProfilePage.vue'
export { default as HilosProfileRename } from '../profile/HilosProfileRename.vue'
export { default as HilosProfilePhoto } from '../profile/HilosProfilePhoto.vue'
export { default as HilosProfileEmailChange } from '../profile/HilosProfileEmailChange.vue'
export { default as HilosProfileSignInPage } from '../profile/HilosProfileSignInPage.vue'
export { default as HilosProfileNotificationsPage } from '../profile/HilosProfileNotificationsPage.vue'
export { default as HilosProfileDataPage } from '../profile/HilosProfileDataPage.vue'
export { default as HilosBreadcrumb } from '../HilosBreadcrumb.vue'
export { default as HilosLayout } from '../HilosLayout.vue'
export { default as HilosLink } from '../HilosLink.vue'
export { default as HilosStaticPage } from '../HilosStaticPage.vue'
export { default as HilosAboutPage } from '../public/HilosAboutPage.vue'
export { default as HilosLicensePage } from '../public/HilosLicensePage.vue'
export { default as HilosPrivacyPage } from '../public/HilosPrivacyPage.vue'
export { default as HilosLegalHistoryPage } from '../public/HilosLegalHistoryPage.vue'
export { default as HilosTermsPage } from '../public/HilosTermsPage.vue'
export { default as HilosView } from '../HilosView.vue'
export { default as HilosSkeleton } from '../HilosSkeleton.vue'
export { default as HilosAvatar } from '../HilosAvatar.vue'
export { default as HilosHiddenMark } from '../HilosHiddenMark.vue'
export { default as HilosHideable } from '../HilosHideable.vue'
export { default as HilosTableText } from '../HilosTableText.vue'
export { default as ErrorPage } from '../ErrorPage.vue'
export { default as HilosMaintenance } from '../HilosMaintenance.vue'
export { default as HilosViewportTable } from '../HilosViewportTable.vue'
export type { HilosTableColumn } from '@hilos/core'
export { default as HilosDropdown } from '../HilosDropdown.vue'
export type { HilosDropdownOption } from '../hilosDropdown.js'
export { default as HilosNotificationBell } from '../HilosNotificationBell.vue'
export { default as HilosThemeMenu } from '../HilosThemeMenu.vue'
export { default as HilosNotificationPreferences } from '../HilosNotificationPreferences.vue'
export { default as HilosPushDeviceToggle } from '../HilosPushDeviceToggle.vue'
export { default as HilosQrCode } from '../HilosQrCode.vue'
export { default as HilosBackupCodes } from '../HilosBackupCodes.vue'
export { default as HilosAccountDeletion } from '../profile/HilosAccountDeletion.vue'
export { default as HilosProfileSecurityPage } from '../profile/HilosProfileSecurityPage.vue'
export { default as HilosProfileSessions } from '../profile/HilosProfileSessions.vue'
export { default as HilosProfileDevices } from '../profile/HilosProfileDevices.vue'
export { default as ConflictActions } from '../ConflictActions.vue'
export { default as ConflictHeader } from '../ConflictHeader.vue'
export { default as LoadingButton } from '../LoadingButton.vue'
export { default as HilosSwitch } from '../HilosSwitch.vue'
export { default as HilosModal } from '../HilosModal.vue'
export { default as HilosLongText } from '../HilosLongText.vue'
export { default as HilosActionError } from '../HilosActionError.vue'
export { default as HilosActionReason } from '../HilosActionReason.vue'
export { default as HilosFormError } from '../HilosFormError.vue'
export { default as HilosSendProgress } from '../HilosSendProgress.vue'
export { default as HilosUploadDrop } from '../HilosUploadDrop.vue'
export { default as HilosUploadFiles } from '../HilosUploadFiles.vue'
export { default as HilosUploadPicker } from '../HilosUploadPicker.vue'
export { default as HilosStepUpStep } from '../auth/HilosStepUpStep.vue'
export { default as HilosEditNotice } from '../HilosEditNotice.vue'
export { default as HilosToastHost } from '../HilosToastHost.vue'
export type { HilosToastCorner } from '../hilosToastCorner.js'
export { default as HilosAuthSurface } from '../auth/HilosAuthSurface.vue'
export { default as HilosMagicLinkPage } from '../auth/HilosMagicLinkPage.vue'
export { default as HilosSecondFactorCancelPage } from '../auth/HilosSecondFactorCancelPage.vue'
export { default as HilosOAuthCallbackPage } from '../auth/HilosOAuthCallbackPage.vue'
export { hilosAuthGateKey } from '../auth/hilosAuthGateKey.js'

export { default as HilosProfilePasswordChange } from '../profile/HilosProfilePasswordChange.vue'

export { default as HilosDataExport } from '../profile/HilosDataExport.vue'

export { default as HilosLegalChanges } from '../legal/HilosLegalChanges.vue'
export { default as HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.vue'
export { default as HilosProfileAgreementsPage } from '../profile/HilosProfileAgreementsPage.vue'
export { default as HilosProfileAgreementsHistoryPage } from '../profile/HilosProfileAgreementsHistoryPage.vue'

export { default as HilosLegalConsent } from '../legal/HilosLegalConsent.vue'
export { default as HilosLegalReconsent } from '../legal/HilosLegalReconsent.vue'
