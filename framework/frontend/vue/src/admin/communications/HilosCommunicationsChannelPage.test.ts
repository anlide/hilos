// The channel field's edit modal on the shared row-edit helper (HIL-1050): a
// live row update reloads a pristine modal and says so, a dirty one shows the
// conflict chrome without Merge and answers Keep mine / Take theirs, a row gone
// under the modal locks save as "Deleted", and the modal asks before discarding
// a changed draft. The merge itself is the core helper's; this covers the view.
import { mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
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
  sent: Array<{ action: string; payload: Record<string, unknown> }>
} {
  let rows = initial.slice()
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
    sent,
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
