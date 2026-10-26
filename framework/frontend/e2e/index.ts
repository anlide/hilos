// The e2e toolbox shared by the demo suites: helpers that drive the browser the
// same way whatever the demo, because what they drive is the framework's own
// surface — or a screen of a stand resident, like the OAuth emulator's consent
// window, which is the same in every demo too. A demo keeps its own
// `tests/e2e/helpers/` for what belongs to that demo alone; anything that would
// read the same in another demo belongs here.
//
// The demos reach these by relative path, the way their Playwright configs
// already reach `framework/frontend/scripts/timeout-scale.mjs` — the runner
// mounts the whole repository, so no package boundary stands in between.
//
// ONE RULE holds this together: a file here imports from `@playwright/test` with
// `import type` and nothing else. The demo suite carries its own installed copy
// of Playwright, and loading a second one from this folder's node_modules is
// refused by the runner itself ("Requiring @playwright/test second time"). A
// type import is erased before any of that happens; everything a helper needs at
// runtime arrives through the `Page` it is handed, and a check's `expect` through
// its first parameter.
// ESLint holds the rule: eslint.config.mjs refuses a value import or re-export
// of @playwright/test anywhere under e2e/, so `npm run lint` goes red before
// any demo runner does.

export {
  fitsInScrollport,
  liesAbove,
  overlapSpot,
  watchFirstRowTop,
  watchHeight,
  watchLeft,
  watchTop,
  watchTopWithin,
  watchTableGeometry,
  watchTableHandoff,
  watchTableFirstLive,
  expectTableWrappingStress,
  expectTableRecordWrappingStress,
  settleTableLayout,
  tableTextRegion,
  expectTableTextTrack,
  watchTableTextRoom,
  watchTableTextInline,
  expectTableTextGeometry,
  expectTableTextParts,
  expectTableIntrinsicRows,
  type TableTextPolicy,
  type TableGeometryPlan,
  type TableGeometryWatch,
  Watched,
} from './geometry.js'
export {
  armSocketDrop,
  controlSocketReconnect,
  countSentFrames,
  createSocketFrameReader,
  dropSocket,
  forwardSessionTimeZone,
  watchSocketFrames,
  type SocketReconnectControl,
  type SocketFrame,
} from './socket.js'
export {
  openRawWebSocket,
  type RawWebSocket,
  type RawWebSocketCloser,
  type RawWebSocketStand,
} from './rawWebSocket.js'
export {
  clickUnavailableControl,
  expectConnectedInteraction,
  expectDisconnectedInteraction,
  expectWelcomedSockets,
  expectSessionRotation,
  watchInteractionNodes,
  watchOpenedSockets,
  type DisconnectedInteractionControls,
  type OpenedSocket,
} from './connection.js'
export {
  deliveryObject,
  waitDeliveryBoundary,
  waitForDeliveryQuiet,
  watchDeliveryFrames,
  type DeliveryFrameLog,
  type DeliveryPacket,
  type DeliveryRequest,
} from './deliveryFrames.js'
export {
  deliverySettingUpdates,
  saveDeliverySetting,
  type DeliverySettingUpdate,
  type DeliverySettingMutation,
} from './deliverySettings.js'
export {
  watchTableAllocation,
  setTableEnvironment,
  loadLateTableFont,
  expectTableLayoutZoom,
  watchTableNativeLifecycle,
  expectTableNativeLifecycle,
  type TableEnvironment,
} from './tableLifecycle.js'
export {
  watchHandshakeFrames,
  type HandshakeFrameLog,
} from './handshakeFrames.js'
export { openFirstFrame } from './firstFrame.js'
export {
  PASSKEY_PLATFORMS,
  platformsLeftOut,
  readPasskeyCreations,
  watchPasskeyCreation,
  type PasskeyCreation,
  type PasskeyPlatform,
} from './passkeyCreation.js'
export { addVirtualAuthenticator } from './virtualAuthenticator.js'
export {
  dismissToasts,
  expectToastPlacement,
  type ToastPlacement,
} from './toasts.js'
export {
  CIRCLE_OFFLINE,
  CIRCLE_ONLINE,
  MAINTENANCE_ON_NOTICE,
  MAINTENANCE_PATH,
  MAINTENANCE_CIRCLE_PATH,
  MANUAL_MAINTENANCE_OPERATION,
  MANUAL_STUB_MESSAGE,
  MANUAL_STUB_TITLE,
  addToMaintenanceCircle,
  clearMaintenanceCircle,
  confirmMaintenanceCircleRemoval,
  expectAdmittedToManualMaintenance,
  expectHeldByManualMaintenance,
  expectMaintenanceCodeIssued,
  expectMaintenanceOpen,
  expectMaintenancePageHead,
  expectManualMaintenanceOn,
  issueMaintenanceCode,
  maintenanceCircleOnline,
  maintenanceCircleRow,
  presentMaintenanceCode,
  turnOffMaintenance,
  turnOnMaintenance,
} from './maintenance.js'
export { enableEmailChannel, openBell, unreadBadge } from './notifications.js'
export {
  clearCustomSetting,
  draftCustomSetting,
  openSettingEdit,
  setCustomSetting,
} from './settings.js'
export {
  ROW_PREFIX,
  ROWS,
  TABLE,
  settleRemovedRow,
  shownByTestId,
  tableTextToggle,
  expectTableTextControl,
  tableRowKeyByText,
  advanceTableToRow,
  openTableFixture,
  expectDataFreeDeparture,
  expectInertTableHolders,
  expectTableCardContentFits,
  expectTableProgressCoverage,
  expectTableRowOrder,
  screenshotTable,
} from './table.js'
export {
  abandonConsent,
  chooseAccount,
  confirmConsent,
  denyConsent,
  signInAs,
  waitForProviderWindow,
} from './standOAuthUser.js'
export { acceptTermsAsNewAccount, signUpAs } from './providerSignUp.js'
export { downloadBytes } from './download.js'
export { dropFiles, type DroppedFile } from './upload.js'
export { connectFirstApp, type ConnectedApp } from './secondFactor.js'
export { leaveSecondAdmin } from './secondAdmin.js'
export {
  SESSION_COOKIE_PREFIX,
  ROTATE_COOKIE_SUFFIX,
  isSessionCookie,
  isRotateCookie,
} from './sessionCookie.js'
export {
  ANALYTICS_SIGN_UP_LAST_ACTION,
  ANALYTICS_WRITER_TIMEOUT_MS,
  analyticsPeopleViewports,
  analyticsPersonLink,
  analyticsSessionCell,
  analyticsSessionLink,
  expectAnalyticsActionRows,
  expectAnalyticsActionsInOrder,
  expectAnalyticsActionsOnPage,
  expectAnalyticsDashboardCard,
  expectAnalyticsOverview,
  expectAnalyticsOverviewTiles,
  expectAnalyticsPeopleAccessibility,
  expectAnalyticsPeopleFacets,
  expectAnalyticsPeoplePerson,
  expectAnalyticsPeopleWindow,
  expectAnalyticsPersonNavigation,
  expectAnalyticsPersonSession,
  expectAnalyticsSectionHead,
  expectAnalyticsSessionFrames,
  expectAnalyticsSessionHeader,
  expectNoAnalyticsBodies,
  expectNoAnalyticsPersonSession,
  expectOtherAnalyticsSession,
  readAnalyticsActionRows,
  readAnalyticsBrowserSession,
  readAnalyticsOverviewSnapshot,
  watchAnalyticsFrames,
  waitAnalyticsBrowserSession,
  type AnalyticsActionRow,
  type AnalyticsBrowserSession,
  type AnalyticsFrame,
  type AnalyticsFrameLog,
  type AnalyticsOverviewSnapshot,
} from './analytics.js'

export {
  CHANGE_LOG_PATH,
  CHANGE_LOG_TABLES_PATH,
  CHANGE_LOG_LEAD,
  CHANGE_LOG_TABLES_LEAD,
  CHANGE_LOG_BLOCK_ACTION,
  chooseChangeLogFeedTable,
  expectChangeLogDashboardCard,
  expectChangeLogOverview,
  expectChangeLogFeedRow,
  expectChangeLogReceiptOpen,
  expectChangeLogReceiptMoment,
  expectChangeLogTimes,
  watchChangeLogDisplay,
  expectFreshChangeLogReceiptWriter,
  expectChangeLogReceiptWriter,
  expectChangeLogValues,
  expectRegistrationFeedRow,
  expectRegistrationHistory,
  expectRegistrationReceiptFacts,
  expectTrackedTablesPage,
  expectChangeLogReference,
  expectTrackedTableRecord,
  expectTrackedTableWhole,
} from './changeLog.js'

export {
  DAEMON_ROOT_PATH,
  DAEMON_NODE_PATH,
  DAEMON_NODE_TABS,
  expectDaemonNodeHead,
  expectDaemonNodeFits,
  expectDaemonNodeTableName,
  watchDaemonNodeFrames,
  expectDaemonNodeAnswer,
  expectDaemonNodeLive,
  expectStandaloneNodeOverview,
  DAEMON_WORKERS_PATH,
  DAEMON_WORKERS_CHILD,
  DAEMON_ROOT_CRUMB,
  DAEMON_PLACEMENT_TYPE,
  DAEMON_PLACEMENT_LONG_TYPE,
  DAEMON_PLACEMENT_CELL,
  DAEMON_PLACEMENT_TOTALS,
  expectDaemonPage,
  expectStandaloneOverview,
  expectStandalonePlacement,
  expectUnfilteredTotals,
  expectStandaloneWorkers,
  expectStandaloneCron,
  readDaemonCronMoment,
  expectDaemonCronMoments,
  watchDaemonCronDisplay,
  expectStandaloneHttp,
  expectStandaloneEnvironment,
  expectStandaloneMismatch,
  expectClusterOverview,
  expectClusterDaemonNodePage,
  expectClusterDaemonNodeOverview,
  expectClusterDaemonCronSchedule,
  expectDaemonWorkerAgent,
  expectClusterLeaderPlacement,
  expectClusterEnvironmentMismatch,
  watchDaemonMismatchFrames,
  watchDaemonCronFrames,
} from './daemon.js'
export {
  RESTART_AGENT,
  RESTART_MARK_TIMEOUT_MS,
  readRestartWorker,
  withStoppedRestartWorker,
  type RestartWorker,
} from './daemonRestart.js'

export {
  expectCountryDeleteDescription,
  expectCountryPageHead,
  expectLanguagePageHead,
  expectDefaultLanguageHeader,
  expectDeletedCountryCard,
  expectDeletedCountryCardReloaded,
  expectI18nName,
  expectI18nPageHead,
  expectNoOtherLanguageNames,
  i18nLocaleRow,
  i18nNameRow,
  watchI18nLivePages,
} from './i18n.js'
export {
  watchPageErrorFrames,
  type PageErrorFrameLog,
} from './pageErrorFrames.js'
export {
  DARK_FRAME,
  LIGHT_FRAME,
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
  THEME_TEST_ICONS,
  THEME_TEST_LABELS,
  THEME_TEST_POSITIONS,
  chooseHeaderTheme,
  chooseProfileTheme,
  emulateSystemTheme,
  expectProfileTheme,
  expectThemeFirstFrame,
  expectThemeHeader,
  expectThemeMode,
  expectUnavailableThemeHeader,
  openProfileTheme,
  type ThemeTestPick,
  type ThemeTestPosition,
} from './theme.js'
export { watchThemeFrames, type ThemeFrameLog } from './themeFrames.js'
export {
  chooseProfileTimeZone,
  expectProfileTimeZone,
  expectProfileTimeZoneCatalog,
  expectProfileTimeZoneSearch,
  openProfileTimeZone,
} from './timeZone.js'
export {
  APPEARANCE_PATH,
  THEME_DEFAULT_KEY,
  THEME_SWITCHING_KEY,
  appearanceSettingValue,
  expectAppearancePageHead,
  expectAppearanceRows,
  openAppearanceSetting,
  saveAppearanceDefaultTheme,
  saveAppearanceSwitching,
} from './appearance.js'

export { expectHilosUserBlockSettled } from './hilosUserLifecycle.js'
export {
  expectHilosUserOverview,
  expectHilosUserOverviewAccessibility,
} from './hilosUserOverview.js'
export {
  expectHiddenUserTimeZones,
  expectUserActivityDisplay,
  expectUserActivityMoment,
  expectUserActivityWire,
  expectUserTimeZone,
  userTableRows,
  type UserTableRow,
} from './users.js'
export { expectPasswordVisibility } from './passwordVisibility.js'
export { expectDesktopShell } from './layout.js'

export {
  readClusterBrowserStand,
  newClusterMasterPage,
  setClusterMaster,
  clusterCommandChannel,
  expectClusterLeaderUnchanged,
  expectClusterAnalyticsAgents,
  watchClusterMasterUpgrades,
  markClusterNodeLogs,
  expectNoProtectedModeAgentStops,
  STAND_MASTER_COOKIE,
  type ClusterBrowserStand,
  type ClusterInspection,
  type ClusterNodeLogMark,
} from './cluster.js'

export {
  readMonopolisticWorker,
  type MonopolisticWorker,
} from './daemonWorker.js'
export { watchUploadFrames } from './uploadFrames.js'
export { watchTableSearch, watchTableModalFocus } from './tableFocus.js'
export { watchTableContinuity } from './tableContinuity.js'
export { watchDaemonSource } from './daemonSource.js'
export {
  expectLogsPageHead,
  watchLogFrames,
  logMomentLabel,
  logBatchDirectory,
  expectLogUtcMoment,
  expectLogEpochMoment,
  expectLogLineClock,
  expectLogBatchViewer,
  type LogFrameLog,
} from './logs.js'
export {
  backupMomentRow,
  enterCalendarDate,
  expectBackupMoments,
  expectLegalAcceptanceMoments,
  expectMaintenanceMoment,
  expectReaderMillisecondMoment,
  expectReaderMoment,
  legalAcceptanceMomentRow,
  readerMillisecondMoment,
  readerMoment,
} from './moments.js'
export {
  expectProfileSessionMoments,
  profileSessionSnapshot,
  type ProfileSessionSnapshot,
} from './profileSessions.js'
export {
  bundleFiles,
  expectBundleRequests,
  expectLazyPageFailure,
  expectLazyAdminPage,
  expectLazyAdminPending,
  expectLazyDeparture,
  nextClientPaint,
  settleClientBundles,
  expectPublicBundle,
  expectServerRefusal,
  holdClientBundle,
  holdPageReply,
  readClientBundleReport,
  selectLazyBundle,
  siblingExclusiveFiles,
  watchClientBundles,
  watchClientDocument,
  waitClientBundle,
  watchPagePresentation,
  watchPageReplies,
  type ClientBundleReport,
  type ClientBundleRequest,
  type HeldClientBundle,
  type LazyBundleSection,
  type PageReply,
  type PagePresentationSnapshot,
} from './lazyBundles.js'

export {
  STATUS_PATH,
  STATUS_COMPONENTS_PATH,
  STATUS_LEAD,
  watchStatusFrames,
  expectStatusHead,
  submitStatusIncident,
  expectStatusIncidentDialog,
} from './status.js'

export {
  PUBLIC_STATUS_PATH,
  PUBLIC_STATUS_FILES,
  watchPublicStatus,
  expectPublicStatusPair,
  expectPublicStatusArchive,
  expectPublicStatusStale,
  expectPublicStatusReadOnly,
  expectPublicStatusAccessibility,
  type PublicStatusLog,
  type DisplayedStatusPair,
} from './publicStatus.js'

export {
  communicationsChannelReplies,
  expectCommunicationsAccessibility,
  expectCommunicationsChannelHead,
  expectCommunicationsOverviewSummary,
  expectCommunicationsSummaryUpdate,
  resetCommunicationsField,
  saveCommunicationsField,
} from './communications.js'
