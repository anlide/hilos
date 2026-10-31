import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  bindPageScope,
  createAppPageRouter,
  createHilosRouter,
  HilosConnection,
  HilosPages,
  PAGE_SIGNAL_SCHEMAS,
  resolvePageTitle,
  ScopeManager,
  type WebSocketLike,
} from '@hilos/core/base'
import {
  HILOS_MODERATION_KINDS_TABLE,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosModerationKindPage } from '@hilos/angular/admin/moderation'
import { HILOS_ROUTER } from '@hilos/angular/base'

const TABLE = HILOS_MODERATION_KINDS_TABLE
const PAGE = HilosPages.MODERATION_KIND
const initial: HilosModerationKindRow = {
  kind: 'hilos_profile_name',
  title: 'Profile name',
  owner: 'framework',
  input: 'text',
  model: 'text-model',
  profile: 'hilos.moderation.text',
  ownRuleCount: 0,
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
let fixture: ComponentFixture<HilosModerationKindPage> | undefined
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
  const router = createHilosRouter(
    createAppPageRouter({}),
    pages,
    {
      pathname: () => `/hilos/moderation/${initial.kind}`,
      pushState: () => {},
      replaceState: () => {},
      onPopState: () => () => {},
    },
    (page) =>
      resolvePageTitle(
        page,
        {},
        'Test',
        pages.pageIdentity.get()?.label,
        !pages.pageLoading.get(),
        pages.pageIdentity.get()?.pageHeadOwner?.label,
      ),
  )
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
  function answer(row = initial): void {
    receive('page_response', {
      page: PAGE,
      payload: {
        data: {
          pageLabel: 'Kind',
          pageHeadOwner: {
            page: PAGE,
            label: 'Kind',
            lead: 'Checks and rules.',
            icon: 'bi-shield-check',
          },
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: HilosPages.MODERATION, label: 'Moderation' },
            { page: PAGE, label: 'Kind' },
          ],
          pageTabs: [{ page: PAGE, label: 'Overview' }],
          pageChildren: [],
        },
        windows: {
          [TABLE]: {
            rows: [wire(row)],
            sort: [],
            limit: 1,
            totalCount: 1,
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
  fixture = TestBed.createComponent(HilosModerationKindPage)
  fixture.componentRef.setInput('context', bench.context)
  document.body.append(fixture.nativeElement)
  fixture.detectChanges()
  return fixture.nativeElement as HTMLElement
}
function text(node: Element | null): string {
  const copy = node?.cloneNode(true) as Element | undefined
  for (const hidden of copy?.querySelectorAll('[aria-hidden="true"]') ?? []) {
    hidden.remove()
  }
  return copy?.textContent?.replace(/\s+/g, ' ').trim() ?? ''
}

describe('HilosModerationKindPage', () => {
  it('renders the shared live entity head and read-only semantic metadata without fetching on mount', () => {
    const h = backend()
    h.answer()
    const view = mount(h)
    expect([...view.querySelectorAll('h1')].map(text)).toEqual(['Profile name'])
    const crumbs = view.querySelector('[data-id="hilos-breadcrumb"]')!
    expect(
      [...crumbs.querySelectorAll('a')].map((link) => [
        text(link),
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['Hilos', '/hilos'],
      ['Moderation', '/hilos/moderation'],
    ])
    expect(text(crumbs.querySelector('li[aria-current="page"]'))).toBe(
      'Profile name',
    )
    expect(
      view
        .querySelector('[data-id="hilos-moderation-kind-visual"]')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-owner"]')),
    ).toBe('Framework')
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-key"]')),
    ).toBe(initial.kind)
    expect([...view.querySelectorAll('dl dt')].map(text)).toEqual([
      'Checked by',
      'Can refuse for',
      'Rules in force',
    ])
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-model"]')),
    ).toContain('Text model · text-model')
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-profile"]')),
    ).toBe(initial.profile)
    expect(
      [
        ...view.querySelectorAll(
          '[data-id="hilos-moderation-kind-reasons"] span',
        ),
      ].map(text),
    ).toEqual(initial.reasons)
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-rules"]')),
    ).toBe('Built-in until you add a rule of your own')
    expect(
      view.querySelector('table, input, select, button, [role="tablist"]'),
    ).toBeNull()
    expect(view.querySelector('[aria-label="Kind pages"]')).toBeNull()
    expect(h.sent.filter((frame) => frame.type === 'table_viewport')).toEqual(
      [],
    )
    expect(h.router.currentTitle.get()).toBe('Profile name · Test')
    h.delta('row_updated', { ...initial, title: 'Live title', ownRuleCount: 1 })
    fixture!.detectChanges()
    expect(text(view.querySelector('h1'))).toBe('Live title')
    expect(text(crumbs.querySelector('li[aria-current="page"]'))).toBe(
      'Live title',
    )
    expect(h.router.currentTitle.get()).toBe('Live title · Test')
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-rules"]')),
    ).toBe('1 rule of your own')
    fixture!.destroy()
    fixture = undefined
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
    expect(h.context.connection.tableWindowDescriptors()).toEqual({})
  })

  it('shows the hidden model and project-owned policy without revealing a model value', () => {
    const h = backend()
    h.answer({
      ...initial,
      owner: 'project',
      input: 'image',
      model: { _hidden: true },
      ownRuleCount: 4,
    })
    const view = mount(h)
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-owner"]')),
    ).toBe('Project')
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-model"]')),
    ).toContain('Vision model')
    expect(text(view.querySelector('[data-id="hilos-hidden"]'))).toBe('Hidden')
    expect(text(view)).not.toContain('text-model')
    expect(text(view)).not.toContain('[object Object]')
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-rules"]')),
    ).toBe('4 rules of your own')
  })

  it('keeps metadata loading separate from a refused window and recovers through the canonical answer', () => {
    const h = backend()
    const view = mount(h)
    expect(
      view.querySelector(
        '[role="status"][aria-label="Loading moderation kind"]',
      ),
    ).not.toBeNull()
    expect(view.querySelector('[data-id="hilos-table-unavailable"]')).toBeNull()
    h.answer()
    fixture!.detectChanges()
    expect(
      view.querySelector('[aria-label="Loading moderation kind"]'),
    ).toBeNull()
    expect(view.querySelector('dl')).not.toBeNull()
    h.receive('table_window_refused', {
      page: PAGE,
      tableKey: TABLE,
      errorCode: 'unavailable',
    })
    fixture!.detectChanges()
    const refused = view.querySelector('[data-id="hilos-table-unavailable"]')!
    expect(refused).not.toBeNull()
    expect(refused.getAttribute('role')).toBe('status')
    expect(text(refused)).toContain('List unavailable')
    expect(view.querySelector('dl, table')).toBeNull()
    expect(
      view.querySelector('[aria-label="Loading moderation kind"]'),
    ).toBeNull()
    expect(refused.querySelector('button, a')).toBeNull()
    h.answer({ ...initial, title: 'Recovered' })
    fixture!.detectChanges()
    expect(view.querySelector('[data-id="hilos-table-unavailable"]')).toBeNull()
    expect(text(view.querySelector('h1'))).toBe('Recovered')
    expect(view.querySelector('dl')).not.toBeNull()
  })

  it('clears metadata on a kind change and only shows the next kind after its answer', () => {
    const h = backend()
    h.answer()
    const view = mount(h)
    h.router.navigate(`/hilos/moderation/${photo.kind}`)
    fixture!.detectChanges()
    expect(view.querySelector('dl')).toBeNull()
    expect(
      view.querySelector('[aria-label="Loading moderation kind"]'),
    ).not.toBeNull()
    h.answer(photo)
    fixture!.detectChanges()
    expect(text(view.querySelector('h1'))).toBe('Profile photo')
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-key"]')),
    ).toBe(photo.kind)
    expect(text(view)).not.toContain('text-model')
  })

  it('releases the previous context and its name producer, ignores late updates and cleans up on destroy', () => {
    const old = backend()
    old.answer()
    const view = mount(old)
    const next = backend()
    next.answer({ ...initial, profile: 'new.profile' })
    fixture!.componentRef.setInput('context', next.context)
    fixture!.detectChanges()
    expect(old.context.connection.tableWindowDescriptors()).toEqual({})
    expect(old.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
    expect(
      text(view.querySelector('[data-id="hilos-moderation-kind-profile"]')),
    ).toBe('new.profile')
    old.delta('row_updated', { ...initial, profile: 'late.profile' })
    fixture!.detectChanges()
    expect(text(view)).not.toContain('late.profile')
    fixture!.destroy()
    fixture = undefined
    expect(next.context.connection.tableWindowDescriptors()).toEqual({})
    expect(next.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
  })
})
