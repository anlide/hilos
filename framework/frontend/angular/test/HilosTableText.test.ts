import {
  Component,
  ErrorHandler,
  InjectionToken,
  Input,
  inject,
  signal,
} from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HIDDEN_VALUE,
  TableViewportController,
  createSignal,
  type Hideable,
  type HilosTableColumn,
  type HilosTableFrame,
} from '@hilos/core/base'
import {
  HilosTableCell,
  HilosTableDetail,
  HilosTableText,
  HilosViewportTable,
} from '../src/base/index.js'
import { HILOS_TABLE_TEXT } from '../src/hilosTableTextContext.js'

const PARENT = new InjectionToken<string>('parts-parent')

@Component({
  selector: 'test-parts-child',
  imports: [HilosTableText],
  template: `
    <a href="#open" [attr.data-parent]="parent">Open</a>
    <span class="badge">Tag</span>
    @if (replacement) {
      <hilos-table-text [value]="value" />
    } @else {
      <hilos-table-text [value]="value" />
    }
    <button type="button">Edit</button>
  `,
})
class PartsChild {
  @Input() value: Hideable<string> = ''
  @Input() replacement = false
  readonly parent = inject(PARENT)
  readonly context = inject(HILOS_TABLE_TEXT)
}

@Component({
  imports: [
    HilosViewportTable,
    HilosTableCell,
    HilosTableDetail,
    HilosTableText,
    PartsChild,
  ],
  providers: [{ provide: PARENT, useValue: 'project dependency' }],
  template: `
    @if (mounted()) {
      <hilos-viewport-table [controller]="controller()!">
        @switch (renderer()) {
          @case ('parts') {
            <ng-template hilosTableCell="name" let-row let-rowKey="rowKey">
              <test-parts-child
                [value]="row.name"
                [replacement]="replacement()"
              />
              <span [attr.data-row-key]="rowKey"></span>
            </ng-template>
          }
          @case ('other') {
            <ng-template hilosTableCell="name" let-row>
              <hilos-table-text [value]="row.name" />
              <a href="#other">Other link</a>
            </ng-template>
          }
          @case ('zero') {
            <ng-template hilosTableCell="name">No region</ng-template>
          }
          @case ('two') {
            <ng-template hilosTableCell="name" let-row>
              <hilos-table-text [value]="row.name" />
              <hilos-table-text [value]="row.name" />
            </ng-template>
          }
          @case ('intrinsic') {
            <ng-template hilosTableCell="name" let-row>{{
              row.name
            }}</ng-template>
          }
        }
        <ng-template hilosTableCell="other" let-row>
          <hilos-table-text [value]="row.other" />
        </ng-template>
        <ng-template hilosTableCell="empty">
          <hilos-table-text [value]="''" />
        </ng-template>
        <ng-template hilosTableCell="plain"
          ><a href="#plain">Plain</a></ng-template
        >
        <ng-template hilosTableCell="detail">Independent detail</ng-template>
        <ng-template hilosTableDetail="detail">Independent detail</ng-template>
      </hilos-viewport-table>
    }
  `,
})
class PartsHost {
  readonly controller = signal<TableViewportController<Row> | null>(null)
  readonly renderer = signal('parts')
  readonly replacement = signal(false)
  readonly mounted = signal(true)
}

@Component({
  imports: [HilosTableText],
  template: `<hilos-table-text [value]="'Outside'" />`,
})
class OutsideHost {}

interface Row {
  name: Hideable<string>
  other: string
}

const columns: readonly HilosTableColumn[] = [
  { key: 'name', label: 'Name', card: 'title', heightContent: 'parts' },
  { key: 'other', label: 'Other', heightContent: 'parts' },
  { key: 'empty', label: 'Empty', heightContent: 'parts' },
  { key: 'missing', label: 'Missing', heightContent: 'parts' },
  { key: 'plain', label: 'Plain' },
  { key: 'detail', label: 'Detail', detail: true },
]
const controllers: TableViewportController<Row>[] = []
const fontsDescriptor = Object.getOwnPropertyDescriptor(document, 'fonts')

function controller(
  frame?: Partial<HilosTableFrame>,
  value: Hideable<string> = 'Full\nplain text',
) {
  const c = new TableViewportController<Row>({
    resolve: (raw) => ({
      name: raw.slots['name'] as Hideable<string>,
      other: 'Another value',
    }),
    sendViewport: () => undefined,
    frame: {
      title: 'Parts',
      search: {},
      rowHeight: { mode: 'lines', count: 3 },
      columns,
      ...frame,
    },
  })
  controllers.push(c)
  c.ingestWindow(
    [{ rowKey: 'a', slots: { name: value } }],
    1,
    true,
    null,
    null,
    10,
  )
  return c
}

async function settle(f: ComponentFixture<PartsHost>) {
  f.detectChanges()
  await f.whenStable()
  f.detectChanges()
  await f.whenStable()
}

function layout() {
  const frames = new Map<number, FrameRequestCallback>()
  const history: FrameRequestCallback[] = []
  const observers: { nodes: Set<Element>; callback: () => void }[] = []
  let serial = 0
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    frames.set(++serial, callback)
    history.push(callback)
    return serial
  })
  vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
  class Observer {
    readonly nodes = new Set<Element>()
    constructor(readonly callback: () => void) {
      observers.push(this)
    }
    observe(node: Element) {
      this.nodes.add(node)
    }
    unobserve(node: Element) {
      this.nodes.delete(node)
    }
    disconnect() {
      this.nodes.clear()
    }
  }
  vi.stubGlobal('ResizeObserver', Observer)
  vi.stubGlobal('MutationObserver', Observer)
  // Synthetic boxes prove identity, timing and focus. Native layout is proved
  // by the browser suite, which loads the shipped Sass.
  const sizes = { wide: 120, card: 120, width: 200 }
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    () => new DOMRect(0, 0, sizes.width, 48),
  )
  for (const key of [
    'clientWidth',
    'clientHeight',
    'scrollWidth',
    'scrollHeight',
  ] as const)
    vi.spyOn(HTMLElement.prototype, key, 'get').mockImplementation(function (
      this: HTMLElement,
    ) {
      if (key === 'clientHeight') return 48
      if (key === 'scrollHeight')
        return this.closest('td') ? sizes.wide : sizes.card
      return sizes.width
    })
  return { frames, history, observers, sizes }
}

async function mount(
  options: {
    frame?: Partial<HilosTableFrame>
    value?: Hideable<string>
    renderer?: string
  } = {},
) {
  const dom = layout()
  const f = TestBed.createComponent(PartsHost)
  const c = controller(options.frame, options.value)
  f.componentInstance.controller.set(c)
  f.componentInstance.renderer.set(options.renderer ?? 'parts')
  const root = f.nativeElement as HTMLElement
  document.body.append(root)
  await settle(f)
  const windows = () => [
    ...root.querySelectorAll<HTMLElement>('hilos-table-text [id]'),
  ]
  const toggles = () => [
    ...root.querySelectorAll<HTMLButtonElement>(
      '[data-id="hilos-table-text-toggle"]',
    ),
  ]
  const children = () =>
    f.debugElement
      .queryAll(By.directive(PartsChild))
      .map((node) => node.componentInstance as PartsChild)
  async function flush() {
    const pending = [...dom.frames.values()]
    dom.frames.clear()
    for (const callback of pending) callback(0)
    await settle(f)
  }
  await flush()
  return { f, root, controller: c, windows, toggles, children, flush, ...dom }
}

function update(c: TableViewportController<Row>, value: Hideable<string>) {
  c.ingestDelta({
    kind: 'row_updated',
    rowKey: 'a',
    row: { rowKey: 'a', slots: { name: value } },
  })
}

beforeEach(() => {
  // A valid transition must not merely log an after-render contract failure.
  TestBed.configureTestingModule({
    providers: [
      {
        provide: ErrorHandler,
        useValue: {
          handleError(error: unknown) {
            throw error
          },
        },
      },
    ],
  })
})

afterEach(() => {
  TestBed.resetTestingModule()
  for (const c of controllers.splice(0)) c.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  if (fontsDescriptor) Object.defineProperty(document, 'fonts', fontsDescriptor)
  else Reflect.deleteProperty(document, 'fonts')
  document.body.replaceChildren()
})

describe('public Angular parts text through project templates', () => {
  it('preserves parent DI and cell context with one region per projection and controls outside the clip', async () => {
    const m = await mount()
    expect(
      m.children().map((child) => child.context.value().projection),
    ).toEqual(['wide', 'card'])
    for (const child of m.children()) {
      expect(child.context.regions()).toBe(1)
      expect(child.context.value().readRecord()).not.toBeNull()
    }
    expect(m.children()[0]!.context.value().readRecord()).toBe(
      m.children()[1]!.context.value().readRecord(),
    )
    expect(
      m.root.querySelectorAll('[data-parent="project dependency"]'),
    ).toHaveLength(2)
    expect(m.root.querySelectorAll('[data-row-key="a"]')).toHaveLength(2)
    expect(m.windows()).toHaveLength(6)
    expect(new Set(m.windows().map((node) => node.id)).size).toBe(6)
    expect(m.toggles()).toHaveLength(6)
    for (const button of m.toggles()) {
      expect(button.type).toBe('button')
      expect(button.getAttribute('aria-expanded')).toBe('false')
      expect(button.getAttribute('aria-label')).toMatch(
        /^Show in full: (Name|Other|Empty)$/,
      )
      expect(
        m.root.querySelector(`[id="${button.getAttribute('aria-controls')}"]`),
      ).not.toBeNull()
    }
    for (const node of m.windows()) {
      expect(node.closest('[aria-hidden]')).toBeNull()
      expect(
        node.querySelector('a, button, .badge, hilos-hidden-mark'),
      ).toBeNull()
    }
    const holders = m.root.querySelectorAll('hilos-table-text [inert]')
    expect(holders).toHaveLength(6)
    for (const node of holders) {
      expect(node.getAttribute('aria-hidden')).toBe('true')
      expect(node.textContent?.trim()).toBe('Show in full')
      expect(
        node.querySelector('[id], [data-id], button, a, [tabindex]'),
      ).toBeNull()
    }
    expect(m.root.querySelector('span hilos-table-value')).toBeNull()
    expect(m.root.querySelectorAll('a[href="#open"]')).toHaveLength(2)
    expect(m.root.querySelectorAll('test-parts-child > button')).toHaveLength(2)
    expect(m.root.querySelectorAll('test-parts-child > .badge')).toHaveLength(2)
  })

  it('keeps one plain text copy for empty, short, zero, multiline and long values', async () => {
    const m = await mount()
    m.sizes.wide = m.sizes.card = 10
    for (const value of [
      '',
      'Short',
      '0',
      'First\nSecond',
      'UnbrokenToken'.repeat(200),
    ]) {
      update(m.controller, value)
      await settle(m.f)
      await m.flush()
      const main = [
        ...m.root.querySelectorAll<HTMLElement>(
          'test-parts-child hilos-table-text [id]',
        ),
      ]
      expect(main).toHaveLength(2)
      for (const node of main) {
        expect(node.textContent).toBe(value)
        expect(node.children).toHaveLength(1)
        expect(node.firstElementChild!.children).toHaveLength(0)
      }
      expect(m.toggles()).toHaveLength(0)
      expect(
        [...m.root.querySelectorAll('dt')].map((node) =>
          node.textContent?.trim(),
        ),
      ).not.toContain('Missing')
      expect(
        [...m.root.querySelectorAll('dt')].map((node) =>
          node.textContent?.trim(),
        ),
      ).toContain('Empty')
    }
  })

  it('keeps ids and core intent separate for two mounted parts tables', async () => {
    const first = await mount()
    first.root.removeAttribute('id')
    const second = await mount()
    expect(first.root.isConnected).toBe(true)
    const ids = [...first.windows(), ...second.windows()].map((node) => node.id)
    expect(new Set(ids).size).toBe(12)
    first.toggles()[0]!.click()
    await settle(first.f)
    expect(first.controller.expandedText.get().size).toBe(1)
    expect(second.controller.expandedText.get().size).toBe(0)
    expect(
      second
        .toggles()
        .every((node) => node.getAttribute('aria-expanded') === 'false'),
    ).toBe(true)
  })

  it('shares only core intent between independent projection readers and retains the focused button until blur', async () => {
    const m = await mount()
    const click = vi.fn()
    const keydown = vi.fn()
    m.root.addEventListener('click', click)
    m.root.addEventListener('keydown', keydown)
    const button = m.toggles()[0]!
    button.focus()
    for (const key of ['Enter', ' '])
      button.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
    button.click()
    await settle(m.f)
    expect(click).not.toHaveBeenCalled()
    expect(keydown).not.toHaveBeenCalled()
    expect(
      m.toggles().filter((node) => node.textContent?.trim() === 'Collapse'),
    ).toHaveLength(2)
    expect(m.controller.expandedText.get().size).toBe(1)
    expect(m.controller.rows.get()[0]!.expanded).toBe(false)
    m.controller.expandRow('a', true)
    await settle(m.f)
    expect(m.root.textContent?.match(/Independent detail/g)).toHaveLength(2)
    m.sizes.wide = 10
    m.sizes.card = 120
    update(m.controller, 'Short')
    await settle(m.f)
    await m.flush()
    expect(m.toggles()[0]).toBe(button)
    expect(button.textContent?.trim()).toBe('Collapse')
    button.click()
    await settle(m.f)
    await m.flush()
    expect(button.textContent?.trim()).toBe('Show in full')
    expect(button.isConnected).toBe(true)
    expect(document.activeElement).toBe(button)
    expect(m.controller.rows.get()[0]!.expanded).toBe(true)
    expect(m.toggles().filter((node) => node.closest('td'))).toEqual([button])
    expect(m.toggles().filter((node) => !node.closest('td'))).toHaveLength(3)
    button.blur()
    await settle(m.f)
    expect(button.isConnected).toBe(false)
  })

  it('draws Hidden outside the empty minimum reserve without stringifying it, and releases old observers', async () => {
    const m = await mount({
      frame: { rowHeight: { mode: 'height', rem: 4.5 } },
    })
    const oldNodes = [
      ...m.root.querySelectorAll<HTMLElement>(
        'test-parts-child hilos-table-text [id]',
      ),
    ]
    m.toggles()[0]!.click()
    await settle(m.f)
    const stringify = vi.fn(() => {
      throw new Error('Hidden cannot stringify')
    })
    const hidden = { ...HIDDEN_VALUE }
    Object.defineProperty(hidden, 'toString', { value: stringify })
    Object.defineProperty(hidden, 'toJSON', { value: stringify })
    update(m.controller, hidden)
    await settle(m.f)
    await m.flush()
    expect(stringify).not.toHaveBeenCalled()
    expect(
      m.root.querySelectorAll('test-parts-child [data-id="hilos-hidden"]'),
    ).toHaveLength(2)
    for (const child of m.root.querySelectorAll('test-parts-child')) {
      const reserve = child.querySelector('.hilos-table-text-height-4-5')!
      expect(reserve.textContent).toBe('')
      expect(reserve.getAttribute('aria-hidden')).toBe('true')
      expect(reserve.hasAttribute('inert')).toBe(true)
      expect(reserve.classList.contains('is-open')).toBe(true)
      expect(reserve.querySelector('[data-id="hilos-hidden"]')).toBeNull()
      expect(
        child.querySelector('[data-id="hilos-table-text-toggle"]'),
      ).toBeNull()
    }
    for (const node of oldNodes) {
      expect(node.isConnected).toBe(false)
      expect(m.observers.some((observer) => observer.nodes.has(node))).toBe(
        false,
      )
    }
    update(m.controller, 'Visible again')
    await settle(m.f)
    await m.flush()
    expect(m.root.querySelector('[data-id="hilos-hidden"]')).toBeNull()
    expect(
      m.toggles().filter((node) => node.textContent?.trim() === 'Collapse'),
    ).toHaveLength(2)
  })

  it.each(['zero', 'two'])(
    'rejects %s regions only after the completed limited render',
    async (renderer) => {
      const handleError = vi.fn()
      TestBed.configureTestingModule({
        providers: [{ provide: ErrorHandler, useValue: { handleError } }],
      })
      await mount({ renderer })
      // Angular delivers after-render failures to ErrorHandler, including a
      // later render with the geometry binding. Every failure is the contract.
      expect(handleError).toHaveBeenCalled()
      for (const [error] of handleError.mock.calls) {
        expect(error).toBeInstanceOf(TypeError)
        expect(error.message).toBe(
          'HilosTableValue: limited parts requires exactly one HilosTableText.',
        )
      }
    },
  )

  it.each(['zero', 'two'])(
    'rejects a live replacement with %s regions after rendering it',
    async (renderer) => {
      const handleError = vi.fn()
      TestBed.configureTestingModule({
        providers: [{ provide: ErrorHandler, useValue: { handleError } }],
      })
      const m = await mount()
      expect(handleError).not.toHaveBeenCalled()
      m.f.componentInstance.renderer.set(renderer)
      expect(handleError).not.toHaveBeenCalled()
      await settle(m.f)
      expect(handleError).toHaveBeenCalled()
      for (const [error] of handleError.mock.calls) {
        expect(error).toBeInstanceOf(TypeError)
        expect(error.message).toBe(
          'HilosTableValue: limited parts requires exactly one HilosTableText.',
        )
      }
    },
  )

  it.each(
    ['zero', 'two'].flatMap((renderer) =>
      [undefined, { mode: 'content' } as const].map((rowHeight) => ({
        renderer,
        rowHeight,
      })),
    ),
  )(
    'allows %j without a cap or control holder',
    async ({ renderer, rowHeight }) => {
      const m = await mount({
        renderer,
        frame: { rowHeight },
      })
      expect(m.toggles()).toHaveLength(0)
      expect(m.root.querySelector('hilos-table-text [inert]')).toBeNull()
      expect(
        m.windows().every((node) => node.className === 'hilos-table-text'),
      ).toBe(true)
      m.f.componentInstance.renderer.set('parts')
      await settle(m.f)
      update(m.controller, HIDDEN_VALUE)
      await settle(m.f)
      expect(
        m.root.querySelectorAll('test-parts-child [data-id="hilos-hidden"]'),
      ).toHaveLength(2)
      expect(
        m.root.querySelector('test-parts-child hilos-table-text [inert]'),
      ).toBeNull()
    },
  )

  it('requires a parts context outside a cell', () => {
    expect(() => {
      const outside = TestBed.createComponent(OutsideHost)
      outside.detectChanges()
    }).toThrow(
      new TypeError('HilosTableText requires a heightContent: parts context.'),
    )
  })

  it('rejects an existing parts context that becomes intrinsic', async () => {
    const dynamic = createSignal(columns)
    const m = await mount({ frame: { columns: () => dynamic.get() } })
    dynamic.set(
      columns.map((column) =>
        column.key === 'name'
          ? { ...column, heightContent: undefined }
          : column,
      ),
    )
    await expect(settle(m.f)).rejects.toThrow(
      new TypeError('HilosTableText requires a heightContent: parts context.'),
    )
  })

  it('switches mode, removes and adds a column without rebuilding independent fields', async () => {
    const dynamic = createSignal(columns)
    const handleError = vi.fn()
    TestBed.configureTestingModule({
      providers: [{ provide: ErrorHandler, useValue: { handleError } }],
    })
    const m = await mount({ frame: { columns: () => dynamic.get() } })
    const button = m.toggles()[0]!
    button.click()
    await settle(m.f)
    const other = m
      .toggles()
      .find(
        (node) => node.getAttribute('aria-label') === 'Show in full: Other',
      )!
    const removed = m.children()
    m.f.componentInstance.renderer.set('intrinsic')
    dynamic.set(
      columns.map((column) =>
        column.key === 'name' ? { ...column, heightContent: 'text' } : column,
      ),
    )
    await settle(m.f)
    await m.flush()
    expect(m.children()).toHaveLength(0)
    expect(removed.every((child) => child.context.regions() === 0)).toBe(true)
    expect(button.isConnected).toBe(false)
    expect(m.toggles()).toContain(other)
    expect(m.controller.expandedText.get().size).toBe(1)
    expect(
      m.toggles().filter((node) => node.textContent?.trim() === 'Collapse'),
    ).toHaveLength(2)
    dynamic.set(columns.filter((column) => column.key !== 'name'))
    await settle(m.f)
    expect(m.toggles()).toContain(other)
    expect(m.controller.expandedText.get().size).toBe(0)
    m.f.componentInstance.renderer.set('parts')
    dynamic.set(columns)
    await settle(m.f)
    await m.flush()
    expect(m.children().every((child) => child.context.regions() === 1)).toBe(
      true,
    )
    expect(m.toggles()).toContain(other)
    const oldRead = m.children()[0]!.context.value().readRecord
    const next = controller({ rowHeight: { mode: 'height', rem: 4.5 } })
    m.f.componentInstance.controller.set(next)
    await settle(m.f)
    await m.flush()
    expect(oldRead()).toBeNull()
    expect(
      m.children().every((child) => child.context.value().controller === next),
    ).toBe(true)
    expect(
      m.windows().every((node) => node.className.includes('height-4-5')),
    ).toBe(true)
    expect(handleError).not.toHaveBeenCalled()
  })

  it('constructs and releases the public component without DOM globals for prerender', () => {
    const c = controller()
    const release = vi.fn()
    const register = vi.fn(() => release)
    const context = {
      value: signal({
        controller: c,
        readRecord: () => null,
        columnKey: 'name',
        label: 'Name',
        heightContent: 'parts' as const,
        projection: 'wide' as const,
      }),
      policy: () => c.frame.rowHeight,
      scope: () => null,
      regions: signal(1),
      register,
    }
    TestBed.configureTestingModule({
      providers: [{ provide: HILOS_TABLE_TEXT, useValue: context }],
    })
    // Initialize the test environment before removing the global DOM. The
    // component itself must need no browser access until Angular after-render.
    TestBed.inject(HILOS_TABLE_TEXT)
    const browser = vi.fn(() => {
      throw new Error('Unexpected browser access')
    })
    vi.stubGlobal('ngServerMode', true)
    vi.stubGlobal('window', undefined)
    vi.stubGlobal('document', undefined)
    vi.stubGlobal('requestAnimationFrame', undefined)
    for (const name of [
      'ResizeObserver',
      'MutationObserver',
      'getComputedStyle',
    ])
      vi.stubGlobal(name, browser)
    try {
      const component = TestBed.runInInjectionContext(
        () => new HilosTableText(),
      )
      expect(component).toBeInstanceOf(HilosTableText)
      expect(register).toHaveBeenCalledTimes(1)
      expect(browser).not.toHaveBeenCalled()
    } finally {
      vi.unstubAllGlobals()
    }
    TestBed.resetTestingModule()
    expect(release).toHaveBeenCalledTimes(1)
  })

  it('registers idempotently and replaces conditional children and project templates without false errors', async () => {
    const m = await mount()
    for (const child of m.children()) {
      const token = {}
      const release = child.context.register(token)
      expect(child.context.register(token)).toBe(release)
      expect(child.context.regions()).toBe(2)
      release()
      release()
      expect(child.context.regions()).toBe(1)
    }
    const independent = m
      .toggles()
      .find(
        (node) => node.getAttribute('aria-label') === 'Show in full: Other',
      )!
    const oldChildren = m.children()
    m.f.componentInstance.replacement.set(true)
    await settle(m.f)
    await m.flush()
    expect(
      m.children().every((child, index) => child === oldChildren[index]),
    ).toBe(true)
    expect(m.children().every((child) => child.context.regions() === 1)).toBe(
      true,
    )
    const oldText = m.f.debugElement
      .queryAll(By.directive(HilosTableText))
      .map((node) => node.componentInstance)
    m.f.componentInstance.renderer.set('other')
    await settle(m.f)
    await m.flush()
    expect(oldChildren.every((child) => child.context.regions() === 1)).toBe(
      true,
    )
    const nextText = m.f.debugElement
      .queryAll(By.directive(HilosTableText))
      .map((node) => node.componentInstance)
    expect(
      oldText.slice(0, 1).every((component) => !nextText.includes(component)),
    ).toBe(true)
    expect(m.toggles()).toContain(independent)
    expect(m.root.querySelectorAll('a[href="#other"]')).toHaveLength(2)
    m.f.componentInstance.renderer.set('missing')
    await settle(m.f)
    expect(m.root.querySelector('a[href="#other"]')).toBeNull()
    m.f.componentInstance.renderer.set('parts')
    await settle(m.f)
    expect(m.children().every((child) => child.context.regions() === 1)).toBe(
      true,
    )
    m.f.componentInstance.mounted.set(false)
    await settle(m.f)
    expect(m.windows()).toHaveLength(0)
    expect(m.observers.every((observer) => observer.nodes.size === 0)).toBe(
      true,
    )
  })

  it.each(['reset', 'refusal', 'window', 'departure', 'controller'] as const)(
    'rejects stale readers and controls before %s flush and reused keys',
    async (change) => {
      const m = await mount()
      const read = m.children()[0]!.context.value().readRecord
      const record = read()!
      const button = m.toggles()[0]!
      expect(m.controller.expandedText.get().size).toBe(0)
      if (change === 'reset') m.controller.resetWindowRequests()
      if (change === 'refusal') m.controller.ingestRefusal('internal_error')
      if (change === 'window') m.controller.setSearch('other')
      if (change === 'departure') {
        m.controller.ingestDelta({
          kind: 'row_removed',
          rowKey: 'a',
          reason: 'deleted',
        })
        m.controller.apply()
      }
      if (change === 'controller')
        m.f.componentInstance.controller.set(controller())
      else expect(read()).toBeNull()
      if (change !== 'controller') {
        button.click()
        m.controller.expandText(record, 'name', true)
        expect(m.controller.expandedText.get().size).toBe(0)
      }
      await settle(m.f)
      expect(read()).toBeNull()
      m.controller.ingestWindow(
        [{ rowKey: 'a', slots: { name: 'Reused key' } }],
        1,
        true,
        null,
        null,
        10,
      )
      await settle(m.f)
      await m.flush()
      expect(read()).toBeNull()
      expect(m.children()[0]!.context.value().readRecord()).not.toBe(record)
    },
  )

  it.each([false, true])(
    'removes both expanded projections at departure and preserves external focus: %s',
    async (external) => {
      const m = await mount()
      const button = m.toggles()[0]!
      button.focus()
      button.click()
      await settle(m.f)
      m.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
      })
      m.controller.apply()
      const outside = document.createElement('button')
      document.body.append(outside)
      if (external) outside.focus()
      await settle(m.f)
      expect(m.windows()).toHaveLength(0)
      expect(m.toggles()).toHaveLength(0)
      expect(m.root.querySelector('test-parts-child')).toBeNull()
      expect(m.controller.expandedText.get().size).toBe(0)
      expect(document.activeElement).toBe(
        external
          ? outside
          : m.root.querySelector('[data-id="hilos-table-search"]'),
      )
    },
  )

  it('ignores retired observer and frame callbacks after a new controller reuses the row key', async () => {
    const m = await mount()
    m.toggles()[0]!.click()
    await settle(m.f)
    const oldObservers = [...m.observers]
    const oldFrames = [...m.history]
    const next = controller({}, 'New occurrence')
    m.f.componentInstance.controller.set(next)
    await settle(m.f)
    await m.flush()
    const buttons = m.toggles()
    expect(buttons).toHaveLength(6)
    expect(
      buttons.every((node) => node.getAttribute('aria-expanded') === 'false'),
    ).toBe(true)
    expect(oldObservers.every((observer) => observer.nodes.size === 0)).toBe(
      true,
    )
    const rect = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    rect.mockClear()
    const scheduled = m.frames.size
    for (const observer of oldObservers) observer.callback()
    for (const callback of oldFrames) callback(0)
    expect(rect).not.toHaveBeenCalled()
    expect(m.frames.size).toBe(scheduled)
    expect(next.expandedText.get().size).toBe(0)
    expect(m.toggles().every((node, index) => node === buttons[index])).toBe(
      true,
    )
    buttons[0]!.click()
    await settle(m.f)
    expect(next.expandedText.get().size).toBe(1)
  })

  it('empties observer, RAF and unresolved font tickets when the entire mount is destroyed', async () => {
    let resolveFonts!: () => void
    const fonts = new EventTarget() as EventTarget & { ready: Promise<void> }
    fonts.ready = new Promise((resolve) => {
      resolveFonts = resolve
    })
    Object.defineProperty(document, 'fonts', {
      configurable: true,
      value: fonts,
    })
    const m = await mount()
    const children = m.children()
    const read = children[0]!.context.value().readRecord
    const nodes = m.windows()
    expect(
      nodes.every((node) =>
        m.observers.some(
          (observer) =>
            observer.nodes.has(node) &&
            observer.nodes.has(node.firstElementChild!),
        ),
      ),
    ).toBe(true)
    m.f.destroy()
    expect(read()).toBeNull()
    expect(children.every((child) => child.context.regions() === 0)).toBe(true)
    expect(m.observers.every((observer) => observer.nodes.size === 0)).toBe(
      true,
    )
    // Angular may retain its own scheduler frame. Drain it along with the
    // canceled adapter callbacks; none may read this destroyed presentation.
    m.frames.clear()
    const rect = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    rect.mockClear()
    const style = vi.spyOn(window, 'getComputedStyle')
    for (const callback of m.history) callback(0)
    for (const observer of m.observers) observer.callback()
    resolveFonts()
    await Promise.resolve()
    fonts.dispatchEvent(new Event('loadingdone'))
    window.dispatchEvent(new Event('resize'))
    document.dispatchEvent(new Event('visibilitychange'))
    expect(rect).not.toHaveBeenCalled()
    expect(style).not.toHaveBeenCalled()
    expect(m.frames.size).toBe(0)
  })
})
