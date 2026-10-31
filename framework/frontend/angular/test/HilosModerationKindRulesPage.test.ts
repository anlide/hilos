import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionLifecycle,
  bindAdminAccess,
  bindSessionScope,
  bindPageScope,
  createAppPageRouter,
  createHilosRouter,
  HilosConnection,
  HilosPages,
  PAGE_SIGNAL_SCHEMAS,
  SESSION_SIGNAL_SCHEMAS,
  resolvePageTitle,
  ScopeManager,
  type WebSocketLike,
} from '@hilos/core/base'
import {
  HILOS_MODERATION_KINDS_TABLE,
  HILOS_MODERATION_RULES_TABLE,
  type HilosModerationKindRow,
} from '@hilos/core/admin/moderation'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosModerationKindRulesPage } from '@hilos/angular/admin/moderation'
import { HILOS_ROUTER } from '@hilos/angular/base'

const TABLE = HILOS_MODERATION_KINDS_TABLE
const PAGE = HilosPages.MODERATION_KIND_RULES
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
let fixture: ComponentFixture<HilosModerationKindRulesPage> | undefined
const releases: Array<() => void> = []
afterEach(() => {
  fixture?.destroy()
  fixture = undefined
  for (const off of releases.splice(0)) off()
  TestBed.resetTestingModule()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
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
    projectSchemas: { ...PAGE_SIGNAL_SCHEMAS, ...SESSION_SIGNAL_SCHEMAS },
  })
  const scopes = new ScopeManager()
  const actions = new ActionLifecycle(connection)
  bindSessionScope(connection, scopes)
  releases.push(bindAdminAccess(scopes))
  const pages = bindPageScope(connection, scopes)
  const router = createHilosRouter(
    createAppPageRouter({}),
    pages,
    {
      pathname: () => `/hilos/moderation/${initial.kind}/rules`,
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
  function answer(row = initial, rules: string[] = []): void {
    receive('page_response', {
      page: PAGE,
      payload: {
        data: {
          pageLabel: 'Kind',
          pageHeadOwner: {
            page: HilosPages.MODERATION_KIND,
            label: 'Kind',
            lead: 'Checks and rules.',
            icon: 'bi-shield-check',
          },
          pageBreadcrumb: [
            { page: HilosPages.DASHBOARD, label: 'Hilos' },
            { page: HilosPages.MODERATION, label: 'Moderation' },
            { page: HilosPages.MODERATION_KIND, label: 'Kind' },
          ],
          pageTabs: [
            { page: HilosPages.MODERATION_KIND, label: 'Overview' },
            { page: PAGE, label: 'Rules' },
          ],
          pageChildren: [],
        },
        windows: {
          [HILOS_MODERATION_RULES_TABLE]: {
            rows: rules.map((rule, i) => ({
              rowKey: String(i + 1),
              slots: { rule: { id: i + 1, kind: row.kind, rule } },
            })),
            sort: [],
            limit: 25,
            totalCount: rules.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
          },
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
    context: { connection, scopes, actions },
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
  fixture = TestBed.createComponent(HilosModerationKindRulesPage)
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

describe('HilosModerationKindRulesPage', () => {
  it('uses the shared live entity head, separate Overview/Rules links and named viewport without a mount fetch', () => {
    const h = backend()
    h.answer(initial, ['First rule'])
    const view = mount(h)
    expect([...view.querySelectorAll('h1')].map(text)).toEqual(['Profile name'])
    const navigation = view.querySelector('[aria-label="Kind pages"]')!
    expect(
      [...navigation.querySelectorAll('a')].map((link) => [
        text(link),
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['Overview', '/hilos/moderation/hilos_profile_name'],
      ['Rules', '/hilos/moderation/hilos_profile_name/rules'],
    ])
    expect(text(navigation.querySelector('[aria-current="page"]'))).toBe(
      'Rules',
    )
    expect(view.querySelector('table')?.getAttribute('aria-labelledby')).toBe(
      view.querySelector('h1')!.id,
    )
    expect(text(view.querySelector('table'))).toContain('First rule')
    expect(view.querySelector('[aria-label="Edit rule"]')).not.toBeNull()
    expect(view.querySelector('[aria-label="Delete rule"]')).not.toBeNull()
    expect(h.sent.filter((frame) => frame.type === 'table_viewport')).toEqual(
      [],
    )
    h.delta('row_updated', { ...initial, title: 'Live kind' })
    fixture!.detectChanges()
    expect(text(view.querySelector('h1'))).toBe('Live kind')
    expect(h.router.currentTitle.get()).toBe('Live kind · Test')
  })

  it('shows built-in rules only from the total own count, never an empty search result or unavailable table', () => {
    const h = backend()
    h.answer()
    const view = mount(h)
    expect(text(view.querySelector('h2'))).toBe('Built-in rules')
    expect(
      [
        ...view.querySelectorAll(
          '[data-id="hilos-moderation-rules-built-in"] li',
        ),
      ].map(text),
    ).toEqual(initial.defaultRules)
    h.delta('row_updated', { ...initial, ownRuleCount: 2 })
    fixture!.detectChanges()
    expect(
      view.querySelector('[data-id="hilos-moderation-rules-built-in"]'),
    ).toBeNull()
    expect(text(view.querySelector('table'))).toContain(
      'No rules of your own yet',
    )
    h.delta('row_updated', initial)
    h.receive('table_window_refused', {
      page: PAGE,
      tableKey: HILOS_MODERATION_RULES_TABLE,
      errorCode: 'unavailable',
    })
    fixture!.detectChanges()
    expect(
      view.querySelector('[data-id="hilos-table-unavailable"]'),
    ).not.toBeNull()
    expect(
      view.querySelector('[data-id="hilos-moderation-rules-built-in"]'),
    ).toBeNull()
    h.answer()
    fixture!.detectChanges()
    expect(
      view.querySelector('[data-id="hilos-moderation-rules-built-in"]'),
    ).not.toBeNull()
  })

  it('keeps loading distinct from metadata refusal, recovers and clears old content on kind navigation', () => {
    const h = backend()
    const view = mount(h)
    expect(
      view.querySelector('[aria-label="Loading moderation rules"]'),
    ).not.toBeNull()
    h.receive('table_window_refused', {
      page: PAGE,
      tableKey: TABLE,
      errorCode: 'unavailable',
    })
    fixture!.detectChanges()
    expect(
      view.querySelector('[data-id="hilos-table-unavailable"]'),
    ).not.toBeNull()
    expect(
      view.querySelector('[aria-label="Loading moderation rules"]'),
    ).toBeNull()
    h.answer(initial, ['Old rule'])
    fixture!.detectChanges()
    view.querySelector<HTMLButtonElement>('[aria-label="Edit rule"]')!.click()
    fixture!.detectChanges()
    expect(view.querySelector('textarea')?.value).toBe('Old rule')
    h.router.navigate('/hilos/moderation/hilos_profile_photo/rules')
    fixture!.detectChanges()
    expect(view.querySelector('[role="dialog"]')).toBeNull()
    expect(view.querySelector('table')).toBeNull()
    h.answer(photo, ['Photo rule'])
    fixture!.detectChanges()
    expect(text(view.querySelector('h1'))).toBe('Profile photo')
    expect(text(view.querySelector('table'))).toContain('Photo rule')
    expect(text(view)).not.toContain('Old rule')
  })

  it('mounts windows under the admin provider: a viewer may draft but form submit and Save send nothing', () => {
    const h = backend()
    h.receive('handshake_response', {
      entities: { currentUser: null },
      data: { adminViewMode: true },
    })
    h.answer()
    const view = mount(h)
    const add = [...view.querySelectorAll<HTMLButtonElement>('button')].find(
      (button) => text(button) === 'Add rule',
    )!
    add.click()
    fixture!.detectChanges()
    const field = view.querySelector('textarea')!
    field.value = 'Local draft'
    field.dispatchEvent(new Event('input', { bubbles: true }))
    fixture!.detectChanges()
    const save = view.querySelector<HTMLButtonElement>(
      '[data-id="hilos-moderation-rule-add-submit"]',
    )!
    expect(field.matches(':disabled')).toBe(false)
    expect(save.disabled).toBe(true)
    save.click()
    field.form!.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    )
    expect(h.sent.filter((frame) => frame.type === 'action')).toEqual([])
    expect(field.value).toBe('Local draft')
  })

  it('disposes focused windows, viewport registrations, mirrors and name bindings on context swap and destroy', () => {
    const old = backend()
    old.answer(initial, ['Old rule'])
    const view = mount(old)
    view.querySelector<HTMLButtonElement>('[aria-label="Edit rule"]')!.click()
    fixture!.detectChanges()
    const next = backend()
    next.answer({ ...initial, title: 'New context' }, ['New rule'])
    fixture!.componentRef.setInput('context', next.context)
    fixture!.detectChanges()
    expect(old.context.connection.tableWindowDescriptors()).toEqual({})
    expect(old.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
    expect(old.sent).toContainEqual(
      expect.objectContaining({ type: 'table_row_focus', rowKey: '' }),
    )
    expect(view.querySelector('[role="dialog"]')).toBeNull()
    expect(text(view.querySelector('table'))).toContain('New rule')
    old.delta('row_updated', { ...initial, title: 'Late title' })
    fixture!.detectChanges()
    expect(text(view)).not.toContain('Late title')
    fixture!.destroy()
    fixture = undefined
    expect(next.context.connection.tableWindowDescriptors()).toEqual({})
    expect(next.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Kind')
  })

  it('renders current rule values, holds removals for Apply and requests a sorted viewport constrained to the kind', () => {
    const h = backend()
    h.answer({ ...initial, ownRuleCount: 1 }, ['Saved rule'])
    const view = mount(h)
    const delta = (kind: string) =>
      h.receive('table_viewport_delta', {
        page: PAGE,
        tableKey: HILOS_MODERATION_RULES_TABLE,
        kind,
        rowKey: '1',
        ...(kind === 'row_removed'
          ? {}
          : {
              row: {
                rowKey: '1',
                slots: {
                  rule: { id: 1, kind: initial.kind, rule: 'Updated rule' },
                },
              },
            }),
      })
    delta('row_updated')
    fixture!.detectChanges()
    expect(text(view.querySelector('table'))).toContain('Updated rule')
    delta('row_removed')
    fixture!.detectChanges()
    expect(view.querySelector('table [aria-label="Edit rule"]')).not.toBeNull()
    view
      .querySelector<HTMLButtonElement>('[data-id="hilos-table-apply"]')!
      .click()
    fixture!.detectChanges()
    expect(view.querySelector('table [aria-label="Edit rule"]')).toBeNull()
    view
      .querySelector<HTMLButtonElement>('[data-id="hilos-table-sort-rule"]')!
      .click()
    expect(h.sent.at(-1)).toMatchObject({
      type: 'table_viewport',
      page: PAGE,
      tableKey: HILOS_MODERATION_RULES_TABLE,
      sort: [{ field: 'rule', direction: 'asc' }],
      filter: { kind: initial.kind },
    })
  })
})
