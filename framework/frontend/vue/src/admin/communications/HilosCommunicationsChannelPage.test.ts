// The channel field's edit modal on the shared row-edit helper (HIL-1050): a
// live row update reloads a pristine modal and says so, a dirty one shows the
// conflict chrome without Merge and answers Keep mine / Take theirs, a row gone
// under the modal locks save as "Deleted", and the modal asks before discarding
// a changed draft. The merge itself is the core helper's; this covers the view.
// The ↺ resets only through a confirm dialog (HIL-1147).
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_COPY,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosCommunicationsContext,
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'

import HilosCommunicationsChannelPage from './HilosCommunicationsChannelPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const TABLE = 'hilosCommunicationsChannelFields'

/**
 * The identity the channel page answers with: the card to its delivery journal
 * is its one child.
 */
const CHANNEL_IDENTITY: HilosPageIdentity = {
  label: 'Channel',
  lead: 'A single communication channel and its configuration.',
  breadcrumb: [
    { page: HilosPages.COMMUNICATIONS, label: 'Communications' },
    { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Channel' },
  ],
  children: [
    {
      page: HilosPages.COMMUNICATIONS_DELIVERIES,
      label: 'Deliveries',
      lead: 'Delivery log for one channel.',
      icon: null,
    },
  ],
}

/** Resolves the delivery journal from the channel's own route params. */
const resolveDeliveries: HilosRouter['resolvePath'] = (page, params) =>
  page === HilosPages.COMMUNICATIONS_DELIVERIES
    ? `/hilos/communications/${params?.channelId}/deliveries`
    : undefined

function router(
  identity?: HilosPageIdentity,
  resolvePath: HilosRouter['resolvePath'] = () => undefined,
): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.COMMUNICATIONS_CHANNEL,
      params: { channelId: 'sms' },
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

interface FieldSlot {
  channel: string
  field: string
  label: string
  type: string
  value: boolean | number | string | null
  valueSource: string
  secret: boolean
  editable: boolean
}

/** The `from` field of the sms channel, with the given effective value and source. */
function fromField(value: string, valueSource = 'settings'): FieldSlot {
  return {
    channel: 'sms',
    field: 'from',
    label: 'From (sender id)',
    type: 'string',
    value,
    valueSource,
    secret: false,
    editable: true,
  }
}

const ROW_KEY = 'notifications.channel.sms.from'

function seededContext(initial: FieldSlot[]): {
  context: HilosCommunicationsContext
  pushUpdate: (next: FieldSlot) => void
  pushRemove: () => void
  pushLeave: (next: FieldSlot) => void
  answer: (outcome: 'success' | 'fail', errorCode?: string) => void
  sent: Array<{
    action: string
    payload: Record<string, unknown>
    requestId?: string
  }>
  focus: string[]
} {
  let rows = initial.slice()
  const focus: string[] = []
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL)
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.COMMUNICATIONS_CHANNEL,
      tableKey: TABLE,
      rows: rows.map((field) => ({ rowKey: ROW_KEY, slots: { field } })),
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

  const connection = {
    registerTableWindow(): void {
      serveWindow()
    },
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      serveWindow()

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableRowFocus(page: string, tableKey: string, rowKey: string): boolean {
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
      connection:
        connection as unknown as HilosCommunicationsContext['connection'],
      scopes,
      actions,
    },
    pushUpdate(next: FieldSlot): void {
      rows = [next]
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.COMMUNICATIONS_CHANNEL,
            tableKey: TABLE,
            kind: 'row_updated',
            rowKey: ROW_KEY,
            row: { rowKey: ROW_KEY, slots: { field: next } },
          },
        })
      }
    },
    pushRemove(): void {
      rows = []
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.COMMUNICATIONS_CHANNEL,
            tableKey: TABLE,
            kind: 'row_removed',
            rowKey: ROW_KEY,
            reason: 'deleted',
          },
        })
      }
    },
    // The row left this tab's window alive, and the frame that takes it off the screen
    // carries the row for the dialog holding it in focus.
    pushLeave(next: FieldSlot): void {
      rows = []
      for (const listener of deltaListeners) {
        listener({
          data: {
            page: HilosPages.COMMUNICATIONS_CHANNEL,
            tableKey: TABLE,
            kind: 'row_removed',
            rowKey: ROW_KEY,
            reason: 'left_set',
            row: { rowKey: ROW_KEY, slots: { field: next } },
          },
        })
      }
    },
    // Answer the last action sent, the way the server replies to it.
    answer(outcome: 'success' | 'fail', errorCode?: string): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The channel refused the reset.',
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

async function mountPage(
  context: HilosCommunicationsContext,
  navigator: HilosRouter = router(),
) {
  const wrapper = mount(HilosCommunicationsChannelPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: navigator } },
  })
  mounted.push(wrapper)
  await nextTick()

  return wrapper
}

// The row's controls stand in the document twice — once in the table and once
// in the card the same row becomes on a narrow screen — so the button is looked
// up through the table, which says which of the two is clicked.
function editButton(): HTMLElement {
  return document.querySelector(
    'table [data-id="hilos-channel-field-edit-from"]',
  ) as HTMLElement
}

function modalEl(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function valueInput(): HTMLInputElement {
  return modalEl('hilos-channel-edit-value') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return modalEl('hilos-channel-edit-save') as HTMLButtonElement
}

async function typeDraft(text: string): Promise<void> {
  const input = valueInput()
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

/** Open the modal on the seeded row and wait for it to draw. */
async function openModal(context: HilosCommunicationsContext): Promise<void> {
  await mountPage(context)
  editButton().click()
  await nextTick()
}

describe('HilosCommunicationsChannelPage edit modal', () => {
  it('opens on the committed value with save locked and the message line empty', async () => {
    const { context } = seededContext([fromField('+1000')])
    await openModal(context)

    expect(valueInput().value).toBe('+1000')
    expect(saveButton().disabled).toBe(true)
    expect(modalEl('hilos-channel-edit-notice')).toBeNull()
    expect(modalEl('hilos-channel-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the live row changes elsewhere and says so', async () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    await openModal(context)

    pushUpdate(fromField('+2000'))
    await nextTick()
    await nextTick()

    expect(valueInput().value).toBe('+2000')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)

    // The person types over the taken value: the note about it goes out.
    await typeDraft('+3000')
    expect(modalEl('hilos-channel-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', async () => {
    const { context, pushUpdate, sent } = seededContext([fromField('+1000')])
    await openModal(context)
    await typeDraft('+mine')

    pushUpdate(fromField('+theirs'))
    await nextTick()
    await nextTick()

    expect(modalEl('conflict-badge')).not.toBeNull()
    expect(modalEl('hilos-channel-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "+theirs"',
    )
    expect(modalEl('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('+mine')

    modalEl('conflict-accept-mine')?.click()
    await nextTick()
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-channel-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('communications_channel_set')
    expect(sent[0]?.payload).toMatchObject({
      channel: 'sms',
      field: 'from',
      value: '+mine',
    })
  })

  it('Take theirs sets the field to the live value, says so, and locks save', async () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    await openModal(context)
    await typeDraft('+mine')
    pushUpdate(fromField('+theirs'))
    await nextTick()
    await nextTick()

    modalEl('conflict-accept-theirs')?.click()
    await nextTick()

    expect(valueInput().value).toBe('+theirs')
    expect(modalEl('conflict-badge')).toBeNull()
    expect(modalEl('hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', async () => {
    const { context, pushRemove } = seededContext([fromField('+1000')])
    await openModal(context)
    await typeDraft('+mine')
    pushRemove()
    await nextTick()
    await nextTick()

    expect(modalEl('hilos-channel-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(valueInput().value).toBe('+mine')
    expect(modalEl('modal')).not.toBeNull()
  })

  it('follows a row that left the window under the modal: Updated just now, not Deleted', async () => {
    const { context, pushLeave } = seededContext([fromField('+1000')])
    await openModal(context)
    // The other tab's save moved the row under the order or out of the filter: the screen
    // gates the departure, the dialog holding the row in focus reads the body off the frame.
    pushLeave(fromField('+2000'))
    await nextTick()
    await nextTick()

    expect(valueInput().value).toBe('+2000')
    expect(modalEl('hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().textContent?.trim()).toBe('Save')
  })

  it('takes the row into focus on open and lets it go on close', async () => {
    const { context, focus } = seededContext([fromField('+1000')])
    await openModal(context)
    expect(focus).toEqual([ROW_KEY])

    modalEl('modal-close')?.click()
    await nextTick()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('asks before discarding a changed draft, and closes a pristine one at once', async () => {
    const { context } = seededContext([fromField('+1000')])
    await openModal(context)
    await typeDraft('+mine')

    modalEl('modal-close')?.click()
    await nextTick()
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('modal-confirm-discard')).not.toBeNull()

    modalEl('modal-confirm-discard')?.click()
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })
})

describe('HilosCommunicationsChannelPage door', () => {
  it('shows the card to its delivery journal above the fields', async () => {
    // The rest of this file mounts the page with no identity, where the card is
    // absent from sound and broken code alike — which is how the page went
    // without a door to its journal.
    const { context } = seededContext([fromField('+1000')])
    await mountPage(context, router(CHANNEL_IDENTITY, resolveDeliveries))

    const card = document.querySelector(
      `[data-id="hilos-admin-child-${HilosPages.COMMUNICATIONS_DELIVERIES}"]`,
    )
    const test = document.querySelector('[data-id="hilos-channel-test"]')
    expect(card?.getAttribute('href')).toBe(
      '/hilos/communications/sms/deliveries',
    )
    expect(test).not.toBeNull()
    // The way on stands above what the admin came for.
    expect(
      (card as Element).compareDocumentPosition(test as Element) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
  })
})

describe('HilosCommunicationsChannelPage reset dialog', () => {
  function resetButton(): HTMLButtonElement {
    return document.querySelector(
      'table [data-id="hilos-channel-field-reset-from"]',
    ) as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return modalEl('hilos-channel-reset-confirm') as HTMLButtonElement
  }

  async function settle(): Promise<void> {
    await nextTick()
    await nextTick()
    await nextTick()
  }

  async function openReset(context: HilosCommunicationsContext): Promise<void> {
    await mountPage(context)
    resetButton().click()
    await nextTick()
  }

  it('opens on ↺ with the row in focus and sends nothing', async () => {
    const { context, sent, focus } = seededContext([fromField('+1000')])
    await openReset(context)

    expect(modalEl('modal')?.textContent).toContain('Reset · From (sender id)')
    expect(focus).toEqual([ROW_KEY])
    expect(sent).toEqual([])
  })

  it('shows the value now and where it goes back to', async () => {
    const { context } = seededContext([fromField('+1000')])
    await openReset(context)

    expect(modalEl('hilos-channel-reset-now')?.textContent?.trim()).toBe(
      '+1000',
    )
    expect(modalEl('hilos-channel-reset-default')?.textContent?.trim()).toBe(
      'the env value, or the default when env has none',
    )
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext([fromField('+1000')])
    await openReset(context)

    confirmButton().click()
    await nextTick()
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      {
        action: 'communications_channel_reset',
        payload: { channel: 'sms', field: 'from' },
      },
    ])

    answer('success')
    await settle()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('Cancel sends nothing and lets the row go', async () => {
    const { context, sent, focus } = seededContext([fromField('+1000')])
    await openReset(context)

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext([fromField('+1000')])
    await openReset(context)

    confirmButton().click()
    await nextTick()
    answer('fail')
    await settle()

    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('hilos-action-error')?.textContent).toContain(
      'The channel refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', async () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    await openReset(context)
    expect(modalEl('hilos-channel-reset-gone')).toBeNull()

    pushUpdate(fromField('+env', 'env'))
    await nextTick()

    expect(modalEl('hilos-channel-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(modalEl('hilos-channel-reset-now')?.textContent?.trim()).toBe('+env')
    expect(confirmButton().disabled).toBe(true)
  })

  it('locks ↺ while the value is not the admin one', async () => {
    const { context } = seededContext([fromField('+env', 'env')])
    await mountPage(context)

    expect(resetButton().disabled).toBe(true)
  })
})

describe('HilosCommunicationsChannelPage in the admin view mode', () => {
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

  function resetButton(): HTMLButtonElement {
    return document.querySelector(
      'table [data-id="hilos-channel-field-reset-from"]',
    ) as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return modalEl('hilos-channel-reset-confirm') as HTMLButtonElement
  }

  async function settle(): Promise<void> {
    await nextTick()
    await nextTick()
    await nextTick()
  }

  it('a viewer finds the test send standing in view mode', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent } = seededContext([fromField('+1000')])
    await mountPage(context)

    const testButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-channel-test"]',
    )
    expect(testButton?.disabled).toBe(true)
    expect(testButton?.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    testButton?.click()
    await nextTick()
    expect(sent).toEqual([])
  })

  it('a viewer opens a field edit, may type, and has nothing to save it with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent, focus } = seededContext([fromField('+1000')])
    await mountPage(context)

    expect((editButton() as HTMLButtonElement).disabled).toBe(false)
    editButton().click()
    await nextTick()

    expect(valueInput().disabled).toBe(false)
    await typeDraft('+mine')
    expect(valueInput().value).toBe('+mine')

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

    const discard = modalEl('modal-confirm-discard')
    expect(discard).not.toBeNull()
    discard?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('Enter in a field edit is refused in the words of the view mode', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent, answer } = seededContext([fromField('+1000')])
    await openModal(context)
    await typeDraft('+mine')

    const form = document.querySelector('[data-id="modal"] form')
    expect(form).not.toBeNull()
    form?.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    )
    await nextTick()

    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      {
        action: 'communications_channel_set',
        payload: { channel: 'sms', field: 'from', value: '+mine' },
      },
    ])

    answer('fail', 'view_mode')
    await settle()

    expect(modalEl('hilos-action-error')?.textContent).toContain(
      HILOS_VIEW_MODE_COPY.refusal,
    )
    expect(modalEl('modal')).not.toBeNull()
    expect(valueInput().value).toBe('+mine')
    expect(saveButton().disabled).toBe(true)
  })

  it('a viewer opens the reset of an overridden field and has nothing to reset with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent, focus } = seededContext([fromField('+1000')])
    await mountPage(context)

    expect(resetButton().disabled).toBe(false)
    resetButton().click()
    await nextTick()

    expect(focus).toEqual([ROW_KEY])
    expect(confirmButton().disabled).toBe(true)
    expect(confirmButton().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirmButton().click()
    await nextTick()
    expect(sent).toEqual([])

    const cancel = Array.from(
      document.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
    ).find((button) => button.textContent?.trim() === 'Cancel')
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    await nextTick()

    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('an admin on a node in the mode sends a test as today', async () => {
    const { handshake } = bindSession()
    handshake({ id: 1, admin: true }, true)
    const { context, sent } = seededContext([fromField('+1000')])
    await mountPage(context)

    const testButton = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-channel-test"]',
    )
    expect(testButton?.disabled).toBe(false)
    expect(testButton?.getAttribute('aria-describedby')).toBeNull()
    testButton?.click()
    await nextTick()

    expect(sent[0]?.action).toBe('communications_channel_test')
    expect(sent[0]?.payload).toEqual({ channel: 'sms' })
  })
})
