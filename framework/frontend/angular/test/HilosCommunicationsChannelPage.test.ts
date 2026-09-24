// The Angular peer of vue/src/admin/communications/HilosCommunicationsChannelPage.test.ts
// and react/test/HilosCommunicationsChannelPage.test.tsx (HIL-1050), under the
// same case names: the channel field's edit modal on the shared row-edit helper.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
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
import { describe, expect, it } from 'vitest'

import { HilosCommunicationsChannelPage } from '../src/admin/communications/HilosCommunicationsChannelPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

/** Mount the page and open the modal on the seeded row. */
function openModal(
  context: HilosCommunicationsContext,
): ComponentFixture<HilosCommunicationsChannelPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosCommunicationsChannelPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  root
    .querySelector<HTMLElement>(
      'table [data-id="hilos-channel-field-edit-from"]',
    )
    ?.click()
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

function typeDraft(fixture: ComponentFixture<unknown>, text: string): void {
  const input = valueInput(fixture)
  input.value = text
  input.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

describe('HilosCommunicationsChannelPage edit modal', () => {
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

describe('HilosCommunicationsChannelPage door', () => {
  it('shows the card to its delivery journal above the fields', () => {
    // The rest of this file mounts the page with no identity, where the card is
    // absent from sound and broken code alike — which is how the page went
    // without a door to its journal.
    const { context } = seededContext([fromField('+1000')])
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HILOS_ROUTER,
          useValue: router(CHANNEL_IDENTITY, resolveDeliveries),
        },
      ],
    })
    const fixture = TestBed.createComponent(HilosCommunicationsChannelPage)
    fixture.componentRef.setInput('context', context)
    fixture.detectChanges()

    const card = el(
      fixture,
      `hilos-admin-child-${HilosPages.COMMUNICATIONS_DELIVERIES}`,
    )
    const test = el(fixture, 'hilos-channel-test')
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
