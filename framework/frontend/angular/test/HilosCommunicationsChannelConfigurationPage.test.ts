// The Angular peer of vue/src/admin/communications/HilosCommunicationsChannelConfigurationPage.test.ts
// and react/test/HilosCommunicationsChannelConfigurationPage.test.tsx (HIL-1050), under the
// same case names: the channel field's edit modal on the shared row-edit helper,
// the ↺ that resets only through a confirm dialog (HIL-1147), and the page in the
// admin view mode (HIL-1261): hidden values stay hidden while the field editor
// and reset retain their existing permissions and merge behavior.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  pageIdentity,
  resolveHilosPath,
} from '@hilos/core'
import type {
  HilosCommunicationsContext,
  HilosConnection,
  HilosPageKey,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosCommunicationsChannelConfigurationPage } from '../src/admin/communications/HilosCommunicationsChannelConfigurationPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const TABLE = 'hilosCommunicationsChannelFields'
const ROW_KEY = 'notifications.channel.sms.from'

function router(scopes: ScopeManager): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
      params: { channelId: 'sms' },
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) =>
      resolveHilosPath(page as HilosPageKey, params),
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

interface FieldSlot {
  channel: string
  field: string
  label: string
  type: string
  value: unknown
  valueSource: string
  defaultValue: unknown
  defaultValueSource: 'env' | 'default'
  secret: boolean
  editable: boolean
}

/** The `from` field of the sms channel, with the given effective value and source. */
function fromField(
  value: unknown,
  valueSource = 'settings',
  defaultValue: unknown = 'Hilos',
  defaultValueSource: 'env' | 'default' = 'default',
): FieldSlot {
  return {
    channel: 'sms',
    field: 'from',
    label: 'From (sender id)',
    type: 'string',
    value,
    valueSource,
    defaultValue,
    defaultValueSource,
    secret: false,
    editable: true,
  }
}

function seededContext(initial: FieldSlot[]): {
  context: HilosCommunicationsContext
  pushUpdate: (next: FieldSlot) => void
  pushRemove: () => void
  pushLeave: (next: FieldSlot) => void
  answer: (outcome: 'success' | 'fail') => void
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
  const pageScope = scopes.openPage(
    HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
  )
  for (const [key, value] of Object.entries({
    pageLabel: 'Channel',
    pageLead: 'A single communication channel and its configuration.',
    pageHeadOwner: {
      page: HilosPages.COMMUNICATIONS_CHANNEL,
      label: 'Channel',
      lead: 'A single communication channel and its configuration.',
      icon: 'bi-envelope-paper',
    },
    pageBreadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.COMMUNICATIONS, label: 'Communications' },
      { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Channel' },
    ],
    pageTabs: [
      { page: HilosPages.COMMUNICATIONS_CHANNEL, label: 'Overview' },
      {
        page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
        label: 'Configuration',
      },
      { page: HilosPages.COMMUNICATIONS_DELIVERIES, label: 'Deliveries' },
    ],
    pageChildren: [],
    channelSummary: {
      channel: 'sms',
      label: 'SMS',
      enabled: true,
      configured: true,
      driver: 'twilio',
      missingFields: 0,
      fieldCount: 5,
      secretFieldCount: 2,
      overriddenFieldCount: 1,
    },
  })) {
    pageScope.data.set(key, value)
  }
  const windowListeners = new Set<(signal: { data: unknown }) => void>()
  const deltaListeners = new Set<(signal: { data: unknown }) => void>()
  const serveWindow = (): void => {
    const data = {
      page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
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
            page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
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
            page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
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
            page: HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION,
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
    answer(outcome: 'success' | 'fail'): void {
      const last = sent[sent.length - 1]
      const event = outcome === 'success' ? 'actionSuccess' : 'actionError'
      for (const listener of replyListeners.get(event) ?? []) {
        listener({
          kind: event,
          action: last?.action,
          requestId: last?.requestId,
          reason: 'The channel refused the reset.',
        })
      }
    },
    sent,
    focus,
  }
}

/** Mount the page on the seeded rows. */
function mountPage(
  context: HilosCommunicationsContext,
): ComponentFixture<HilosCommunicationsChannelConfigurationPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router(context.scopes) }],
  })
  const fixture = TestBed.createComponent(
    HilosCommunicationsChannelConfigurationPage,
  )
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

// The row's controls stand in the document twice — once in the table and once
// in the card the same row becomes on a narrow screen — so the button is looked
// up through the table, which says which of the two is clicked.
function editButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return (fixture.nativeElement as HTMLElement).querySelector(
    'table [data-id="hilos-channel-field-edit-from"]',
  ) as HTMLButtonElement
}

function resetButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return (fixture.nativeElement as HTMLElement).querySelector(
    'table [data-id="hilos-channel-field-reset-from"]',
  ) as HTMLButtonElement
}

/** Mount the page and open the modal on the seeded row. */
function openModal(
  context: HilosCommunicationsContext,
): ComponentFixture<HilosCommunicationsChannelConfigurationPage> {
  const fixture = mountPage(context)
  editButton(fixture).click()
  fixture.detectChanges()

  return fixture
}

function el(
  fixture: ComponentFixture<unknown>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

function valueInput(fixture: ComponentFixture<unknown>): HTMLInputElement {
  return el(fixture, 'hilos-channel-edit-value') as HTMLInputElement
}

function saveButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-channel-edit-save') as HTMLButtonElement
}

function confirmButton(fixture: ComponentFixture<unknown>): HTMLButtonElement {
  return el(fixture, 'hilos-channel-reset-confirm') as HTMLButtonElement
}

function cancelButton(root: HTMLElement): HTMLButtonElement {
  return Array.from(
    root.querySelectorAll<HTMLButtonElement>('[data-id="modal"] button'),
  ).find(
    (button) => button.textContent?.trim() === 'Cancel',
  ) as HTMLButtonElement
}

function typeDraft(fixture: ComponentFixture<unknown>, text: string): void {
  const input = valueInput(fixture)
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

describe('HilosCommunicationsChannelConfigurationPage shared head', () => {
  it('draws the channel identity and current tab above the fields table', () => {
    const { context } = seededContext([fromField('+1000')])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelectorAll('h1')).toHaveLength(1)
    expect(el(fixture, 'hilos-page-head-title')?.textContent?.trim()).toBe(
      'SMS',
    )
    expect(
      el(fixture, 'hilos-breadcrumb')?.querySelector('[aria-current="page"]')
        ?.textContent,
    ).toContain('SMS')
    expect(
      el(
        fixture,
        `hilos-page-tab-${HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION}`,
      )?.getAttribute('aria-current'),
    ).toBe('page')
    expect(
      el(
        fixture,
        `hilos-page-tab-${HilosPages.COMMUNICATIONS_CHANNEL}`,
      )?.getAttribute('href'),
    ).toBe('/hilos/communications/sms')
    expect(el(fixture, 'hilos-viewport-table')).not.toBeNull()
  })
})

describe('HilosCommunicationsChannelConfigurationPage edit modal', () => {
  it('opens on the committed value with save locked and the message line empty', () => {
    const { context } = seededContext([fromField('+1000')])
    const fixture = openModal(context)

    expect(valueInput(fixture).value).toBe('+1000')
    expect(saveButton(fixture).disabled).toBe(true)
    expect(el(fixture, 'hilos-channel-edit-notice')).toBeNull()
    expect(el(fixture, 'hilos-channel-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the live row changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    const fixture = openModal(context)

    pushUpdate(fromField('+2000'))
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('+2000')
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton(fixture).disabled).toBe(true)

    // The person types over the taken value: the note about it goes out.
    typeDraft(fixture, '+3000')
    expect(el(fixture, 'hilos-channel-edit-notice')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(false)
  })

  it('sends nothing on Enter while a conflict stands', () => {
    const { context, pushUpdate, sent } = seededContext([fromField('+1000')])
    const fixture = openModal(context)
    typeDraft(fixture, '+mine')
    pushUpdate(fromField('+theirs'))
    fixture.detectChanges()

    valueInput(fixture).form?.dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    fixture.detectChanges()

    expect(sent).toHaveLength(0)
    expect(el(fixture, 'conflict-badge')).not.toBeNull()
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext([fromField('+1000')])
    const fixture = openModal(context)
    typeDraft(fixture, '+mine')

    pushUpdate(fromField('+theirs'))
    fixture.detectChanges()

    expect(el(fixture, 'conflict-badge')).not.toBeNull()
    expect(el(fixture, 'hilos-channel-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "+theirs"',
    )
    expect(el(fixture, 'conflict-merge')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(true)
    expect(valueInput(fixture).value).toBe('+mine')

    el(fixture, 'conflict-accept-mine')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-channel-edit-notice')).toBeNull()
    expect(saveButton(fixture).disabled).toBe(false)

    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('communications_channel_set')
    expect(sent[0]?.payload).toMatchObject({
      channel: 'sms',
      field: 'from',
      value: '+mine',
    })
  })

  it('holds the edit open and locks Cancel while saving, then keeps a refused draft', async () => {
    const { context, sent, answer, focus } = seededContext([fromField('+1000')])
    const fixture = openModal(context)
    typeDraft(fixture, '+pending')

    saveButton(fixture).click()
    saveButton(fixture).click()
    fixture.detectChanges()

    const close = el(fixture, 'modal-close') as HTMLButtonElement
    const cancel = cancelButton(fixture.nativeElement as HTMLElement)
    expect(sent).toHaveLength(1)
    expect(close.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    el(fixture, 'modal')?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    fixture.detectChanges()
    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'modal-confirm')).toBeNull()

    answer('fail')
    await new Promise((resolve) => setTimeout(resolve, 0))
    fixture.detectChanges()

    expect(el(fixture, 'modal')).not.toBeNull()
    expect(valueInput(fixture).value).toBe('+pending')
    expect(close.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(el(fixture, 'hilos-action-error')?.textContent).toContain(
      'The channel refused the reset.',
    )
    cancel.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'modal-confirm-discard')).not.toBeNull()
    el(fixture, 'modal-confirm-discard')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('Take theirs sets the field to the live value, says so, and locks save', () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    const fixture = openModal(context)
    typeDraft(fixture, '+mine')
    pushUpdate(fromField('+theirs'))
    fixture.detectChanges()

    el(fixture, 'conflict-accept-theirs')?.click()
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('+theirs')
    expect(el(fixture, 'conflict-badge')).toBeNull()
    expect(el(fixture, 'hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton(fixture).disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', () => {
    const { context, pushRemove } = seededContext([fromField('+1000')])
    const fixture = openModal(context)
    typeDraft(fixture, '+mine')
    pushRemove()
    fixture.detectChanges()

    expect(el(fixture, 'hilos-channel-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton(fixture).disabled).toBe(true)
    expect(saveButton(fixture).textContent?.trim()).toBe('Deleted')
    expect(valueInput(fixture).value).toBe('+mine')
    expect(el(fixture, 'modal')).not.toBeNull()
  })

  it('follows a row that left the window under the modal: Updated just now, not Deleted', () => {
    const { context, pushLeave } = seededContext([fromField('+1000')])
    const fixture = openModal(context)
    // The other tab's save moved the row under the order or out of the filter: the screen
    // gates the departure, the dialog holding the row in focus reads the body off the frame.
    pushLeave(fromField('+2000'))
    fixture.detectChanges()
    fixture.detectChanges()

    expect(valueInput(fixture).value).toBe('+2000')
    expect(el(fixture, 'hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton(fixture).textContent?.trim()).toBe('Save')
  })

  it('takes the row into focus on open and lets it go on close', () => {
    const { context, focus } = seededContext([fromField('+1000')])
    const fixture = openModal(context)
    expect(focus).toEqual([ROW_KEY])

    el(fixture, 'modal-close')?.click()
    fixture.detectChanges()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('asks before discarding a changed draft, and closes a pristine one at once', () => {
    const { context } = seededContext([fromField('+1000')])
    const fixture = openModal(context)
    typeDraft(fixture, '+mine')

    el(fixture, 'modal-close')?.click()
    fixture.detectChanges()
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'modal-confirm-discard')).not.toBeNull()

    el(fixture, 'modal-confirm-discard')?.click()
    fixture.detectChanges()
    expect(el(fixture, 'modal')).toBeNull()
  })
})

describe('HilosCommunicationsChannelConfigurationPage reset dialog', () => {
  function openReset(
    context: HilosCommunicationsContext,
  ): ComponentFixture<HilosCommunicationsChannelConfigurationPage> {
    const fixture = mountPage(context)
    resetButton(fixture).click()
    fixture.detectChanges()

    return fixture
  }

  /** Answer the action and let the page take the reply. */
  async function reply(
    fixture: ComponentFixture<unknown>,
    answer: (outcome: 'success' | 'fail') => void,
    outcome: 'success' | 'fail',
  ): Promise<void> {
    answer(outcome)
    await new Promise((resolve) => setTimeout(resolve, 0))
    fixture.detectChanges()
  }

  it('opens on ↺ with the row in focus and sends nothing', () => {
    const { context, sent, focus } = seededContext([fromField('+1000')])
    const fixture = openReset(context)
    const reset = el(fixture, 'hilos-channel-field-reset-from')

    expect(reset?.getAttribute('title')).toBe('Reset to default')
    expect(reset?.getAttribute('aria-label')).toBe('Reset to default')

    expect(el(fixture, 'modal')?.textContent).toContain(
      'Reset · From (sender id)',
    )
    expect(focus).toEqual([ROW_KEY])
    expect(sent).toEqual([])
  })

  it('shows the value now and where it goes back to', () => {
    const { context } = seededContext([fromField('+1000')])
    const fixture = openReset(context)

    expect(el(fixture, 'hilos-channel-reset-now')?.textContent?.trim()).toBe(
      '+1000',
    )
    expect(
      el(fixture, 'hilos-channel-reset-default')?.textContent?.trim(),
    ).toBe('Hilos Default')
  })

  it.each([
    [0, '0'],
    [false, 'Off'],
    [null, '—'],
    ['', '—'],
  ])('keeps the channel fallback value %s typed', (value, expected) => {
    const { context } = seededContext([fromField('+1000', 'settings', value)])
    const fixture = openReset(context)

    expect(
      el(fixture, 'hilos-channel-reset-default')?.textContent?.trim(),
    ).toBe(`${expected} Default`)
  })

  it('shows the env fallback source and follows a live row update', () => {
    const { context, pushUpdate } = seededContext([
      fromField('+1000', 'settings', '+1200', 'env'),
    ])
    const fixture = openReset(context)

    expect(
      el(fixture, 'hilos-channel-reset-default')?.textContent?.trim(),
    ).toBe('+1200 From env')

    pushUpdate(fromField('+1000', 'settings', false, 'default'))
    fixture.detectChanges()

    expect(
      el(fixture, 'hilos-channel-reset-default')?.textContent?.trim(),
    ).toBe('Off Default')
  })

  it('Reset sends the reset and closes on success, letting the row go', async () => {
    const { context, sent, focus, answer } = seededContext([fromField('+1000')])
    const fixture = openReset(context)

    confirmButton(fixture).click()
    fixture.detectChanges()
    expect(sent.map(({ action, payload }) => ({ action, payload }))).toEqual([
      {
        action: 'communications_channel_reset',
        payload: { channel: 'sms', field: 'from' },
      },
    ])

    await reply(fixture, answer, 'success')
    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('Cancel sends nothing and lets the row go', () => {
    const { context, sent, focus } = seededContext([fromField('+1000')])
    const fixture = openReset(context)

    cancelButton(fixture.nativeElement as HTMLElement).click()
    fixture.detectChanges()

    expect(el(fixture, 'modal')).toBeNull()
    expect(sent).toEqual([])
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('keeps the dialog open with the refusal on a failed reset', async () => {
    const { context, answer } = seededContext([fromField('+1000')])
    const fixture = openReset(context)

    confirmButton(fixture).click()
    fixture.detectChanges()
    await reply(fixture, answer, 'fail')

    expect(el(fixture, 'modal')).not.toBeNull()
    expect(el(fixture, 'hilos-action-error')?.textContent).toContain(
      'The channel refused the reset.',
    )
  })

  it('says a reset elsewhere and locks Reset', () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    const fixture = openReset(context)
    expect(el(fixture, 'hilos-channel-reset-gone')).toBeNull()

    pushUpdate(fromField('+env', 'env'))
    fixture.detectChanges()

    expect(el(fixture, 'hilos-channel-reset-gone')?.textContent?.trim()).toBe(
      'Already reset elsewhere.',
    )
    expect(el(fixture, 'hilos-channel-reset-now')?.textContent?.trim()).toBe(
      '+env',
    )
    expect(confirmButton(fixture).disabled).toBe(true)
  })

  it('locks ↺ while the value is not the admin one', () => {
    const { context } = seededContext([fromField('+env', 'env')])
    const fixture = mountPage(context)

    expect(resetButton(fixture).disabled).toBe(true)
  })
})

describe('HilosCommunicationsChannelConfigurationPage in the admin view mode', () => {
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

  it('a viewer opens a field edit, sees the hidden mark instead of an input, and closes via Cancel', () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent, focus } = seededContext([
      { ...fromField('+1000'), value: { _hidden: true } },
    ])
    const fixture = mountPage(context)

    expect(editButton(fixture).disabled).toBe(false)
    editButton(fixture).click()
    fixture.detectChanges()

    expect(valueInput(fixture)).toBeNull()
    expect(el(fixture, 'hilos-hidden')).not.toBeNull()

    expect(saveButton(fixture).disabled).toBe(true)
    saveButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    const cancel = cancelButton(fixture.nativeElement as HTMLElement)
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    fixture.detectChanges()

    expect(el(fixture, 'modal-confirm-discard')).toBeNull()
    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('a viewer opens the reset of an overridden field with a hidden value and has nothing to reset with', () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent, focus } = seededContext([
      { ...fromField('+1000', 'settings'), value: { _hidden: true } },
    ])
    const fixture = mountPage(context)

    expect(resetButton(fixture).disabled).toBe(false)
    resetButton(fixture).click()
    fixture.detectChanges()

    expect(focus).toEqual([ROW_KEY])
    expect(
      el(fixture, 'hilos-channel-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(confirmButton(fixture).disabled).toBe(true)
    expect(confirmButton(fixture).getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    confirmButton(fixture).click()
    fixture.detectChanges()
    expect(sent).toEqual([])

    const cancel = cancelButton(fixture.nativeElement as HTMLElement)
    expect(cancel?.disabled).toBe(false)
    cancel?.click()
    fixture.detectChanges()

    expect(el(fixture, 'modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })
})
