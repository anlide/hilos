import { flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  MAINTENANCE_STATE_SIGNAL,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  bindTimeZoneState,
  createSignal,
  hilosTimeZone,
  hilosToasts,
} from '@hilos/core/base'
import {
  HILOS_MAINTENANCE_DISABLE_COPY,
  HILOS_MAINTENANCE_ENABLE_COPY,
  HILOS_MAINTENANCE_PASS_COPY,
  HILOS_MAINTENANCE_STATE_COPY,
} from '@hilos/core/admin/maintenance'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosMaintenanceState,
  HilosRouter,
  HilosPageIdentity,
  PageRouteMatch,
  ProjectSignal,
  WritableSignal,
} from '@hilos/core/base'
import type { HilosMaintenanceContext } from '@hilos/core/admin/maintenance'

import HilosMaintenancePage from './HilosMaintenancePage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.MAINTENANCE,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/maintenance'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Maintenance',
      lead: 'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.',
      breadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.MAINTENANCE, label: 'Maintenance' },
      ],
      children: [],
      pageHeadOwner: {
        page: HilosPages.MAINTENANCE,
        label: 'Maintenance',
        lead: 'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.',
        icon: 'bi-tools',
      },
      pageTabs: [
        { page: HilosPages.MAINTENANCE, label: 'Overview' },
        { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' },
      ],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      (
        ({
          [HilosPages.DASHBOARD]: '/hilos',
          [HilosPages.MAINTENANCE]: '/hilos/maintenance',
          [HilosPages.MAINTENANCE_CIRCLE]: '/hilos/maintenance/circle',
        }) as Record<string, string>
      )[page],
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface Dispatched {
  action: string
  payload: Record<string, unknown>
  loading: WritableSignal<boolean>
  settle: (result: ActionResult) => void
  refuse: (error: ActionError) => void
}

/**
 * An action lifecycle that records what was dispatched and hands the answer back to
 * the test: the add dialog closes on the server's word, so a fake that settled by
 * itself would hide exactly the step under test.
 */
function makeActions(): {
  actions: ActionLifecycle
  dispatched: Dispatched[]
} {
  const dispatched: Dispatched[] = []
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
      let settle: (result: ActionResult) => void = () => {}
      let refuse: (error: ActionError) => void = () => {}
      const done = new Promise<ActionResult>((resolve, reject) => {
        settle = resolve
        refuse = reject
      })
      const loading = createSignal(false)
      dispatched.push({ action, payload, loading, settle, refuse })

      return {
        requestId: String(dispatched.length),
        loading,
        done,
      }
    },
  } as unknown as ActionLifecycle

  return { actions, dispatched }
}

function seededContext(actions: ActionLifecycle = makeActions().actions): {
  context: HilosMaintenanceContext
  tableTraffic: string[]
  pushState: (state: HilosMaintenanceState) => void
} {
  const tableTraffic: string[] = []
  const listeners = new Set<(signal: { type: string; data: unknown }) => void>()
  const connection = {
    registerTableWindow(): void {
      tableTraffic.push('register')
    },
    unregisterTableWindow(): void {
      tableTraffic.push('unregister')
    },
    sendTableViewport(): boolean {
      tableTraffic.push('viewport')
      return true
    },
    sendTableRendered(): boolean {
      tableTraffic.push('rendered')
      return true
    },
    sendTableRowFocus(): boolean {
      tableTraffic.push('focus')
      return true
    },
    on(
      event: string,
      listener: (signal: { type: string; data: unknown }) => void,
    ): () => void {
      if (event === 'projectSignal') listeners.add(listener)
      else tableTraffic.push(event)
      return () => {
        listeners.delete(listener)
      }
    },
  } as unknown as HilosConnection

  return {
    context: { connection, scopes: new ScopeManager(), actions },
    tableTraffic,
    pushState(state): void {
      for (const listener of listeners)
        listener({ type: MAINTENANCE_STATE_SIGNAL, data: state })
    },
  }
}

const mounted: ReturnType<typeof mount>[] = []
const cleanups: (() => void)[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  for (const cleanup of cleanups.splice(0)) cleanup()
  vi.restoreAllMocks()
  vi.useRealTimers()
  hilosToasts.clear()
})

async function mountPage(context: HilosMaintenanceContext) {
  const wrapper = mount(HilosMaintenancePage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

/** Let a settled action reach the dialog: the promise, the driver, then the render. */
async function settled(): Promise<void> {
  await flushPromises()
  await nextTick()
}

function maintenanceState(
  overrides: Partial<HilosMaintenanceState> = {},
): HilosMaintenanceState {
  return {
    phase: 'inactive',
    operation: null,
    manual: false,
    startedAt: null,
    enteredFromBrowser: false,
    passCount: 0,
    circleNamed: 0,
    circleAdmitted: 0,
    ...overrides,
  }
}

/** Drive the production zone binding while keeping real Intl output in a fixed locale. */
function readerTimeZone() {
  const DateTimeFormat = Intl.DateTimeFormat
  vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(
    function (_locales, options) {
      return new DateTimeFormat('en-US', options)
    },
  )
  const previousZone = hilosTimeZone.get()
  const listeners = new Map<string, (signal: ProjectSignal) => void>()
  const connection = {
    projectTimeZone: 'UTC',
    on(event: string, listener: (signal: ProjectSignal) => void) {
      listeners.set(event, listener)
      return () => listeners.delete(event)
    },
  } as unknown as HilosConnection
  const release = bindTimeZoneState(connection)
  const setZone = (timeZone: string): void => {
    listeners.get('projectSignal')!({
      type: 'handshake_response',
      data: { entities: { currentUser: { id: 1, timeZone } } },
    } as ProjectSignal)
  }
  cleanups.push(() => {
    setZone(previousZone)
    release()
  })
  setZone('UTC')
  return setZone
}

describe('HilosMaintenancePage reader-zone moments', () => {
  it.each([true, false])(
    'redraws the held startedAt without a maintenance frame (manual=%s)',
    async (manual) => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-01-01T00:32:35.123Z'))
      const setZone = readerTimeZone()
      setZone('America/Los_Angeles')
      const { context, pushState } = seededContext()
      const wrapper = await mountPage(context)
      const state = maintenanceState({
        phase: 'verifying',
        operation: manual ? 'manual_maintenance' : 'restore',
        manual,
        startedAt: '2026-01-01T00:30:05.123456Z',
        passCount: 2,
      })
      pushState(state)
      await nextTick()
      const card = wrapper.get(
        `[data-id="hilos-maintenance-state-${manual ? 'manual' : 'operation'}"]`,
      )
      const timestamp = card.get('[data-id="hilos-maintenance-started-at"]')
      expect(timestamp.text()).toBe('Dec 31, 2025, 4:30:05 PM')
      expect(card.get('[data-id="hilos-maintenance-pass-count"]').text()).toBe(
        '2',
      )
      expect(card.text()).toContain('verifying')
      if (manual) expect(card.text()).toContain('2 minutes ago')

      setZone('Europe/Kyiv')
      await nextTick()
      expect(timestamp.text()).toBe('Jan 1, 2026, 2:30:05 AM')
      expect(card.get('[data-id="hilos-maintenance-pass-count"]').text()).toBe(
        '2',
      )
      expect(card.text()).toContain('verifying')
      expect(state.startedAt).toBe('2026-01-01T00:30:05.123456Z')
      if (manual) {
        expect(card.text()).toContain('2 minutes ago')
        await vi.advanceTimersByTimeAsync(29_999)
        expect(card.text()).toContain('2 minutes ago')
        await vi.advanceTimersByTimeAsync(1)
        expect(card.text()).toContain('3 minutes ago')
        expect(timestamp.text()).toBe('Jan 1, 2026, 2:30:05 AM')
      } else {
        expect(card.text()).not.toContain('minutes ago')
        expect(card.find('button').exists()).toBe(false)
      }
    },
  )

  it.each([true, false])(
    'keeps the absent marker for missing or malformed startedAt (manual=%s)',
    async (manual) => {
      readerTimeZone()
      const { context, pushState } = seededContext()
      const wrapper = await mountPage(context)
      for (const startedAt of [
        null,
        'malformed',
        '2026-02-30T00:00:00.000000Z',
      ]) {
        pushState(
          maintenanceState({
            phase: 'verifying',
            operation: manual ? 'manual_maintenance' : 'restore',
            manual,
            startedAt,
          }),
        )
        await nextTick()
        expect(
          wrapper.get('[data-id="hilos-maintenance-started-at"]').text(),
        ).toBe('—')
      }
    },
  )
})

describe('HilosMaintenancePage', () => {
  it('offers enable only after the open frame arrives', async () => {
    const { context, pushState } = seededContext()
    const wrapper = await mountPage(context)
    expect(
      wrapper.find('[data-id="hilos-maintenance-state-open"]').exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-maintenance-enable"]').exists()).toBe(
      false,
    )
    pushState(maintenanceState())
    await nextTick()
    const stateBlock = wrapper.get('[data-id="hilos-maintenance-state"]')
    expect(stateBlock.text()).toContain(HILOS_MAINTENANCE_STATE_COPY.openTitle)
    expect(stateBlock.text()).toContain(
      HILOS_MAINTENANCE_STATE_COPY.openDescription,
    )
    expect(stateBlock.get('[data-id="hilos-maintenance-enable"]').text()).toBe(
      HILOS_MAINTENANCE_ENABLE_COPY.enableTitle,
    )
  })

  it('offers disable only in the manual card, for either entry source', async () => {
    const { context, pushState } = seededContext()
    const wrapper = await mountPage(context)
    const button = () => wrapper.find('[data-id="hilos-maintenance-disable"]')
    expect(button().exists()).toBe(false)
    for (const state of [
      maintenanceState(),
      maintenanceState({ phase: 'verifying', operation: 'restore' }),
    ]) {
      pushState(state)
      await settled()
      expect(button().exists()).toBe(false)
    }
    for (const enteredFromBrowser of [true, false]) {
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'manual_maintenance',
          manual: true,
          enteredFromBrowser,
        }),
      )
      await settled()
      expect(button().exists()).toBe(true)
    }
  })

  it('offers pass only in the manual card, for either entry source', async () => {
    const { context, pushState } = seededContext()
    const wrapper = await mountPage(context)
    const button = () => wrapper.find('[data-id="hilos-maintenance-pass"]')
    expect(button().exists()).toBe(false)
    for (const state of [
      maintenanceState(),
      maintenanceState({ phase: 'verifying', operation: 'restore' }),
    ]) {
      pushState(state)
      await settled()
      expect(button().exists()).toBe(false)
    }
    for (const enteredFromBrowser of [true, false]) {
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'manual_maintenance',
          manual: true,
          enteredFromBrowser,
        }),
      )
      await settled()
      expect(button().exists()).toBe(true)
    }
  })

  it('renders the manual card and redraws its pass count on a new frame', async () => {
    const { context, pushState } = seededContext()
    const wrapper = await mountPage(context)
    const manual = maintenanceState({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
      startedAt: new Date(Date.now() - 150_000).toISOString(),
      enteredFromBrowser: false,
      circleNamed: 3,
      circleAdmitted: 2,
    })
    pushState(manual)
    await nextTick()
    const card = wrapper.get('[data-id="hilos-maintenance-state-manual"]')
    expect(card.text()).toContain(HILOS_MAINTENANCE_STATE_COPY.manualTitle)
    expect(card.text()).toContain(
      HILOS_MAINTENANCE_STATE_COPY.turnedOnFromConsole,
    )
    expect(card.text()).toContain('3 named · 2 let in')
    expect(card.text()).toContain('2 minutes ago')
    expect(card.get('[data-id="hilos-maintenance-disable"]').text()).toBe(
      HILOS_MAINTENANCE_DISABLE_COPY.disableTitle,
    )
    pushState({ ...manual, passCount: 1 })
    await nextTick()
    expect(wrapper.get('[data-id="hilos-maintenance-pass-count"]').text()).toBe(
      '1',
    )
  })

  it('renders only the planned operation lines in the frozen card', async () => {
    readerTimeZone()
    const { context, pushState } = seededContext()
    const wrapper = await mountPage(context)
    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'restore',
        startedAt: '2026-10-10T10:00:00.000000Z',
        passCount: 2,
      }),
    )
    await nextTick()
    const card = wrapper.get('[data-id="hilos-maintenance-state-operation"]')
    expect(card.text()).toContain(HILOS_MAINTENANCE_STATE_COPY.operationTitle)
    expect(card.text()).toContain(
      HILOS_MAINTENANCE_STATE_COPY.verifyingPhaseNote,
    )
    expect(card.text()).toContain('restore')
    expect(card.text()).toContain('Oct 10, 2026, 10:00:00 AM')
    expect(card.text()).not.toContain('Initiator')
    expect(card.find('button').exists()).toBe(false)
  })
})

function enableModal(): HTMLElement | null {
  return document.querySelector('[data-id="hilos-maintenance-enable-modal"]')
}

/** A button of the enable confirmation, which must already be open. */
function enableButton(action: 'confirm' | 'cancel'): HTMLButtonElement {
  return document.querySelector(
    `[data-id="hilos-maintenance-enable-${action}"]`,
  ) as HTMLButtonElement
}

/** Mount the real page and open its enable confirmation from a server open frame. */
async function openEnable() {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext(actions)
  const wrapper = await mountPage(context)
  pushState(maintenanceState())
  await nextTick()
  const opener = wrapper.get<HTMLButtonElement>(
    '[data-id="hilos-maintenance-enable"]',
  )
  opener.element.focus()
  opener.element.click()
  await settled()

  return { wrapper, pushState, dispatched, opener }
}

describe('HilosMaintenancePage enable confirmation', () => {
  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'explains entry and allows %s before dispatch without sending anything',
    async (method) => {
      const { dispatched, opener } = await openEnable()
      const dialog = enableModal()?.closest<HTMLElement>(
        '[role="dialog"]',
      ) as HTMLElement
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_ENABLE_COPY.enableTitle,
      )
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(document.activeElement).toBe(dialog)
      expect(dialog.querySelector('input, textarea')).toBeNull()
      for (const text of [
        HILOS_MAINTENANCE_ENABLE_COPY.lead,
        HILOS_MAINTENANCE_ENABLE_COPY.outsideNote,
        HILOS_MAINTENANCE_ENABLE_COPY.insideNote,
        HILOS_MAINTENANCE_ENABLE_COPY.agentsNote,
        HILOS_MAINTENANCE_ENABLE_COPY.footer,
      ])
        expect(dialog.textContent).toContain(text)
      expect(
        dialog.querySelector('[data-id="hilos-action-error-idle"]'),
      ).not.toBeNull()

      if (method === 'cancel') enableButton('cancel').click()
      if (method === 'close')
        dialog
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
          ?.click()
      if (method === 'escape')
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (method === 'backdrop') dialog.click()
      await settled()

      expect(enableModal()).toBeNull()
      expect(dispatched).toHaveLength(0)
      expect(document.activeElement).toBe(opener.element)
    },
  )

  it('blocks repeat and every close while busy, survives manual push and closes on its one success', async () => {
    const { wrapper, pushState, dispatched } = await openEnable()
    const dialog = enableModal()?.closest<HTMLElement>(
      '[role="dialog"]',
    ) as HTMLElement
    enableButton('confirm').click()
    enableButton('confirm').click()
    await settled()

    expect(dispatched).toMatchObject([
      { action: 'maintenance_enable', payload: {} },
    ])
    expect(enableButton('confirm').getAttribute('aria-disabled')).toBe('true')
    expect(enableButton('cancel').disabled).toBe(true)
    enableButton('cancel').click()
    dialog.querySelector<HTMLButtonElement>('[data-id="modal-close"]')?.click()
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    dialog.click()
    await settled()
    expect(enableModal()).not.toBeNull()
    dispatched[0]?.loading.set(true)
    await settled()
    expect(enableButton('confirm').getAttribute('aria-busy')).toBe('true')

    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'manual_maintenance',
        manual: true,
      }),
    )
    await settled()
    expect(wrapper.find('[data-id="hilos-maintenance-enable"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-maintenance-state-manual"]').exists(),
    ).toBe(true)
    expect(enableModal()?.closest('[role="dialog"]')).toBe(dialog)
    expect(enableButton('cancel').disabled).toBe(true)
    expect(hilosToasts.toasts.get()).toHaveLength(0)

    const message = 'Maintenance is on — the system is closed to visitors'
    dispatched[0]?.settle({ message })
    await settled()
    expect(enableModal()).toBeNull()
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message, severity: 'success', repeats: 1 },
    ])
    expect(dispatched).toHaveLength(1)
  })

  it.each([
    maintenanceState({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
    }),
    maintenanceState({ phase: 'active', operation: 'restore' }),
  ])(
    'blocks an unsent confirmation when the node is no longer open: %j',
    async (state) => {
      const { pushState, dispatched } = await openEnable()
      pushState(state)
      await settled()
      expect(enableButton('confirm').disabled).toBe(true)
      enableButton('confirm').click()
      expect(dispatched).toHaveLength(0)
      expect(enableButton('cancel').disabled).toBe(false)
      enableButton('cancel').click()
      await settled()
      expect(enableModal()).toBeNull()
    },
  )

  it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
    'keeps %s readable in the modal, releases Cancel and never retries',
    async (outcome) => {
      const { dispatched, pushState } = await openEnable()
      enableButton('confirm').click()
      await settled()
      // A changed state never answers the action, even when its eventual answer is a refusal.
      pushState(
        maintenanceState({
          phase: 'verifying',
          operation: 'manual_maintenance',
          manual: true,
        }),
      )
      const reason =
        outcome === 'fail'
          ? 'Another request is awaiting confirmation.'
          : 'No reply received.'
      dispatched[0]?.refuse(
        new ActionError('maintenance_enable', outcome, reason),
      )
      await settled()

      const message =
        outcome === 'fail'
          ? reason
          : HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote
      expect(
        enableModal()?.querySelector('[data-id="hilos-action-error"]')
          ?.textContent,
      ).toContain(message)
      expect(
        enableModal()
          ?.querySelector('[data-id="hilos-action-error-slot"]')
          ?.getAttribute('aria-live'),
      ).toBe('assertive')
      expect(enableButton('cancel').disabled).toBe(false)
      expect(enableButton('confirm').disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message, severity: 'error' },
      ])
      expect(dispatched).toHaveLength(1)
      enableButton('cancel').click()
      await settled()
      expect(enableModal()).toBeNull()
    },
  )

  it('shows the entire backend unconfirmed reason and admin details through the standard refusal plate', async () => {
    const { dispatched } = await openEnable()
    enableButton('confirm').click()
    await settled()
    const reason = `${HILOS_MAINTENANCE_ENABLE_COPY.unconfirmedNote} The agent did not observe ready.`
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_enable',
        'fail',
        reason,
        'MaintenanceFailure',
        'Original detail',
      ),
    )
    await settled()
    enableModal()
      ?.querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled()
    expect(document.body.textContent).toContain(reason)
    expect(document.body.textContent).toContain('MaintenanceFailure')
    expect(document.body.textContent).toContain('Original detail')
    expect(
      [...document.querySelectorAll('[role="dialog"]')].map((dialog) =>
        dialog.getAttribute('aria-label'),
      ),
    ).toContain(HILOS_MAINTENANCE_ENABLE_COPY.refusalTitle)
    expect(enableButton('cancel').disabled).toBe(false)
  })
})

/** The disable confirmation's body; its parent HilosModal teleports to the document. */
function disableModal(): HTMLElement | null {
  return document.querySelector('[data-id="hilos-maintenance-disable-modal"]')
}

/** A button of the disable confirmation, which must already be open. */
function disableButton(action: 'confirm' | 'cancel'): HTMLButtonElement {
  return document.querySelector(
    `[data-id="hilos-maintenance-disable-${action}"]`,
  ) as HTMLButtonElement
}

/** Mount the real page and open its disable confirmation from a server manual frame. */
async function openDisable() {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext(actions)
  const wrapper = await mountPage(context)
  pushState(
    maintenanceState({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
    }),
  )
  await nextTick()
  const opener = wrapper.get<HTMLButtonElement>(
    '[data-id="hilos-maintenance-disable"]',
  )
  opener.element.focus()
  opener.element.click()
  await settled()

  return { wrapper, pushState, dispatched, opener }
}

describe('HilosMaintenancePage disable confirmation', () => {
  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'explains exit and allows %s before dispatch without sending anything',
    async (method) => {
      const { dispatched, opener } = await openDisable()
      const dialog = disableModal()?.closest<HTMLElement>(
        '[role="dialog"]',
      ) as HTMLElement
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_DISABLE_COPY.disableTitle,
      )
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(document.activeElement).toBe(dialog)
      expect(dialog.querySelector('input, textarea')).toBeNull()
      for (const text of [
        HILOS_MAINTENANCE_DISABLE_COPY.lead,
        HILOS_MAINTENANCE_DISABLE_COPY.footer,
      ])
        expect(dialog.textContent).toContain(text)
      expect(
        dialog.querySelector('[data-id="hilos-action-error-idle"]'),
      ).not.toBeNull()

      if (method === 'cancel') disableButton('cancel').click()
      if (method === 'close')
        dialog
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
          ?.click()
      if (method === 'escape')
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (method === 'backdrop') dialog.click()
      await settled()

      expect(disableModal()).toBeNull()
      expect(dispatched).toHaveLength(0)
      expect(document.activeElement).toBe(opener.element)
    },
  )

  it('blocks repeat and every close while busy, survives inactive push and closes on its one success', async () => {
    const { wrapper, pushState, dispatched } = await openDisable()
    const dialog = disableModal()?.closest<HTMLElement>(
      '[role="dialog"]',
    ) as HTMLElement
    disableButton('confirm').click()
    disableButton('confirm').click()
    await settled()

    expect(dispatched).toMatchObject([
      { action: 'maintenance_disable', payload: {} },
    ])
    expect(disableButton('confirm').getAttribute('aria-disabled')).toBe('true')
    expect(disableButton('cancel').disabled).toBe(true)
    disableButton('cancel').click()
    dialog.querySelector<HTMLButtonElement>('[data-id="modal-close"]')?.click()
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    dialog.click()
    await settled()
    expect(disableModal()).not.toBeNull()
    dispatched[0]?.loading.set(true)
    await settled()
    expect(disableButton('confirm').getAttribute('aria-busy')).toBe('true')

    pushState(maintenanceState())
    await settled()
    expect(wrapper.find('[data-id="hilos-maintenance-disable"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-maintenance-state-open"]').exists(),
    ).toBe(true)
    expect(disableModal()?.closest('[role="dialog"]')).toBe(dialog)
    expect(disableButton('cancel').disabled).toBe(true)
    expect(hilosToasts.toasts.get()).toHaveLength(0)

    const message = 'Maintenance is off — pages are reloading'
    dispatched[0]?.settle({ message })
    await settled()
    expect(disableModal()).toBeNull()
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message, severity: 'success', repeats: 1 },
    ])
    expect(dispatched).toHaveLength(1)
  })

  it.each([
    maintenanceState(),
    maintenanceState({ phase: 'active', operation: 'restore' }),
  ])(
    'blocks an unsent confirmation when the window is no longer manual: %j',
    async (state) => {
      const { pushState, dispatched } = await openDisable()
      pushState(state)
      await settled()
      expect(disableButton('confirm').disabled).toBe(true)
      disableButton('confirm').click()
      expect(dispatched).toHaveLength(0)
      expect(disableButton('cancel').disabled).toBe(false)
      disableButton('cancel').click()
      await settled()
      expect(disableModal()).toBeNull()
    },
  )

  it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
    'keeps %s readable in the modal, releases Cancel and never retries',
    async (outcome) => {
      const { dispatched, pushState } = await openDisable()
      disableButton('confirm').click()
      await settled()
      // A changed state never answers the action, even when its eventual answer is a refusal.
      pushState(maintenanceState())
      const reason =
        outcome === 'fail'
          ? 'Another request is awaiting confirmation.'
          : 'No reply received.'
      dispatched[0]?.refuse(
        new ActionError('maintenance_disable', outcome, reason),
      )
      await settled()

      const message =
        outcome === 'fail'
          ? reason
          : HILOS_MAINTENANCE_DISABLE_COPY.unconfirmedNote
      expect(
        disableModal()?.querySelector('[data-id="hilos-action-error"]')
          ?.textContent,
      ).toContain(message)
      expect(
        disableModal()
          ?.querySelector('[data-id="hilos-action-error-slot"]')
          ?.getAttribute('aria-live'),
      ).toBe('assertive')
      expect(disableButton('cancel').disabled).toBe(false)
      expect(disableButton('confirm').disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message, severity: 'error' },
      ])
      expect(dispatched).toHaveLength(1)
      disableButton('cancel').click()
      await settled()
      expect(disableModal()).toBeNull()
    },
  )

  it('shows the entire backend unconfirmed reason and admin details through the standard refusal plate', async () => {
    const { dispatched } = await openDisable()
    disableButton('confirm').click()
    await settled()
    const reason = `${HILOS_MAINTENANCE_DISABLE_COPY.unconfirmedNote} The agent did not observe inactive.`
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_disable',
        'fail',
        reason,
        'MaintenanceFailure',
        'Original detail',
      ),
    )
    await settled()
    disableModal()
      ?.querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled()
    expect(document.body.textContent).toContain(reason)
    expect(document.body.textContent).toContain('MaintenanceFailure')
    expect(document.body.textContent).toContain('Original detail')
    expect(
      [...document.querySelectorAll('[role="dialog"]')].map((dialog) =>
        dialog.getAttribute('aria-label'),
      ),
    ).toContain(HILOS_MAINTENANCE_DISABLE_COPY.refusalTitle)
    expect(disableButton('cancel').disabled).toBe(false)
  })
})

/** The pass confirmation's body; its parent HilosModal teleports to the document. */
function passModal(): HTMLElement | null {
  return document.querySelector('[data-id="hilos-maintenance-pass-modal"]')
}

/** A button of the pass confirmation, which must already be open. */
function passButton(action: 'confirm' | 'cancel'): HTMLButtonElement {
  return document.querySelector(
    `[data-id="hilos-maintenance-pass-${action}"]`,
  ) as HTMLButtonElement
}

/** Mount the real page and open its pass confirmation from a server manual frame. */
async function openPass() {
  const { actions, dispatched } = makeActions()
  const { context, pushState } = seededContext(actions)
  const wrapper = await mountPage(context)
  pushState(
    maintenanceState({
      phase: 'verifying',
      operation: 'manual_maintenance',
      manual: true,
    }),
  )
  await nextTick()
  const opener = wrapper.get<HTMLButtonElement>(
    '[data-id="hilos-maintenance-pass"]',
  )
  opener.element.focus()
  opener.element.click()
  await settled()

  return { wrapper, pushState, dispatched, opener }
}

describe('HilosMaintenancePage pass confirmation', () => {
  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'explains issuance and allows %s before dispatch without sending anything',
    async (method) => {
      const { dispatched, opener } = await openPass()
      const dialog = passModal()?.closest<HTMLElement>(
        '[role="dialog"]',
      ) as HTMLElement
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_PASS_COPY.mintTitle,
      )
      expect(dialog.getAttribute('aria-modal')).toBe('true')
      expect(document.activeElement).toBe(dialog)
      expect(dialog.querySelector('input, textarea')).toBeNull()
      for (const text of [
        HILOS_MAINTENANCE_PASS_COPY.lead,
        HILOS_MAINTENANCE_PASS_COPY.reuseNote,
      ])
        expect(dialog.textContent).toContain(text)
      expect(
        dialog.querySelector('[data-id="hilos-action-error-idle"]'),
      ).not.toBeNull()

      if (method === 'cancel') passButton('cancel').click()
      if (method === 'close')
        dialog
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
          ?.click()
      if (method === 'escape')
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (method === 'backdrop') dialog.click()
      await settled()

      expect(passModal()).toBeNull()
      expect(dispatched).toHaveLength(0)
      expect(document.activeElement).toBe(opener.element)
    },
  )

  it('blocks repeat and every close while busy, survives inactive push and reveals a result only on its one success', async () => {
    const { wrapper, pushState, dispatched } = await openPass()
    const dialog = passModal()?.closest<HTMLElement>(
      '[role="dialog"]',
    ) as HTMLElement
    passButton('confirm').click()
    passButton('confirm').click()
    await settled()

    expect(dispatched).toMatchObject([
      { action: 'maintenance_pass', payload: {} },
    ])
    expect(passButton('confirm').getAttribute('aria-disabled')).toBe('true')
    expect(passButton('cancel').disabled).toBe(true)
    passButton('cancel').click()
    dialog.querySelector<HTMLButtonElement>('[data-id="modal-close"]')?.click()
    dialog.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    dialog.click()
    await settled()
    expect(passModal()).not.toBeNull()
    dispatched[0]?.loading.set(true)
    await settled()
    expect(passButton('confirm').getAttribute('aria-busy')).toBe('true')

    pushState(maintenanceState())
    await settled()
    expect(wrapper.find('[data-id="hilos-maintenance-pass"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-maintenance-state-open"]').exists(),
    ).toBe(true)
    expect(passModal()?.closest('[role="dialog"]')).toBe(dialog)
    expect(passButton('cancel').disabled).toBe(true)
    expect(hilosToasts.toasts.get()).toHaveLength(0)

    const message = 'Code issued'
    dispatched[0]?.settle({ message, reply: { pass: 'opaque-secret' } })
    await settled()
    expect(passModal()?.textContent).toContain('opaque-secret')
    expect(
      document.querySelector('[data-id="hilos-maintenance-pass-result"]'),
    ).not.toBeNull()
    expect(hilosToasts.toasts.get()).toMatchObject([
      { message, severity: 'success', repeats: 1 },
    ])
    expect(dispatched).toHaveLength(1)
  })

  it.each([
    maintenanceState(),
    maintenanceState({ phase: 'active', operation: 'restore' }),
  ])(
    'blocks an unsent confirmation when the window is no longer manual: %j',
    async (state) => {
      const { pushState, dispatched } = await openPass()
      pushState(state)
      await settled()
      expect(passButton('confirm').disabled).toBe(true)
      passButton('confirm').click()
      expect(dispatched).toHaveLength(0)
      expect(passButton('cancel').disabled).toBe(false)
      passButton('cancel').click()
      await settled()
      expect(passModal()).toBeNull()
    },
  )

  it.each(['fail', 'timeout', 'disconnected', 'invalid-reply'] as const)(
    'keeps %s readable in the modal, releases Cancel and never retries',
    async (outcome) => {
      const { dispatched, pushState } = await openPass()
      passButton('confirm').click()
      await settled()
      // A changed state never answers the action, even when its eventual answer is a refusal.
      pushState(maintenanceState())
      const reason =
        outcome === 'fail'
          ? 'Another request is awaiting confirmation.'
          : 'No reply received.'
      dispatched[0]?.refuse(
        new ActionError('maintenance_pass', outcome, reason),
      )
      await settled()

      const message =
        outcome === 'fail'
          ? reason
          : HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote
      expect(
        passModal()?.querySelector('[data-id="hilos-action-error"]')
          ?.textContent,
      ).toContain(message)
      expect(
        passModal()
          ?.querySelector('[data-id="hilos-action-error-slot"]')
          ?.getAttribute('aria-live'),
      ).toBe('assertive')
      expect(passButton('cancel').disabled).toBe(false)
      expect(passButton('confirm').disabled).toBe(true)
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message, severity: 'error' },
      ])
      expect(dispatched).toHaveLength(1)
      passButton('cancel').click()
      await settled()
      expect(passModal()).toBeNull()
    },
  )

  it('shows the entire backend unconfirmed reason and admin details through the standard refusal plate', async () => {
    const { dispatched } = await openPass()
    passButton('confirm').click()
    await settled()
    const reason = `${HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote} The agent did not observe inactive.`
    dispatched[0]?.refuse(
      new ActionError(
        'maintenance_pass',
        'fail',
        reason,
        'MaintenanceFailure',
        'Original detail',
      ),
    )
    await settled()
    passModal()
      ?.querySelector<HTMLButtonElement>(
        '[data-id="hilos-action-error-details"]',
      )
      ?.click()
    await settled()
    expect(document.body.textContent).toContain(reason)
    expect(document.body.textContent).toContain('MaintenanceFailure')
    expect(document.body.textContent).toContain('Original detail')
    expect(
      [...document.querySelectorAll('[role="dialog"]')].map((dialog) =>
        dialog.getAttribute('aria-label'),
      ),
    ).toContain(HILOS_MAINTENANCE_PASS_COPY.refusalTitle)
    expect(passButton('cancel').disabled).toBe(false)
  })
})

describe('HilosMaintenancePage and the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode, both as the backend stamps them (HIL-1253).
       *
       * @param user The person behind the session, or null for a guest.
       * @param viewMode The node's admin view mode.
       */
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  it('disables enable for a viewer and after a live revoke while confirmation is open', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, pushState } = seededContext(actions)
    const wrapper = await mountPage(context)
    pushState(maintenanceState())
    await settled()
    const button = wrapper.get<HTMLButtonElement>(
      '[data-id="hilos-maintenance-enable"]',
    ).element
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    button.click()
    await settled()
    expect(enableModal()).toBeNull()

    handshake({ id: 7, admin: true }, true)
    await settled()
    expect(button.disabled).toBe(false)
    button.click()
    await settled()
    expect(enableButton('confirm').disabled).toBe(false)
    handshake({ id: 7, admin: false }, true)
    await settled()
    expect(enableButton('confirm').disabled).toBe(true)
    enableButton('confirm').click()
    expect(dispatched).toHaveLength(0)
    expect(enableButton('cancel').disabled).toBe(false)
  })

  it('disables disable for a viewer and after a live revoke while confirmation is open', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, pushState } = seededContext(actions)
    const wrapper = await mountPage(context)
    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'manual_maintenance',
        manual: true,
      }),
    )
    await settled()
    const button = wrapper.get<HTMLButtonElement>(
      '[data-id="hilos-maintenance-disable"]',
    ).element
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    button.click()
    await settled()
    expect(disableModal()).toBeNull()

    handshake({ id: 7, admin: true }, true)
    await settled()
    expect(button.disabled).toBe(false)
    button.click()
    await settled()
    expect(disableButton('confirm').disabled).toBe(false)
    handshake({ id: 7, admin: false }, true)
    await settled()
    expect(disableButton('confirm').disabled).toBe(true)
    disableButton('confirm').click()
    expect(dispatched).toHaveLength(0)
    expect(disableButton('cancel').disabled).toBe(false)
  })

  it('disables code issuance for a viewer and after a live revoke while confirmation is open', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { actions, dispatched } = makeActions()
    const { context, pushState } = seededContext(actions)
    const wrapper = await mountPage(context)
    pushState(
      maintenanceState({
        phase: 'verifying',
        operation: 'manual_maintenance',
        manual: true,
      }),
    )
    await settled()
    const button = wrapper.get<HTMLButtonElement>(
      '[data-id="hilos-maintenance-pass"]',
    ).element
    expect(button.disabled).toBe(true)
    expect(button.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    button.click()
    await settled()
    expect(passModal()).toBeNull()

    handshake({ id: 7, admin: true }, true)
    await settled()
    expect(button.disabled).toBe(false)
    button.click()
    await settled()
    expect(passButton('confirm').disabled).toBe(false)
    handshake({ id: 7, admin: false }, true)
    await settled()
    expect(passButton('confirm').disabled).toBe(true)
    passButton('confirm').click()
    expect(dispatched).toHaveLength(0)
    expect(passButton('cancel').disabled).toBe(false)
  })
})

describe('HilosMaintenancePage code result', () => {
  it.each(['cancel', 'close', 'escape', 'backdrop'] as const)(
    'forgets the secret on %s, returns focus and opens a fresh confirmation',
    async (method) => {
      const { dispatched, opener } = await openPass()
      passButton('confirm').click()
      await settled()
      const secret = '<opaque&code>'
      dispatched[0]?.settle({ message: 'Code issued', reply: { pass: secret } })
      await settled()
      const dialog = passModal()?.closest<HTMLElement>(
        '[role="dialog"]',
      ) as HTMLElement
      const value = dialog.querySelector<HTMLElement>(
        '[data-id="hilos-maintenance-pass-value"]',
      )
      expect(value?.textContent).toBe(secret)
      expect(value?.querySelector('opaque')).toBeNull()
      expect(document.activeElement).toBe(value)
      expect(dialog.getAttribute('aria-label')).toBe(
        HILOS_MAINTENANCE_PASS_COPY.codeTitle,
      )
      expect(passButton('cancel').textContent?.trim()).toBe('Close')
      expect(hilosToasts.toasts.get()).toMatchObject([
        { message: 'Code issued' },
      ])
      expect(JSON.stringify(hilosToasts.toasts.get())).not.toContain(secret)
      expect(
        JSON.stringify({ ...localStorage, ...sessionStorage }),
      ).not.toContain(secret)
      expect(window.location.href).not.toContain(secret)
      if (method === 'cancel') passButton('cancel').click()
      if (method === 'close')
        dialog
          .querySelector<HTMLButtonElement>('[data-id="modal-close"]')
          ?.click()
      if (method === 'escape')
        dialog.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        )
      if (method === 'backdrop') dialog.click()
      await settled()
      expect(passModal()).toBeNull()
      expect(document.body.textContent).not.toContain(secret)
      expect(document.activeElement).toBe(opener.element)
      opener.element.click()
      await settled()
      expect(passModal()?.textContent).not.toContain(secret)
      expect(
        document.querySelector('[data-id="hilos-maintenance-pass-result"]'),
      ).toBeNull()
      expect(passButton('confirm').disabled).toBe(false)
      expect(dispatched).toHaveLength(1)
      passButton('confirm').click()
      await settled()
      expect(dispatched).toHaveLength(2)
      dispatched[1]?.settle({ reply: { pass: 'new-code' } })
      await settled()
      expect(passModal()?.textContent).toContain('new-code')
      expect(passModal()?.textContent).not.toContain(secret)
    },
  )

  it('forgets a shown result on unmount', async () => {
    const { wrapper, dispatched } = await openPass()
    passButton('confirm').click()
    dispatched[0]?.settle({ reply: { pass: 'departed-code' } })
    await settled()
    expect(passModal()?.textContent).toContain('departed-code')
    wrapper.unmount()
    await settled()
    expect(document.body.textContent).not.toContain('departed-code')
    expect(passModal()).toBeNull()
  })

  it('cannot resurrect a result from a promise after unmount', async () => {
    const { wrapper, dispatched } = await openPass()
    passButton('confirm').click()
    await settled()
    wrapper.unmount()
    dispatched[0]?.settle({ reply: { pass: 'late-code' } })
    await settled()
    expect(document.body.textContent).not.toContain('late-code')
    expect(passModal()).toBeNull()
    const reopened = await openPass()
    expect(passModal()?.textContent).not.toContain('late-code')
    expect(reopened.dispatched).toHaveLength(0)
  })

  it('a late success after timeout cannot replace a newly opened confirmation', async () => {
    const { dispatched, opener } = await openPass()
    passButton('confirm').click()
    await settled()
    dispatched[0]?.refuse(
      new ActionError('maintenance_pass', 'timeout', 'Timed out'),
    )
    await settled()
    expect(passModal()?.textContent).toContain(
      HILOS_MAINTENANCE_PASS_COPY.unconfirmedNote,
    )
    passButton('cancel').click()
    await settled()
    opener.element.click()
    await settled()
    dispatched[0]?.settle({ reply: { pass: 'orphan-code' } })
    await settled()
    expect(document.body.textContent).not.toContain('orphan-code')
    expect(
      document.querySelector('[data-id="hilos-maintenance-pass-result"]'),
    ).toBeNull()
    expect(dispatched).toHaveLength(1)
  })
})

describe('Maintenance Overview head', () => {
  it('shares the Maintenance section head and selects only Overview', async () => {
    const { context, tableTraffic } = seededContext()
    const wrapper = await mountPage(context)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('Maintenance')
    const head = wrapper.get('[data-id="hilos-page-head-block"]')
    const icon = head.get('i.bi-tools')
    expect(
      icon.element.parentElement?.classList.contains('rounded-circle'),
    ).toBe(true)
    expect(head.text()).toContain(
      'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.',
    )
    const breadcrumb = wrapper.get('[data-id="hilos-breadcrumb"]')
    expect(breadcrumb.findAll('li').map((item) => item.text())).toEqual([
      'Hilos',
      'Maintenance',
    ])
    expect(
      breadcrumb.findAll('a').map((link) => link.attributes('href')),
    ).toEqual(['/hilos'])
    const tabs = wrapper.get('nav[aria-label="Maintenance pages"]')
    expect(
      tabs.findAll('a').map((link) => [link.text(), link.attributes('href')]),
    ).toEqual([
      ['Overview', '/hilos/maintenance'],
      ['Circle', '/hilos/maintenance/circle'],
    ])
    expect(tabs.findAll('a[aria-current="page"]')).toHaveLength(1)
    expect(tabs.get('a[aria-current="page"]').text()).toBe('Overview')
    expect(tabs.get('ul').classes()).toContain('overflow-x-auto')
    expect(tabs.get('ul').classes()).toContain('flex-nowrap')
    expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-maintenance-circle-table"]').exists(),
    ).toBe(false)
    expect(tableTraffic).toEqual([])
    wrapper.unmount()
    mounted.splice(mounted.indexOf(wrapper), 1)
    expect(tableTraffic).toEqual([])
  })
})
