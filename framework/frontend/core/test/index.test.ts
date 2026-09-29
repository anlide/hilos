// Pins the public surface: everything an adapter or demo may import from
// '@hilos/core' is exported here. Behavior is covered by the module tests.
import { expect, it } from 'vitest'
import {
  HilosConnection,
  parseSignal,
  assertNever,
  computeBackoffDelay,
  isReconnectDragging,
  RECONNECT_DRAGGING_COPY,
  browserRefusesCookies,
  COOKIES_REFUSED_COPY,
  createPageRouter,
  createAppPageRouter,
  createHilosRouter,
  browserNavigationEnvironment,
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
  createHilosConnection,
  bindSessionScope,
  sessionUserName,
  sessionAdminViewMode,
  bindAdminAccess,
  hilosAdminAccess,
  type HilosAdminAccess,
  actionFailureReason,
  HILOS_VIEW_MODE_COPY,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  VIEW_MODE_ERROR_CODE,
  SESSION_SIGNAL_SCHEMAS,
  applyServerTime,
  offsetMs,
  toLocal,
  bootHilos,
  createAuthGate,
  createAuthFlow,
  PASSWORD_FLOW_METHOD,
  HILOS_ROUTE_DECLARATIONS,
  HILOS_PAGE_ROUTES,
  SIGNAL_TYPE_PAGE_RESPONSE,
  createDeferredFlagState,
  DEFAULT_SKELETON_DELAY_MS,
  HILOS_SKELETON_LINES,
  bindAccountBlocked,
  hilosAccountBlocked,
  dismissAccountBlocked,
  sessionAccountBlocked,
  ACCOUNT_BLOCKED_ACTION_DISMISS,
  ACCOUNT_BLOCKED_COPY,
  OAUTH_REASON_ACCOUNT_BLOCKED,
  bindAccountStanding,
  hilosAccountStanding,
  hilosDeletionStrip,
  hilosSessionAvatarMark,
  hilosStandingTone,
  keepMyAccount,
  sessionAccountStanding,
  readHilosAccountStanding,
  ACCOUNT_FROZEN_ERROR_CODE,
  ACCOUNT_STANDING_STRIP_COPY,
  HILOS_FROZEN_OPEN_PAGES,
  createHilosUserStanding,
  hilosStandingBadge,
  hilosUserFrozenRow,
  hilosUsersPath,
  hilosLegalLapsedHref,
  SIGNAL_ACCOUNT_STANDING_STATE,
  bindUploads,
  cancelUpload,
  uploadFile,
  UPLOAD_ACTION_INIT,
  type DeferredDelay,
  type DeferredFlagState,
} from '../src/index.js'

it('exports the @hilos/core public surface', () => {
  expect(HilosConnection).toBeTypeOf('function')
  expect(parseSignal).toBeTypeOf('function')
  expect(assertNever).toBeTypeOf('function')
  expect(computeBackoffDelay).toBeTypeOf('function')
  expect(isReconnectDragging).toBeTypeOf('function')
  expect(RECONNECT_DRAGGING_COPY.dragging).toBeTypeOf('string')
  expect(browserRefusesCookies).toBeTypeOf('function')
  expect(COOKIES_REFUSED_COPY.title).toBeTypeOf('string')
  expect(createPageRouter).toBeTypeOf('function')
  expect(createAppPageRouter).toBeTypeOf('function')
  expect(createHilosRouter).toBeTypeOf('function')
  expect(browserNavigationEnvironment).toBeTypeOf('function')
  expect(bindPageScope).toBeTypeOf('function')
  expect(PAGE_SIGNAL_SCHEMAS[SIGNAL_TYPE_PAGE_RESPONSE]).toBeDefined()
  expect(createHilosConnection).toBeTypeOf('function')
  expect(bindSessionScope).toBeTypeOf('function')
  expect(sessionUserName).toBeTypeOf('function')
  expect(sessionAdminViewMode).toBeTypeOf('function')
  expect(bindAdminAccess).toBeTypeOf('function')
  const access: HilosAdminAccess = hilosAdminAccess.get()
  expect(access).toBe('none')
  expect(actionFailureReason).toBeTypeOf('function')
  expect(HILOS_VIEW_MODE_COPY.refusal).toBeTypeOf('string')
  expect(HILOS_VIEW_MODE_STRIP_TEXT_ID).toBeTypeOf('string')
  expect(VIEW_MODE_ERROR_CODE).toBeTypeOf('string')
  expect(SESSION_SIGNAL_SCHEMAS['handshake_response']).toBeDefined()
  expect(applyServerTime).toBeTypeOf('function')
  expect(offsetMs).toBeTypeOf('function')
  expect(toLocal).toBeTypeOf('function')
  expect(bootHilos).toBeTypeOf('function')
  expect(createAuthGate).toBeTypeOf('function')
  expect(createAuthFlow).toBeTypeOf('function')
  expect(PASSWORD_FLOW_METHOD.kind).toBe('identifier')
  expect(HILOS_ROUTE_DECLARATIONS).toBeTypeOf('object')
  expect(HILOS_PAGE_ROUTES).toBeTypeOf('object')
  expect(SIGNAL_TYPE_PAGE_RESPONSE).toBe('page_response')
  expect(createDeferredFlagState).toBeTypeOf('function')
  expect(DEFAULT_SKELETON_DELAY_MS).toBeTypeOf('number')
  expect(HILOS_SKELETON_LINES).toEqual([9, 6, 10])
  expect(bindAccountBlocked).toBeTypeOf('function')
  expect(hilosAccountBlocked.get()).toBeNull()
  expect(dismissAccountBlocked).toBeTypeOf('function')
  expect(sessionAccountBlocked).toBeTypeOf('function')
  expect(ACCOUNT_BLOCKED_ACTION_DISMISS).toBe('hilos_dismiss_account_blocked')
  expect(ACCOUNT_BLOCKED_COPY.title).toBe('Access closed')
  expect(OAUTH_REASON_ACCOUNT_BLOCKED).toBe('account_blocked')
  expect(bindAccountStanding).toBeTypeOf('function')
  expect(hilosAccountStanding.get()).toBeNull()
  expect(hilosDeletionStrip.get()).toBeNull()
  expect(hilosSessionAvatarMark.get()).toBeNull()
  expect(hilosStandingTone('frozen')).toBe('info')
  expect(keepMyAccount).toBeTypeOf('function')
  expect(sessionAccountStanding).toBeTypeOf('function')
  expect(readHilosAccountStanding(null)).toBeNull()
  expect(ACCOUNT_FROZEN_ERROR_CODE).toBe('account_frozen')
  expect(ACCOUNT_STANDING_STRIP_COPY.keep).toBe('Keep my account')
  expect(HILOS_FROZEN_OPEN_PAGES).toHaveLength(7)
  expect(createHilosUserStanding).toBeTypeOf('function')
  expect(hilosStandingBadge('none')).toBeNull()
  expect(hilosUserFrozenRow(null)).toBeNull()
  expect(hilosUsersPath('privacy')).toBe('/hilos/users/privacy')
  expect(hilosLegalLapsedHref('terms')).toBe('/hilos/users/terms')
  expect(SIGNAL_ACCOUNT_STANDING_STATE).toBe('hilos_account_standing_state')
  expect(bindUploads).toBeTypeOf('function')
  expect(uploadFile).toBeTypeOf('function')
  expect(cancelUpload).toBeTypeOf('function')
  expect(UPLOAD_ACTION_INIT).toBe('hilos_upload_init')
  const delay: DeferredDelay = DEFAULT_SKELETON_DELAY_MS
  const flag: DeferredFlagState = createDeferredFlagState(delay)
  expect(flag.shown.get()).toBe(false)
  flag.dispose()
})
