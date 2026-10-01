// The two-step verification setting's edit modal on the shared row-edit helper
// (HIL-1134): it holds the setting row in focus, reloads a pristine edit when
// the value changes elsewhere and says so, shows the conflict chrome without
// Merge on a changed one (the live value in words) and answers Keep mine / Take
// theirs, and locks save as "Deleted" when the row goes.
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_COPY,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  HilosSecondFactorSettingKey,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosRouter,
  HilosTwoFactorContext,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import HilosSecurity2faPage from './HilosSecurity2faPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const SETTINGS_TABLE = 'hilosSecurityTwoFactor'
const REQUIRED = HilosSecondFactorSettingKey.required
const TRUST_DAYS = HilosSecondFactorSettingKey.trustDays

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_2FA,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** A setting row's slot, with the given value. */
function settingSlot(rowKey: string, value: string): Record<string, unknown> {
  return { rowKey, value, defaultValue: rowKey === REQUIRED ? 'none' : '30' }
}

function seededContext(): {
  context: HilosTwoFactorContext
  pushUpdate: (rowKey: string, value: string) => void
  pushRemove: (rowKey: string) => void
  answer: (outcome: 'success' | 'fail', errorCode?: string) => void
  sent: Array<{ action: string; payload: Record<string, unknown> }>
  focus: string[]
} {
  let settings = new Map<string, Record<string, unknown>>([
    [REQUIRED, settingSlot(REQUIRED, 'admins')],
    [TRUST_DAYS, settingSlot(TRUST_DAYS, '30')],
  ])
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_2FA)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (tableKey: string): void => {
    const rows =
      tableKey === SETTINGS_TABLE
        ? [...settings].map(([rowKey, setting]) => ({
            rowKey,
            slots: { setting },
          }))
        : []
    const data = {
      page: HilosPages.SECURITY_2FA,
      tableKey,
      rows,
      totalCount: rows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 10,
    }
    for (const listener of windowListeners) {
      listener({ data })
    }
  }
  const pushDelta = (data: Record<string, unknown>): void => {
    for (const listener of deltaListeners) {
      listener({
        data: {
          page: HilosPages.SECURITY_2FA,
          tableKey: SETTINGS_TABLE,
          ...data,
        },
      })
    }
  }

  const connection = {
    registerTableWindow(tableKey: string): void {
      serveWindow(tableKey)
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(_page: string, tableKey: string): boolean {
      serveWindow(tableKey)

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableRowFocus(
      _page: string,
      _tableKey: string,
      rowKey: string,
    ): boolean {
      focus.push(rowKey)

      return true
    },
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      if (event === 'tableWindow') {
        windowListeners.add(listener)

        return () => windowListeners.delete(listener)
      }
      if (event === 'tableViewportDelta') {
        deltaListeners.add(listener)

        return () => deltaListeners.delete(listener)
      }

      return () => {}
    },
  }
  const sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }> = []
  const replyListeners = new Map<
    string,
    Set<(signal: Record<string, unknown>) => void>
  >()
  const actions = new ActionLifecycle({
    sendAction: (
      action: string,
      payload: Record<string, unknown>,
      requestId?: string,
    ) => {
      sent.push({ action, payload, requestId })

      return true
    },
    on: (
      event: string,
      listener: (signal: Record<string, unknown>) => void,
    ) => {
      const listeners = replyListeners.get(event) ?? new Set()
      listeners.add(listener)
      replyListeners.set(event, listeners)

      return () => listeners.delete(listener)
    },
  } as unknown as ConstructorParameters<typeof ActionLifecycle>[0])

  return {
    context: {
      connection: connection as unknown as HilosTwoFactorContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(rowKey: string, value: string): void {
      const setting = settingSlot(rowKey, value)
      settings = new Map(settings).set(rowKey, setting)
      pushDelta({
        kind: 'row_updated',
        rowKey,
        row: { rowKey, slots: { setting } },
      })
    },
    pushRemove(rowKey: string): void {
      settings = new Map(settings)
      settings.delete(rowKey)
      pushDelta({ kind: 'row_removed', rowKey, reason: 'deleted' })
    },
    answer(outcome: 'success' | 'fail', errorCode?: string): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The setting refused the write.',
          ...(errorCode !== undefined ? { errorCode } : {}),
        })
      }
    },
    sent,
    focus,
  }
}

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  document.body.classList.remove('modal-open')
})

function modalEl(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function valueInput(): HTMLInputElement | HTMLSelectElement {
  return modalEl('hilos-2fa-input') as HTMLInputElement | HTMLSelectElement
}

function saveButton(): HTMLButtonElement {
  return modalEl('hilos-2fa-save') as HTMLButtonElement
}

function notice(): HTMLElement | null {
  return modalEl('hilos-2fa-edit-notice')
}

async function typeDraft(text: string): Promise<void> {
  const input = valueInput()
  input.value = text
  input.dispatchEvent(
    new Event(input instanceof HTMLSelectElement ? 'change' : 'input', {
      bubbles: true,
    }),
  )
  await nextTick()
}

/**
 * Mount the page and open the modal of one setting. The row's controls stand
 * in the document twice — the table and the narrow-screen card — so the button
 * is looked up through the table.
 */
async function openModal(
  context: HilosTwoFactorContext,
  rowKey: string,
): Promise<void> {
  const wrapper = mount(HilosSecurity2faPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)
  await nextTick()
  ;(
    document.querySelector(
      `table [data-id="hilos-2fa-edit-${rowKey}"]`,
    ) as HTMLElement
  ).click()
  await nextTick()
}

/** Let a live frame reach the modal. */
async function settle(): Promise<void> {
  await nextTick()
  await nextTick()
}

describe('HilosSecurity2faPage setting modal', () => {
  it('opens on the live value with save locked, the message line empty, and the row in focus', async () => {
    const { context, focus } = seededContext()
    await openModal(context, TRUST_DAYS)

    expect(valueInput().value).toBe('30')
    expect(saveButton().disabled).toBe(true)
    expect(notice()).toBeNull()
    expect(focus).toEqual([TRUST_DAYS])
  })

  it('sends nothing on Enter while a conflict stands', async () => {
    const { context, pushUpdate, sent } = seededContext()
    await openModal(context, REQUIRED)
    await typeDraft('everyone')
    pushUpdate(REQUIRED, 'none')
    await settle()

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('conflict-badge')).not.toBeNull()
  })

  it('reloads a pristine edit when the value changes elsewhere and says so', async () => {
    const { context, pushUpdate } = seededContext()
    await openModal(context, TRUST_DAYS)

    pushUpdate(TRUST_DAYS, '14')
    await settle()

    expect(valueInput().value).toBe('14')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('surfaces a conflict in words, without Merge, and Keep mine sends mine', async () => {
    const { context, pushUpdate, sent } = seededContext()
    await openModal(context, REQUIRED)
    await typeDraft('everyone')
    expect(saveButton().disabled).toBe(false)

    pushUpdate(REQUIRED, 'none')
    await settle()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(notice()?.textContent).toContain('Changed elsewhere to "Nobody"')
    expect(modalEl('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_2fa_setting_set')
    expect(sent[0]?.payload).toMatchObject({ key: REQUIRED, value: 'everyone' })
  })

  it('Take theirs puts the live value in, says so, and locks save', async () => {
    const { context, pushUpdate } = seededContext()
    await openModal(context, REQUIRED)
    await typeDraft('everyone')
    pushUpdate(REQUIRED, 'none')
    await settle()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()

    expect(valueInput().value).toBe('none')
    expect(notice()?.textContent).toContain('Updated just now')
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes, and lets the row go on close', async () => {
    const { context, pushRemove, focus } = seededContext()
    await openModal(context, REQUIRED)
    await typeDraft('everyone')
    pushRemove(REQUIRED)
    await settle()

    expect(notice()?.textContent).toContain('Deleted elsewhere')
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('everyone')

    modalEl('modal-close')?.click()
    await nextTick()
    expect(focus).toEqual([REQUIRED, ''])
  })

  it('asks before discarding a changed draft, and closes a pristine one on Save without a request', async () => {
    const { context, sent } = seededContext()
    await openModal(context, TRUST_DAYS)
    await typeDraft('7')

    modalEl('modal-close')?.click()
    await nextTick()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()
    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
    ;(
      document.querySelector(
        `table [data-id="hilos-2fa-edit-${TRUST_DAYS}"]`,
      ) as HTMLElement
    ).click()
    await nextTick()
    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    expect(sent).toHaveLength(0)
    expect(modalEl('modal')).toBeNull()
  })
})

describe('HilosSecurity2faPage', () => {
  it('no longer carries the operations that ask for confirmation (HIL-1204)', async () => {
    const { context } = seededContext()
    const wrapper = mount(HilosSecurity2faPage, {
      props: { context: markRaw(context) },
      attachTo: document.body,
      global: { provide: { [hilosRouterKey as symbol]: router() } },
    })
    mounted.push(wrapper)
    await nextTick()

    expect(wrapper.find('[data-id="hilos-step-up-table"]').exists()).toBe(false)
  })
})

describe('HilosSecurity2faPage in the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  function bindSession(scopes: ScopeManager) {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
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

  it('a viewer opens a setting edit, finds Save disabled with the mode strip reference, and closes via Cancel', async () => {
    const { context, sent } = seededContext()
    bindSession(context.scopes).handshake(null, true)
    await openModal(context, REQUIRED)

    expect(valueInput().disabled).toBe(false)
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    saveButton().click()
    await nextTick()
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    await nextTick()

    expect(modalEl('modal-confirm-discard')).toBeNull()
    expect(modalEl('modal')).toBeNull()
  })

  it('Enter in a setting edit is refused with the view mode phrase', async () => {
    const { context, sent, answer } = seededContext()
    bindSession(context.scopes).handshake(null, true)
    await openModal(context, REQUIRED)

    await typeDraft('everyone')
    expect(saveButton().disabled).toBe(true)

    const form = document.querySelector('[data-id="modal"] form')
    expect(form).not.toBeNull()
    form?.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    )
    await nextTick()

    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('security_2fa_setting_set')
    expect(sent[0]?.payload).toMatchObject({ key: REQUIRED, value: 'everyone' })

    answer('fail', 'view_mode')
    await settle()
    await nextTick()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-action-error')?.textContent).toContain(
      HILOS_VIEW_MODE_COPY.refusal,
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('an admin on a node in the mode has Save active after typing a draft', async () => {
    const { context } = seededContext()
    bindSession(context.scopes).handshake({ id: 1, admin: true }, true)
    await openModal(context, REQUIRED)

    await typeDraft('everyone')
    expect(saveButton().disabled).toBe(false)
    expect(saveButton().getAttribute('aria-describedby')).toBeNull()
  })
})
