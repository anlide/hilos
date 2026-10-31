import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  bindPageScope,
  createAppPageRouter,
  createHilosRouter,
  HilosConnection,
  HilosPages,
  PAGE_SIGNAL_SCHEMAS,
  ScopeManager,
  type WebSocketLike,
} from '@hilos/core/base'
import {
  HILOS_MODERATION_KINDS_TABLE,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosModerationPage } from '@hilos/angular/admin/moderation'
import { HILOS_ROUTER } from '@hilos/angular/base'

const TABLE = HILOS_MODERATION_KINDS_TABLE
const PAGE = HilosPages.MODERATION
const initial: HilosModerationKindRow = {
  kind: 'hilos_profile_name',
  title: 'Profile name',
  owner: 'framework',
  input: 'text',
  model: 'text-model',
  profile: 'hilos.moderation.text',
  ownRuleCount: 2,
  reasons: ['spam', 'threat'],
  defaultRules: ['Allow polite names.'],
}
const photo: HilosModerationKindRow = {
  ...initial,
  kind: 'hilos_profile_photo',
  title: 'Profile photo',
  input: 'image',
  model: { _hidden: true },
  ownRuleCount: 0,
}
let fixture: ComponentFixture<HilosModerationPage> | undefined
const releases: Array<() => void> = []
afterEach(() => {
  fixture?.destroy()
  fixture = undefined
  for (const off of releases.splice(0)) off()
  document.body.innerHTML = ''
})

/** Only the socket is scripted: the protocol, scope and factories are real. */
function backend() {
  const listeners = new Map<string, (event: { data?: unknown }) => void>()
  const sent: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send: (text) => sent.push(JSON.parse(String(text))),
    close: () => {},
    addEventListener: (event, listener) => listeners.set(event, listener),
  }
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
    projectSchemas: PAGE_SIGNAL_SCHEMAS,
  })
  const scopes = new ScopeManager()
  const pages = bindPageScope(connection, scopes)
  const router = createHilosRouter(createAppPageRouter({}), pages, {
    pathname: () => '/hilos/moderation',
    pushState: () => {},
    replaceState: () => {},
    onPopState: () => () => {},
  })
  connection.connect()
  listeners.get('open')?.({})
  receive('handshake', { build: 'test' })
  pages.releaseOnSession()
  router.start()
  releases.push(() => {
    router.stop()
    pages.unsubscribe()
    connection.close()
  })
  function receive(type: string, data: unknown): void {
    listeners.get('message')?.({ data: JSON.stringify({ type, data }) })
  }
  function wire(row: HilosModerationKindRow) {
    return { rowKey: row.kind, slots: { kind: row } }
  }
  function answer(rows = [initial, photo]): void {
    receive('page_response', {
      page: PAGE,
      payload: {
        data: {
          pageLabel: 'Moderation',
          pageHeadOwner: {
            page: PAGE,
            label: 'Moderation',
            lead: 'Checks and rules.',
            icon: 'bi-shield-check',
          },
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: PAGE, label: 'Moderation' },
          ],
          pageTabs: [{ page: PAGE, label: 'Overview' }],
          pageChildren: [],
        },
        windows: {
          [TABLE]: {
            rows: rows.map(wire),
            sort: [],
            limit: 50,
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
          },
        },
      },
    })
  }
  function delta(kind: string, row = initial): void {
    receive('table_viewport_delta', {
      page: PAGE,
      tableKey: TABLE,
      kind,
      rowKey: row.kind,
      ...(kind === 'row_removed' ? {} : { row: wire(row) }),
    })
  }
  return {
    context: { connection, scopes },
    router,
    sent,
    receive,
    answer,
    delta,
  }
}
function mount(bench: ReturnType<typeof backend>): HTMLElement {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: bench.router }],
  })
  fixture = TestBed.createComponent(HilosModerationPage)
  fixture.componentRef.setInput('context', bench.context)
  document.body.append(fixture.nativeElement)
  fixture.detectChanges()
  return fixture.nativeElement as HTMLElement
}
function text(node: Element | null): string {
  return node?.textContent?.replace(/\s+/g, ' ').trim() ?? ''
}

describe('HilosModerationPage', () => {
  it('renders the shared section head, named native table and readable rows with real Open links', () => {
    const h = backend()
    h.answer()
    const view = mount(h)
    expect([...view.querySelectorAll('h1')].map(text)).toEqual(['Moderation'])
    expect(view.querySelector('[aria-label="Kind pages"]')).toBeNull()
    const table = view.querySelector('table')!
    expect(table.getAttribute('aria-labelledby')).toBe(
      view.querySelector('h1')!.id,
    )
    expect([...table.querySelectorAll('thead th')].map(text)).toEqual([
      'Kind',
      'Declared by',
      'Checked by',
      'Rules',
      '',
      'Will move Will leaveRow state and controls',
    ])
    expect(text(table)).toContain('Framework')
    expect(text(table)).toContain('Text model')
    expect(text(table)).toContain('text-model')
    expect(text(table)).toContain('2 rules')
    expect(text(table)).toContain('Vision model')
    expect(text(table)).toContain('Built-in')
    expect(text(table.querySelector('[data-id="hilos-hidden"]'))).toBe('Hidden')
    expect(text(view)).not.toContain('[object Object]')
    const open = table.querySelector<HTMLAnchorElement>(
      '[aria-label="Open Profile name"]',
    )!
    expect(open.getAttribute('href')).toBe(
      '/hilos/moderation/hilos_profile_name',
    )
    open.click()
    expect(h.router.currentRoute.get().params.kind).toBe(initial.kind)
  })

  it('keeps loading and refused windows distinct and recovers on an answer', () => {
    const h = backend()
    const view = mount(h)
    expect(view.querySelector('[data-id="hilos-table-unavailable"]')).toBeNull()
    expect(view.querySelector('tbody')?.getAttribute('aria-busy')).toBe('true')
    h.receive('table_window_refused', {
      page: PAGE,
      tableKey: TABLE,
      errorCode: 'unavailable',
    })
    fixture!.detectChanges()
    expect(
      view
        .querySelector('[data-id="hilos-table-unavailable"]')
        ?.getAttribute('role'),
    ).toBe('status')
    h.answer([])
    fixture!.detectChanges()
    expect(view.querySelector('[data-id="hilos-table-unavailable"]')).toBeNull()
    expect(text(view)).toContain('No moderation kinds declared.')
  })

  it('refreshes values immediately, holds removals until Apply and uses the native viewport request', () => {
    const h = backend()
    h.answer()
    const view = mount(h)
    h.delta('row_updated', { ...initial, model: 'new-model', ownRuleCount: 1 })
    fixture!.detectChanges()
    expect(text(view.querySelector('table'))).toContain('new-model')
    expect(text(view.querySelector('table'))).toContain('1 rule')
    h.delta('row_removed')
    fixture!.detectChanges()
    expect(
      view.querySelector('table [aria-label="Open Profile name"]'),
    ).not.toBeNull()
    const apply = view.querySelector<HTMLButtonElement>(
      '[data-id="hilos-table-apply"]',
    )!
    expect(apply).not.toBeNull()
    apply.click()
    fixture!.detectChanges()
    expect(
      view.querySelector('table [aria-label="Open Profile name"]'),
    ).toBeNull()
    view
      .querySelector<HTMLButtonElement>('[data-id="hilos-table-sort-title"]')!
      .click()
    fixture!.detectChanges()
    expect(h.sent).toContainEqual(
      expect.objectContaining({
        type: 'table_viewport',
        page: PAGE,
        tableKey: TABLE,
        sort: [{ field: 'title', direction: 'asc' }],
      }),
    )
  })

  it('releases the old context and ignores its later replies, then releases the new binding on destroy', () => {
    const old = backend()
    old.answer()
    const view = mount(old)
    const next = backend()
    next.answer([{ ...initial, title: 'New context' }])
    fixture!.componentRef.setInput('context', next.context)
    fixture!.detectChanges()
    expect(old.context.connection.tableWindowDescriptors()).toEqual({})
    expect(text(view.querySelector('table'))).toContain('New context')
    old.delta('row_updated', { ...initial, title: 'Late old row' })
    fixture!.detectChanges()
    expect(text(view)).not.toContain('Late old row')
    fixture!.destroy()
    fixture = undefined
    expect(next.context.connection.tableWindowDescriptors()).toEqual({})
  })
})
