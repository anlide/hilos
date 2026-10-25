// The channel field's edit modal on the shared row-edit helper (HIL-1050): a
// live row update reloads a pristine modal and says so, a dirty one shows the
// conflict chrome without Merge and answers Keep mine / Take theirs, a row gone
// under the modal locks save as "Deleted", and the modal asks before discarding
// a changed draft. The merge itself is the core helper's; this covers the view.
// The ↺ resets only through a confirm dialog (HIL-1147).
import { flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  pageIdentity,
} from '@hilos/core/base'
import {
  CHANNEL_SUMMARY_DATA,
  type HilosCommunicationsContext,
} from '@hilos/core/admin/communications'
import type {
  Hideable,
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
} from '@hilos/core/base'

import HilosCommunicationsChannelConfigurationPage from './HilosCommunicationsChannelConfigurationPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const TABLE = 'hilosCommunicationsChannelFields'

function router(scopes?: ScopeManager): HilosRouter {
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
    pageIdentity: scopes ? pageIdentity(scopes) : createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
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
  value: boolean | number | string | null
  valueSource: string
  defaultValue: Hideable<boolean | number | string | null>
  defaultValueSource: 'env' | 'default'
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
    defaultValue: 'Hilos',
    defaultValueSource: 'default',
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
  scopes.openPage(HilosPages.COMMUNICATIONS_CHANNEL_CONFIGURATION)
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
  vi.useRealTimers()
})

async function mountPage(
  context: HilosCommunicationsContext,
  navigator: HilosRouter = router(),
) {
  const wrapper = mount(HilosCommunicationsChannelConfigurationPage, {
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

function cancelButton(): HTMLButtonElement {
  return Array.from(
    document.querySelectorAll<HTMLButtonElement>(
      '[data-id="modal"] .modal-footer button',
    ),
  ).find((button) => button.textContent?.trim() === 'Cancel')!
}

// The fieldset the modal stands its body in; a save in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

// Every way a person closes the dialog: the ×, Cancel, Escape and the backdrop.
function tryClosing(): void {
  modalEl('modal-close')?.click()
  cancelButton().click()
  modalEl('modal')?.dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  modalEl('modal')?.click()
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

describe('HilosCommunicationsChannelConfigurationPage edit modal', () => {
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

  it('sends nothing on Enter while a conflict stands', async () => {
    const { context, pushUpdate, sent } = seededContext([fromField('+1000')])
    await openModal(context)
    await typeDraft('+mine')
    pushUpdate(fromField('+theirs'))
    await nextTick()
    await nextTick()

    valueInput().form?.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()

    expect(sent).toHaveLength(0)
    expect(modalEl('conflict-badge')).not.toBeNull()
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

  it('holds the dialog and locks its body while the save is in flight, and frees the same field after a refusal', async () => {
    const { context, sent, answer, focus } = seededContext([fromField('+1000')])
    await openModal(context)
    await typeDraft('+mine')
    const field = valueInput()
    const body = bodyLock()
    const cancel = cancelButton()
    const close = modalEl('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)

    saveButton().click()
    await nextTick()
    expect(sent.map((entry) => entry.action)).toEqual([
      'communications_channel_set',
    ])
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await nextTick()
    expect(modalEl('modal')).not.toBeNull()
    expect(modalEl('modal-confirm')).toBeNull()
    expect(focus).toEqual([ROW_KEY])

    answer('fail')
    await flushPromises()
    expect(modalEl('modal')).not.toBeNull()
    expect(bodyLock()).toBe(body)
    expect(valueInput()).toBe(field)
    expect(field.value).toBe('+mine')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)

    await typeDraft('+mine again')
    saveButton().click()
    await nextTick()
    expect(sent[1]?.payload).toMatchObject({
      channel: 'sms',
      field: 'from',
      value: '+mine again',
    })
    answer('success')
    await flushPromises()
    expect(modalEl('modal')).toBeNull()
    expect(focus).toEqual([ROW_KEY, ''])
  })
})

describe('HilosCommunicationsChannelConfigurationPage surface', () => {
  it('keeps the real shared channel head above the fields and leaves test sending to Overview', async () => {
    const { context } = seededContext([fromField('+1000')])
    const scope = context.scopes.page()!
    scope.data.set('pageLabel', 'Configuration')
    scope.data.set('pageHeadOwner', {
      page: HilosPages.COMMUNICATIONS_CHANNEL,
      label: 'Channel',
      lead: '',
      icon: null,
    })
    scope.data.set(CHANNEL_SUMMARY_DATA, {
      channel: 'sms',
      label: 'SMS',
      enabled: true,
      configured: true,
      driver: 'twilio',
      missingFields: 0,
      fieldCount: 1,
      secretFieldCount: 0,
      overriddenFieldCount: 1,
    })
    const view = await mountPage(context, router(context.scopes))

    expect(view.findAll('h1')).toHaveLength(1)
    expect(view.get('h1').text()).toBe('SMS')
    expect(view.get('[data-id="channel-head-description"]').text()).toBe(
      'Channel sms · driver twilio',
    )
    expect(view.findAll('table')).toHaveLength(1)
    expect(view.text()).toContain('+1000')
    expect(view.find('[data-id="hilos-channel-test"]').exists()).toBe(false)
    expect(view.find('[data-id="channel-head-icon"]').exists()).toBe(true)
  })

  it('shows secrets as env-only status without edit or reset controls', async () => {
    const { context, sent } = seededContext([
      {
        ...fromField('never render this', 'env'),
        secret: true,
        editable: false,
      },
    ])
    const view = await mountPage(context)
    expect(view.text()).toContain('Set in env')
    expect(view.text()).not.toContain('never render this')
    expect(
      view.find('[data-id="hilos-channel-field-edit-from"]').exists(),
    ).toBe(false)
    expect(
      view.find('[data-id="hilos-channel-field-reset-from"]').exists(),
    ).toBe(false)
    expect(sent).toEqual([])
  })

  it('locks the modal immediately while Save keeps its normal appearance for 300 ms', async () => {
    const { context, answer } = seededContext([fromField('+1000')])
    await openModal(context)
    await typeDraft('+mine')
    vi.useFakeTimers()
    saveButton().click()
    await nextTick()

    expect(bodyLock()?.disabled).toBe(true)
    expect(cancelButton().disabled).toBe(true)
    expect(saveButton().disabled).toBe(false)
    expect(saveButton().getAttribute('aria-busy')).toBe('true')
    expect(saveButton().classList.contains('disabled')).toBe(false)
    await vi.advanceTimersByTimeAsync(299)
    expect(saveButton().querySelector('.spinner-border')).toBeNull()
    await vi.advanceTimersByTimeAsync(1)
    expect(saveButton().querySelector('.spinner-border')).not.toBeNull()
    expect(saveButton().classList.contains('disabled')).toBe(true)
    answer('success')
    await vi.advanceTimersByTimeAsync(0)
    await nextTick()
    expect(modalEl('modal')).toBeNull()
  })
})

describe('HilosCommunicationsChannelConfigurationPage reset dialog', () => {
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
    expect(resetButton().title).toBe('Reset to default')
    expect(resetButton().getAttribute('aria-label')).toBe('Reset to default')
  })

  it('shows the value now and where it goes back to', async () => {
    const { context } = seededContext([fromField('+1000')])
    await openReset(context)

    expect(modalEl('hilos-channel-reset-now')?.textContent?.trim()).toBe(
      '+1000',
    )
    expect(
      modalEl('hilos-channel-reset-default')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('Hilos Default')
    expect(
      modalEl('hilos-channel-reset-default')?.classList.contains('text-break'),
    ).toBe(true)
  })

  it.each([
    { value: '+env', source: 'env' as const, expected: '+env From env' },
    { value: null, source: 'default' as const, expected: '— Default' },
    { value: '', source: 'env' as const, expected: '— From env' },
    { value: 0, source: 'default' as const, expected: '0 Default' },
    { value: false, source: 'env' as const, expected: 'Off From env' },
  ])(
    'renders fallback $value from $source without losing falsy values',
    async ({ value, source, expected }) => {
      const { context } = seededContext([
        {
          ...fromField('+1000'),
          defaultValue: value,
          defaultValueSource: source,
        },
      ])
      await openReset(context)

      expect(
        modalEl('hilos-channel-reset-default')
          ?.textContent?.replace(/\s+/g, ' ')
          .trim(),
      ).toBe(expected)
    },
  )

  it('updates the fallback value and source in the open dialog', async () => {
    const { context, pushUpdate, focus, sent } = seededContext([
      fromField('+1000'),
    ])
    await openReset(context)

    pushUpdate({
      ...fromField('+2000'),
      defaultValue: '+env',
      defaultValueSource: 'env',
    })
    await settle()

    expect(modalEl('hilos-channel-reset-now')?.textContent?.trim()).toBe(
      '+2000',
    )
    expect(
      modalEl('hilos-channel-reset-default')
        ?.textContent?.replace(/\s+/g, ' ')
        .trim(),
    ).toBe('+env From env')
    expect(focus).toEqual([ROW_KEY])
    expect(sent).toEqual([])
  })

  it('keeps a hidden fallback hidden in the open dialog', async () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    await openReset(context)

    pushUpdate({ ...fromField('+1000'), defaultValue: { _hidden: true } })
    await settle()

    const fallback = modalEl('hilos-channel-reset-default')
    expect(fallback?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(fallback?.querySelector('.badge.ms-2')?.textContent?.trim()).toBe(
      'Default',
    )
    expect(fallback?.textContent).not.toContain('Hilos')
    expect(fallback?.textContent).not.toContain('[object Object]')
    expect(fallback?.textContent).not.toContain('—')
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

  function resetButton(): HTMLButtonElement {
    return document.querySelector(
      'table [data-id="hilos-channel-field-reset-from"]',
    ) as HTMLButtonElement
  }

  function confirmButton(): HTMLButtonElement {
    return modalEl('hilos-channel-reset-confirm') as HTMLButtonElement
  }

  it('a viewer opens a field edit, sees the hidden mark instead of an input, and closes via Cancel', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent, focus } = seededContext([
      {
        ...fromField('+1000'),
        value: { _hidden: true } as unknown as string,
      },
    ])
    await mountPage(context)

    expect((editButton() as HTMLButtonElement).disabled).toBe(false)
    editButton().click()
    await nextTick()

    expect(modalEl('hilos-channel-edit-value')).toBeNull()
    expect(modalEl('hilos-hidden')).not.toBeNull()

    expect(saveButton().disabled).toBe(true)
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
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('a viewer opens the reset of an overridden field with a hidden value and has nothing to reset with', async () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, sent, focus } = seededContext([
      {
        ...fromField('+1000', 'settings'),
        value: { _hidden: true } as unknown as string,
      },
    ])
    await mountPage(context)

    expect(resetButton().disabled).toBe(false)
    resetButton().click()
    await nextTick()

    expect(focus).toEqual([ROW_KEY])
    expect(
      modalEl('hilos-channel-reset-now')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
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
})
