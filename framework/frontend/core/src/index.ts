// @hilos/core — the framework-agnostic Hilos frontend core.
//
// All non-visual logic lives here and never imports a UI framework: the WS
// transport, the signal/action protocol and its discriminated-union parsers,
// the subscription manager, the normalized entity store, and the headless
// table / modal / conflict state machines. Fills in across rewrite step 7;
// shipped so far: the connection machine, the signal parse boundary with the
// project-signal schema seam, the reactive signal primitive the stores build
// on, the scope-partitioned stores, the normalizer ingest boundary, and the
// page-subscription manager.

export {
  createSignal,
  computedSignal,
  subscribeSignal,
  type ReadonlySignal,
  type WritableSignal,
  type Unsubscribe,
} from './state/signal.js'
export {
  createHilosToastStore,
  hilosToasts,
  type HilosToast,
  type HilosToastOptions,
  type HilosToastSeverity,
  type HilosToastStore,
} from './state/toasts.js'
export {
  EntityStore,
  type EntityId,
  type EntityRef,
  type EntitySnapshot,
} from './state/EntityStore.js'
export { type Entity, type User } from './state/entity.js'
export {
  entityCollection,
  type EntityCollection,
} from './state/EntityCollection.js'
export {
  readBoolean,
  readNumber,
  readNumberOrNull,
  readString,
  readStringOrNull,
} from './state/fieldReaders.js'
export { Scope, ScopeManager, type ScopeKind } from './state/ScopeManager.js'
export { DataStore } from './state/DataStore.js'
export { ListStore, type ListItem } from './state/ListStore.js'
export { TableRowsStore, type TableRow } from './state/TableRowsStore.js'
export {
  ingest,
  type EntityFragment,
  type ListItemFragment,
  type ListSection,
  type TableRowFragment,
  type TableSection,
  type NormalizerOptions,
  type ScopePayload,
} from './state/normalizer.js'
export {
  TableViewportController,
  type SortDirection,
  type TableSort,
  type TableViewportControllerOptions,
  type TableViewportRow,
  type TableViewportDelta,
} from './table/TableViewportController.js'
export { type HilosTableColumn } from './table/hilosTableColumn.js'
export {
  createLoadingButtonState,
  DEFAULT_SPINNER_DELAY_MS,
  type LoadingButtonState,
  type SpinnerDelay,
} from './primitives/loadingButton.js'
export {
  createModalController,
  type ModalController,
  type ModalControllerOptions,
} from './primitives/modal.js'
export { FocusTrap, focusableElements, focusInitial } from './dom/focusTrap.js'
export { lockBodyScroll, unlockBodyScroll } from './dom/scrollLock.js'
export {
  PageSubscription,
  type PageSubscriptionConnection,
} from './subscription/PageSubscription.js'
export {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from './subscription/bindPageScope.js'
export {
  bindTableViewport,
  type TableViewportAddress,
} from './subscription/bindTableViewport.js'
export {
  bindSessionScope,
  sessionUserName,
  sessionUserId,
  sessionImpersonating,
  sessionImpersonatedByName,
  SESSION_SIGNAL_SCHEMAS,
  type SessionScopeOptions,
} from './session/sessionScope.js'
export {
  bindNotificationsScope,
  createHilosNotificationStore,
  hilosNotifications,
  notificationGroupName,
  NOTIFICATION_ACTION_MARK_ALL_READ,
  NOTIFICATION_ACTION_MARK_READ,
  NOTIFICATION_ACTION_SYNC,
  NOTIFICATION_SIGNAL_CREATED,
  NOTIFICATION_SIGNAL_READ,
  NOTIFICATION_SIGNAL_SCHEMAS,
  NOTIFICATION_SIGNAL_SNAPSHOT,
  type HilosNotification,
  type HilosNotificationSnapshot,
  type HilosNotificationStore,
} from './notifications/notificationCenter.js'
export { bootHilos, type BootHilosConfig } from './bootstrap/bootHilos.js'
export {
  createAuthGate,
  type AuthGate,
  type AuthGateOptions,
  type AuthGateRouter,
  type AuthGateActionErrorSource,
} from './auth/authGate.js'
export {
  createAuthSurface,
  isAuthSubmittable,
  authEntries,
  PASSWORD_MIN_LENGTH,
  PASSWORD_AUTH_METHOD,
  SMS_AUTH_METHOD,
  MAGIC_LINK_AUTH_METHOD,
  OAUTH_GITHUB_AUTH_METHOD,
  PASSKEY_AUTH_METHOD,
  PASSKEY_DISCOVERABLE_AUTH_METHOD,
  type AuthSurface,
  type AuthSurfaceOptions,
  type AuthMethodDescriptor,
  type AuthFormState,
  type AuthField,
  type AuthEntry,
  type AuthMode,
  type AuthSubmitOutcome,
} from './auth/authSurface.js'
export {
  createPasskey,
  getPasskey,
  isPasskeySupported,
  base64UrlEncode,
  base64UrlDecode,
  type PasskeyDescriptor,
  type PasskeyCreationOptions,
  type PasskeyRequestOptions,
  type PasskeyRegistrationResponse,
  type PasskeyAssertionResponse,
} from './auth/passkey.js'
export {
  SIGNAL_TYPE_HANDSHAKE,
  SIGNAL_TYPE_PAGE_SUBSCRIBE,
  SIGNAL_TYPE_PAGE_UNSUBSCRIBE,
  SIGNAL_TYPE_PAGE_RESPONSE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
  SIGNAL_TYPE_ACTION,
  SIGNAL_TYPE_ACTION_ERROR,
  FIELD_TYPE,
  FIELD_PAGE,
  FIELD_PARAMS,
  FIELD_ACTION,
  FIELD_DATA,
  KEEPALIVE_TEXT_PING,
} from './protocol/constants.js'
export {
  pageSubscriptionErrorSchema,
  type PageSubscriptionError,
} from './protocol/pageError.js'
export {
  createPageRouter,
  type PageRouter,
  type PageRouteMatch,
  type PageRouterOptions,
} from './routing/PageRouter.js'
export { createAppPageRouter } from './routing/appPageRouter.js'
export {
  createHilosRouter,
  browserNavigationEnvironment,
  type HilosRouter,
  type NavigablePages,
  type NavigationEnvironment,
} from './routing/HilosRouter.js'
export {
  HilosPages,
  HILOS_PAGE_ROUTES,
  HILOS_FOOTER_LINKS,
  HILOS_ADMIN_PAGES,
  HILOS_ADMIN_DASHBOARD_SECTIONS,
  type HilosFooterLink,
  type HilosAdminPageMeta,
  type HilosAdminDashboardSection,
} from './routing/hilosPages.js'
export {
  resolveHilosPath,
  hilosAdminBreadcrumb,
  hilosAdminChildren,
  type HilosCrumb,
  type HilosAdminChild,
} from './routing/hilosAdmin.js'
export { resolvePageTitle } from './routing/pageTitle.js'
export { assertNever } from './protocol/assertNever.js'
export {
  signalEnvelopeSchema,
  handshakeSignalDataSchema,
  type SignalEnvelope,
  type HandshakeSignalData,
} from './protocol/envelope.js'
export {
  entityFragmentSchema,
  listItemSchema,
  listSectionSchema,
  tableRowSchema,
  tableSectionSchema,
  scopePayloadSchema,
  pageResponseSchema,
  type ScopePayloadWire,
  type PageResponseWire,
} from './protocol/scopePayload.js'
export {
  parseSignal,
  type ParsedSignal,
  type HandshakeSignal,
  type ActionSuccessSignal,
  type ActionErrorSignal,
  type ProjectSignal,
  type ProjectSignalSchemas,
  type UnknownSignal,
  type ParseFailure,
  type ParseResult,
} from './protocol/parseSignal.js'
// The schema builder projects declare their own signal ({@link ProjectSignalSchemas})
// schemas with. Re-exported so a project uses the framework's zod instance rather
// than pinning its own — two zod copies produce incompatible `ZodType` brands the
// parse boundary would reject.
export { z } from 'zod'
export {
  ActionErrorStore,
  type ActionErrorSource,
} from './connection/ActionErrorStore.js'
export {
  ActionLifecycle,
  ActionError,
  type ActionHandle,
  type ActionFailureOutcome,
  type ActionLifecycleSource,
  type ActionLifecycleOptions,
} from './connection/actionLifecycle.js'
export {
  computeBackoffDelay,
  DEFAULT_RECONNECT_OPTIONS,
  type ReconnectOptions,
} from './connection/backoff.js'
export {
  HilosConnection,
  type ConnectionState,
  type WebSocketLike,
  type BuildMismatch,
  type HilosConnectionEventMap,
  type HilosConnectionOptions,
  type TableViewportDescriptor,
} from './connection/HilosConnection.js'
export {
  createHilosConnection,
  type CreateHilosConnectionOptions,
  type HilosConnectionBundle,
} from './connection/createHilosConnection.js'
export {
  threeWayMerge,
  type ThreeWayMergeResult,
  type ThreeWayMergeStatus,
} from './conflict/threeWayMerge.js'
export {
  toHilosPresence,
  resolveHilosUserRow,
  createHilosUsersTable,
  createHilosUserDetail,
  createHilosUserRename,
  type HilosPresence,
  type HilosUserProfile,
  type HilosUserRow,
  type HilosUsersContext,
  type HilosUsersTable,
  type HilosUserRename,
} from './admin/users/hilosUsers.js'
export {
  resolveHilosSettingRow,
  isOrphanSetting,
  isPersistedSetting,
  createHilosSettingsTable,
  createHilosSettingsActions,
  type SettingValueSource,
  type HilosSettingRow,
  type HilosSettingsContext,
  type HilosSettingsTable,
  type HilosSettingsActions,
} from './admin/settings/hilosSettings.js'
export {
  resolveHilosBackupRow,
  createHilosBackupsTable,
  createHilosBackupsActions,
  formatBackupDuration,
  formatBackupSize,
  isBackupInProgress,
  isBackupDeletable,
  isBackupKeepable,
  HILOS_BACKUP_SCOPES,
  type HilosBackupRow,
  type HilosBackupsContext,
  type HilosBackupsTable,
  type HilosBackupsActions,
  type HilosBackupScopeOption,
} from './admin/backup/hilosBackups.js'
export {
  resolveHilosChannelRow,
  resolveHilosChannelFieldRow,
  createHilosChannelsTable,
  createHilosChannelFields,
  createHilosCommunicationsActions,
  CHANNEL_ENABLED_FIELD,
  type HilosChannelRow,
  type HilosChannelFieldRow,
  type ChannelValueSource,
  type HilosCommunicationsContext,
  type HilosCommunicationsActions,
  type HilosChannelsTable,
  type HilosChannelFields,
} from './admin/communications/hilosCommunications.js'
