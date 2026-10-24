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
} from '../connectionStateSignal.js'
export {
  hilosSignal,
  mirrorHilosSignal,
  type HilosSignalOptions,
} from '../hilosSignal.js'
export { entitySignal } from '../entitySignal.js'
export {
  createHilosTrackedAction,
  type HilosTrackedAction,
} from '../hilosTrackedAction.js'
export { HILOS_ROUTER } from '../hilosRouterToken.js'
export { HILOS_VIEW_LAYER } from '../hilosViewLayer.js'
export {
  HILOS_TABLE_SELECTION_EDGE,
  type HilosTableSelectionEdge,
} from '../hilosTableSelectionEdge.js'
export { HILOS_PAGE_HEADING_ID } from '../hilosPageHeadingToken.js'
export {
  HILOS_TAKEOVER_VIEW_ONLY,
  injectLookOnly,
  type LookOnly,
} from '../hilosLookOnly.js'
export { HILOS_AUTH_GATE } from '../auth/hilosAuthGateToken.js'
export { HilosAuthSurface } from '../auth/HilosAuthSurface.js'
export { HilosMagicLinkPage } from '../auth/HilosMagicLinkPage.js'
export { HilosSecondFactorCancelPage } from '../auth/HilosSecondFactorCancelPage.js'
export { HilosOAuthCallbackPage } from '../auth/HilosOAuthCallbackPage.js'
export { HilosLink } from '../HilosLink.js'
export { HilosView } from '../HilosView.js'
export { HilosSkeleton } from '../HilosSkeleton.js'
export { HilosAvatar } from '../HilosAvatar.js'
export { HilosHiddenMark } from '../HilosHiddenMark.js'
export { HilosHideable, type HilosHideableContext } from '../HilosHideable.js'
export { ErrorPage } from '../ErrorPage.js'
export { HilosMaintenance } from '../HilosMaintenance.js'
export { HilosLayout } from '../HilosLayout.js'
export {
  HILOS_CONNECTION_INTERACTION,
  injectConnectionInteraction,
  type ConnectionInteraction,
} from '../hilosConnectionInteraction.js'
export { HilosThemeMenu } from '../HilosThemeMenu.js'
export { HilosStaticPage } from '../HilosStaticPage.js'
export { HilosAboutPage } from '../public/HilosAboutPage.js'
export { HilosLicensePage } from '../public/HilosLicensePage.js'
export { HilosPrivacyPage } from '../public/HilosPrivacyPage.js'
export { HilosTermsPage } from '../public/HilosTermsPage.js'
export { LoadingButton } from '../LoadingButton.js'
export { HilosSwitch } from '../HilosSwitch.js'
export { HilosBreadcrumb } from '../HilosBreadcrumb.js'
export { HilosPageHead } from '../HilosPageHead.js'
export { ConflictHeader } from '../ConflictHeader.js'
export {
  ConflictActions,
  type ConflictSaveButtonContext,
} from '../ConflictActions.js'
export { HilosAdminPage } from '../HilosAdminPage.js'
export { HilosNotificationBell } from '../HilosNotificationBell.js'
export { HilosNotificationPreferences } from '../HilosNotificationPreferences.js'
export { HilosPushDeviceToggle } from '../HilosPushDeviceToggle.js'
export { HilosQrCode } from '../HilosQrCode.js'
export { HilosBackupCodes } from '../HilosBackupCodes.js'
export { HilosAccountDeletion } from '../profile/HilosAccountDeletion.js'
export { HilosProfileSecurityPage } from '../profile/HilosProfileSecurityPage.js'
export { HilosProfileSessions } from '../profile/HilosProfileSessions.js'
export { HilosProfileDevices } from '../profile/HilosProfileDevices.js'
export { HilosModal, type ModalActionsContext } from '../HilosModal.js'
export { HilosLongText } from '../HilosLongText.js'
export { HilosActionError } from '../HilosActionError.js'
export { HilosFormError } from '../HilosFormError.js'
export { HilosSendProgress } from '../HilosSendProgress.js'
export { HilosUploadDrop } from '../HilosUploadDrop.js'
export { HilosUploadFiles } from '../HilosUploadFiles.js'
export { HilosUploadPicker } from '../HilosUploadPicker.js'
export { HilosStepUpStep } from '../auth/HilosStepUpStep.js'
export { HilosEditNotice } from '../HilosEditNotice.js'
export { HilosToastHost } from '../HilosToastHost.js'
export type { HilosToastCorner } from '../hilosToastCorner.js'
export {
  HilosViewportTable,
  type BulkUntouchedContext,
  type ViewportTableProgressContext,
  type ViewportTableRowProgressContext,
} from '../HilosViewportTable.js'
export {
  HilosTableCell,
  type HilosTableCellContext,
} from '../HilosTableCell.js'
export { HilosTableDetail } from '../HilosTableDetail.js'
export {
  HilosDropdown,
  type DropdownToggleContext,
  type DropdownOptionContext,
} from '../HilosDropdown.js'
export type { HilosDropdownOption } from '../hilosDropdownOption.js'
export type { HilosTableColumn } from '@hilos/core/base'

export { HilosPageHeading } from '../HilosPageHeading.js'
export { HilosProfilePage } from '../profile/HilosProfilePage.js'
export { HilosProfileRename } from '../profile/HilosProfileRename.js'
export { HilosProfilePhoto } from '../profile/HilosProfilePhoto.js'
export { HilosProfileEmailChange } from '../profile/HilosProfileEmailChange.js'
export { HilosProfileTheme } from '../profile/HilosProfileTheme.js'
export { HilosProfileSignInPage } from '../profile/HilosProfileSignInPage.js'
export { HilosProfileNotificationsPage } from '../profile/HilosProfileNotificationsPage.js'
export { HilosProfileDataPage } from '../profile/HilosProfileDataPage.js'

export { HilosProfilePasswordChange } from '../profile/HilosProfilePasswordChange.js'

export { HilosDataExport } from '../profile/HilosDataExport.js'

export { HilosLegalChanges } from '../legal/HilosLegalChanges.js'

export { HilosLegalRevisionText } from '../legal/HilosLegalRevisionText.js'

export { HilosProfileAgreementsPage } from '../profile/HilosProfileAgreementsPage.js'

export { HilosProfileAgreementsHistoryPage } from '../profile/HilosProfileAgreementsHistoryPage.js'

export { HilosLegalConsent } from '../legal/HilosLegalConsent.js'
export { HilosLegalReconsent } from '../legal/HilosLegalReconsent.js'
