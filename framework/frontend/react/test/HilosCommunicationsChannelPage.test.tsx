// The React peer of vue/src/admin/communications/HilosCommunicationsChannelPage.test.ts
// (HIL-1050), under the same case names: the channel field's edit modal on the
// shared row-edit helper — reload and "Updated just now", conflict with Keep mine
// / Take theirs and no Merge, "Deleted" under a gone row, the discard question.
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  HilosCommunicationsContext,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosCommunicationsChannelPage } from '../src/admin/communications/HilosCommunicationsChannelPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const TABLE = 'hilosCommunicationsChannelFields'
const ROW_KEY = 'notifications.channel.sms.from'

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

function seededContext(initial: FieldSlot[]): {
  context: HilosCommunicationsContext
  pushUpdate: (next: FieldSlot) => void
  pushRemove: () => void
  pushLeave: (next: FieldSlot) => void
  sent: Array<{ action: string; payload: Record<string, unknown> }>
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
  const sent: Array<{ action: string; payload: Record<string, unknown> }> = []
  const actions = new ActionLifecycle({
    sendAction: (action: string, payload: Record<string, unknown>) => {
      sent.push({ action, payload })

      return true
    },
    on: () => () => {},
  })

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
    sent,
    focus,
  }
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function valueInput(): HTMLInputElement {
  return byId('hilos-channel-edit-value') as HTMLInputElement
}

function saveButton(): HTMLButtonElement {
  return byId('hilos-channel-edit-save') as HTMLButtonElement
}

/** Render the page and open the modal on the seeded row. */
function openModal(context: HilosCommunicationsContext): void {
  const { container } = render(
    <HilosRouterContext.Provider value={router()}>
      <HilosCommunicationsChannelPage context={context} />
    </HilosRouterContext.Provider>,
  )
  fireEvent.click(
    container.querySelector(
      'table [data-id="hilos-channel-field-edit-from"]',
    ) as Element,
  )
}

describe('HilosCommunicationsChannelPage edit modal', () => {
  afterEach(() => {
    cleanup()
    document.body.classList.remove('modal-open')
  })

  it('opens on the committed value with save locked and the message line empty', () => {
    const { context } = seededContext([fromField('+1000')])
    openModal(context)

    expect(valueInput().value).toBe('+1000')
    expect(saveButton().disabled).toBe(true)
    expect(byId('hilos-channel-edit-notice')).toBeNull()
    expect(byId('hilos-channel-edit-notice-idle')).not.toBeNull()
  })

  it('reloads a pristine edit when the live row changes elsewhere and says so', () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    openModal(context)

    act(() => {
      pushUpdate(fromField('+2000'))
    })

    expect(valueInput().value).toBe('+2000')
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)

    // The person types over the taken value: the note about it goes out.
    fireEvent.change(valueInput(), { target: { value: '+3000' } })
    expect(byId('hilos-channel-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)
  })

  it('surfaces a conflict on a dirty edit, hides merge, and Keep mine sends mine', () => {
    const { context, pushUpdate, sent } = seededContext([fromField('+1000')])
    openModal(context)
    fireEvent.change(valueInput(), { target: { value: '+mine' } })

    act(() => {
      pushUpdate(fromField('+theirs'))
    })

    expect(byId('conflict-badge')).not.toBeNull()
    expect(byId('hilos-channel-edit-notice')?.textContent).toContain(
      'Changed elsewhere to "+theirs"',
    )
    expect(byId('conflict-merge')).toBeNull()
    expect(saveButton().disabled).toBe(true)
    expect(valueInput().value).toBe('+mine')

    fireEvent.click(byId('conflict-accept-mine') as Element)
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-channel-edit-notice')).toBeNull()
    expect(saveButton().disabled).toBe(false)

    fireEvent.click(saveButton())
    expect(sent).toHaveLength(1)
    expect(sent[0]?.action).toBe('communications_channel_set')
    expect(sent[0]?.payload).toMatchObject({
      channel: 'sms',
      field: 'from',
      value: '+mine',
    })
  })

  it('Take theirs sets the field to the live value, says so, and locks save', () => {
    const { context, pushUpdate } = seededContext([fromField('+1000')])
    openModal(context)
    fireEvent.change(valueInput(), { target: { value: '+mine' } })
    act(() => {
      pushUpdate(fromField('+theirs'))
    })

    fireEvent.click(byId('conflict-accept-theirs') as Element)

    expect(valueInput().value).toBe('+theirs')
    expect(byId('conflict-badge')).toBeNull()
    expect(byId('hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().disabled).toBe(true)
  })

  it('locks save as Deleted and keeps the draft when the row goes under the modal', () => {
    const { context, pushRemove } = seededContext([fromField('+1000')])
    openModal(context)
    fireEvent.change(valueInput(), { target: { value: '+mine' } })
    act(() => {
      pushRemove()
    })

    expect(byId('hilos-channel-edit-notice')?.textContent).toContain(
      'Deleted elsewhere',
    )
    expect(saveButton().disabled).toBe(true)
    expect(saveButton().textContent?.trim()).toBe('Deleted')
    expect(valueInput().value).toBe('+mine')
    expect(byId('modal')).not.toBeNull()
  })

  it('follows a row that left the window under the modal: Updated just now, not Deleted', () => {
    const { context, pushLeave } = seededContext([fromField('+1000')])
    openModal(context)
    // The other tab's save moved the row under the order or out of the filter: the screen
    // gates the departure, the dialog holding the row in focus reads the body off the frame.
    act(() => {
      pushLeave(fromField('+2000'))
    })

    expect(valueInput().value).toBe('+2000')
    expect(byId('hilos-channel-edit-notice')?.textContent).toContain(
      'Updated just now',
    )
    expect(saveButton().textContent?.trim()).toBe('Save')
  })

  it('takes the row into focus on open and lets it go on close', () => {
    const { context, focus } = seededContext([fromField('+1000')])
    openModal(context)
    expect(focus).toEqual([ROW_KEY])

    fireEvent.click(byId('modal-close') as Element)
    expect(focus).toEqual([ROW_KEY, ''])
  })

  it('asks before discarding a changed draft, and closes a pristine one at once', () => {
    const { context } = seededContext([fromField('+1000')])
    openModal(context)
    fireEvent.change(valueInput(), { target: { value: '+mine' } })

    fireEvent.click(byId('modal-close') as Element)
    // The discard question is the modal's own confirm step: the dialog stays.
    expect(byId('modal')).not.toBeNull()
    expect(byId('modal-confirm-discard')).not.toBeNull()

    fireEvent.click(byId('modal-confirm-discard') as Element)
    expect(byId('modal')).toBeNull()
  })
})

describe('HilosCommunicationsChannelPage door', () => {
  afterEach(cleanup)

  it('shows the card to its delivery journal above the fields', () => {
    // The rest of this file renders the page with no identity, where the card is
    // absent from sound and broken code alike — which is how the page went
    // without a door to its journal.
    const { context } = seededContext([fromField('+1000')])
    const { container } = render(
      <HilosRouterContext.Provider
        value={router(CHANNEL_IDENTITY, resolveDeliveries)}
      >
        <HilosCommunicationsChannelPage context={context} />
      </HilosRouterContext.Provider>,
    )

    const card = container.querySelector(
      `[data-id="hilos-admin-child-${HilosPages.COMMUNICATIONS_DELIVERIES}"]`,
    )
    const test = container.querySelector('[data-id="hilos-channel-test"]')
    if (card === null || test === null) {
      throw new Error(
        'The channel page draws both the card and its test button.',
      )
    }

    expect(card.getAttribute('href')).toBe(
      '/hilos/communications/sms/deliveries',
    )
    // The way on stands above what the admin came for.
    expect(
      card.compareDocumentPosition(test) & Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
  })
})
