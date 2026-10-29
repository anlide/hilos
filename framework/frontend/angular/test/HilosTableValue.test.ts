import { Component, InjectionToken, Input, inject, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  TableViewportController,
  createSignal,
  type HilosTableColumn,
  type HilosTableFrame,
} from '@hilos/core/base'
import { HilosTableCell } from '../src/HilosTableCell.js'
import { HilosTableDetail } from '../src/HilosTableDetail.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HILOS_TABLE_TEXT } from '../src/hilosTableTextContext.js'

const PARENT = new InjectionToken<string>('project-parent')

/** The template was declared by the project; both injectors must reach its child. */
@Component({
  selector: 'test-text-child',
  template: `
    @if (unsafe) {
      <button type="button">{{ value }}</button>
    } @else {
      <strong [attr.data-parent]="parent">{{ value }}</strong>
    }
  `,
})
class TextChild {
  @Input() value = ''
  @Input() unsafe = false
  readonly parent = inject(PARENT)
  readonly context = inject(HILOS_TABLE_TEXT)
}

@Component({
  imports: [HilosViewportTable, HilosTableCell, HilosTableDetail, TextChild],
  providers: [{ provide: PARENT, useValue: 'project dependency' }],
  template: `
    <hilos-viewport-table [controller]="controller()!">
      <ng-template hilosTableCell="name" let-row let-rowKey="rowKey">
        <test-text-child [value]="row.name" [unsafe]="unsafe()" />
        <span [attr.data-row-key]="rowKey"></span>
      </ng-template>
      <ng-template hilosTableCell="description" let-row>{{
        row.name
      }}</ng-template>
      <ng-template hilosTableCell="empty"></ng-template>
      <ng-template hilosTableCell="plain"
        ><a href="#plain">Plain</a></ng-template
      >
      <ng-template hilosTableCell="parts"
        ><div>Intrinsic parts</div></ng-template
      >
      <ng-template hilosTableCell="actions"
        ><button type="button">Edit</button></ng-template
      >
      <ng-template hilosTableDetail="detail">Independent detail</ng-template>
    </hilos-viewport-table>
  `,
})
class TextHost {
  readonly controller = signal<TableViewportController<{
    name: string
  }> | null>(null)
  readonly unsafe = signal(false)
}

const controllers: TableViewportController<{ name: string }>[] = []
let frames: Map<number, FrameRequestCallback>
let serial = 0
const columns: readonly HilosTableColumn[] = [
  { key: 'name', label: 'Name', card: 'title', heightContent: 'text' },
  { key: 'description', label: 'Description', heightContent: 'text' },
  { key: 'empty', label: 'Empty', heightContent: 'text' },
  { key: 'missing', label: 'Missing', heightContent: 'text' },
  { key: 'plain', label: 'Plain' },
  { key: 'parts', label: 'Parts' },
  { key: 'detail', label: 'Detail', detail: true },
  { key: 'actions', label: '' },
]

function controller(
  frame: HilosTableFrame = {
    title: 'Text',
    rowHeight: { mode: 'lines', count: 3 },
    columns,
  },
) {
  const result = new TableViewportController<{ name: string }>({
    resolve: (raw) => ({ name: String(raw.slots['name']) }),
    sendViewport: () => undefined,
    frame,
  })
  controllers.push(result)
  result.ingestWindow(
    [{ rowKey: 'a', slots: { name: 'Long plain text' } }],
    1,
    true,
    null,
    null,
    10,
  )
  return result
}

async function settle(f: ComponentFixture<TextHost>) {
  f.detectChanges()
  await f.whenStable()
  f.detectChanges()
  await f.whenStable()
}

async function mount(frame?: HilosTableFrame) {
  frames = new Map()
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    frames.set(++serial, callback)
    return serial
  })
  vi.stubGlobal('cancelAnimationFrame', (id: number) => frames.delete(id))
  const f = TestBed.createComponent(TextHost)
  f.componentInstance.controller.set(controller(frame))
  const root = f.nativeElement as HTMLElement
  document.body.append(root)
  await settle(f)
  const children = () =>
    f.debugElement
      .queryAll(By.directive(TextChild))
      .map((child) => child.componentInstance as TextChild)
  const windows = () => [
    ...root.querySelectorAll<HTMLElement>('[id^="hilos-table-text-"]'),
  ]
  const toggles = () => [
    ...root.querySelectorAll<HTMLButtonElement>(
      '[data-id="hilos-table-text-toggle"]',
    ),
  ]
  const sizes = { height: 48, scroll: 160, width: 100 }
  for (const node of windows()) {
    vi.spyOn(node, 'getBoundingClientRect').mockImplementation(
      () => new DOMRect(0, 0, sizes.width, sizes.height),
    )
    for (const [key, get] of Object.entries({
      clientWidth: () => sizes.width,
      clientHeight: () => sizes.height,
      scrollHeight: () => sizes.scroll,
      scrollWidth: () => sizes.width,
    }))
      Object.defineProperty(node, key, { configurable: true, get })
  }
  async function flush() {
    const pending = [...frames.values()]
    frames.clear()
    for (const callback of pending) callback(0)
    await settle(f)
  }
  await flush()
  return {
    f,
    root,
    children,
    windows,
    toggles,
    sizes,
    flush,
    controller: f.componentInstance.controller()!,
  }
}

afterEach(() => {
  TestBed.resetTestingModule()
  for (const c of controllers.splice(0)) c.dispose()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  document.body.replaceChildren()
})

describe('Angular table text through project templates', () => {
  it('provides exact context and parent dependencies in both wide/card projections', async () => {
    const f = await mount()
    expect(f.children()).toHaveLength(2)
    expect(
      f.children().map((child) => child.context.value().projection),
    ).toEqual(['wide', 'card'])
    const [wide, card] = f.children()
    expect(wide!.context.value().readRecord()).toBe(
      card!.context.value().readRecord(),
    )
    expect(wide!.context.value().readRecord()).not.toBeNull()
    expect(
      [...f.root.querySelectorAll('[data-parent]')].map((node) =>
        node.getAttribute('data-parent'),
      ),
    ).toEqual(['project dependency', 'project dependency'])
    expect(f.root.querySelectorAll('[data-row-key="a"]')).toHaveLength(2)
    expect(new Set(f.windows().map((node) => node.id)).size).toBe(6)
    expect(f.toggles()).toHaveLength(6)
    for (const button of f.toggles()) {
      expect(button.type).toBe('button')
      expect(button.getAttribute('aria-expanded')).toBe('false')
      expect(
        f.root.querySelector(`[id="${button.getAttribute('aria-controls')}"]`),
      ).not.toBeNull()
      expect(button.getAttribute('aria-label')).toMatch(
        /^Show in full: (Name|Description|Empty)$/,
      )
    }
    const holders = f.root.querySelectorAll(
      'hilos-table-value [inert][aria-hidden="true"]',
    )
    expect(holders).toHaveLength(6)
    for (const holder of holders) {
      expect(holder.textContent?.trim()).toBe('Show in full')
      expect(
        holder.querySelector('button, [id], [data-id], [tabindex]'),
      ).toBeNull()
    }
    expect(f.root.querySelector('span hilos-table-value')).toBeNull()
  })

  it.each([undefined, { mode: 'content' } as const])(
    'keeps intrinsic templates for %j and omits missing card fields',
    async (rowHeight) => {
      const f = await mount({ title: 'Intrinsic', columns, rowHeight })
      expect(f.toggles()).toHaveLength(0)
      expect(f.root.querySelectorAll('hilos-table-value [inert]')).toHaveLength(
        0,
      )
      expect(
        [...f.root.querySelectorAll('dt')].map((node) =>
          node.textContent?.trim(),
        ),
      ).not.toContain('Missing')
      expect(
        [...f.root.querySelectorAll('dt')].map((node) =>
          node.textContent?.trim(),
        ),
      ).toContain('Empty')
      expect(f.root.querySelectorAll('a[href="#plain"]')).toHaveLength(2)
      expect(f.root.textContent?.match(/Intrinsic parts/g)).toHaveLength(2)
      expect(
        f.windows().every((node) => !node.className.includes('lines-')),
      ).toBe(true)
    },
  )

  it('retains the same focused native button through live shortening and collapse until blur', async () => {
    const f = await mount()
    const button = f.toggles()[0]!
    button.focus()
    button.click()
    await settle(f.f)
    expect(button.textContent?.trim()).toBe('Collapse')
    expect(
      f.toggles().filter((node) => node.textContent?.trim() === 'Collapse'),
    ).toHaveLength(2)
    expect(f.controller.rows.get()[0]!.expanded).toBe(false)
    f.controller.expandRow('a', true)
    await settle(f.f)
    expect(f.root.textContent?.match(/Independent detail/g)).toHaveLength(2)
    f.sizes.scroll = 10
    f.controller.ingestDelta({
      kind: 'row_updated',
      rowKey: 'a',
      row: { rowKey: 'a', slots: { name: 'Short' } },
    })
    await settle(f.f)
    await f.flush()
    expect(f.toggles()[0]).toBe(button)
    expect(button.textContent?.trim()).toBe('Collapse')
    button.click()
    await settle(f.f)
    await f.flush()
    expect(button.textContent?.trim()).toBe('Show in full')
    expect(button.isConnected).toBe(true)
    expect(document.activeElement).toBe(button)
    expect(f.controller.rows.get()[0]!.expanded).toBe(true)
    button.blur()
    await settle(f.f)
    expect(button.isConnected).toBe(false)
  })

  it('gives multiple mounted tables unique text ids and independent intent', async () => {
    const first = await mount()
    // TestBed removes earlier root ids when it creates another fixture.
    // Keep the first table connected so this really exercises two live mounts.
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

  it.each([
    'a',
    'input',
    'img',
    'div',
    'badge',
    'hidden-mark',
    'focusable',
    'block-span',
  ])('removes the cap when a live consumer gains %s', async (kind) => {
    vi.spyOn(console, 'warn').mockImplementation(() => {})
    const f = await mount()
    const natural = f.windows()[0]!.firstElementChild!
    const element = document.createElement(
      ['badge', 'hidden-mark', 'focusable', 'block-span'].includes(kind)
        ? 'span'
        : kind,
    )
    if (kind === 'badge') element.className = 'badge'
    if (kind === 'hidden-mark') element.setAttribute('data-id', 'hilos-hidden')
    if (kind === 'focusable') element.tabIndex = 0
    if (kind === 'block-span') {
      const style = window.getComputedStyle.bind(window)
      vi.spyOn(window, 'getComputedStyle').mockImplementation((node) => {
        const result = style(node)
        return node === element
          ? new Proxy(result, {
              get: (target, key) =>
                key === 'display' ? 'block' : Reflect.get(target, key),
            })
          : result
      })
    }
    natural.append(element)
    await Promise.resolve()
    await settle(f.f)
    expect(f.windows()[0]!.className).toBe('')
    expect(element.isConnected).toBe(true)
    expect(f.windows()[1]!.className).toContain('lines-3')
  })

  it('does not bubble disclosure clicks or Enter/Space to row actions', async () => {
    const f = await mount()
    const click = vi.fn()
    const key = vi.fn()
    f.root.addEventListener('click', click)
    f.root.addEventListener('keydown', key)
    const button = f.toggles()[0]!
    for (const value of ['Enter', ' '])
      button.dispatchEvent(
        new KeyboardEvent('keydown', { key: value, bubbles: true }),
      )
    button.click()
    expect(click).not.toHaveBeenCalled()
    expect(key).not.toHaveBeenCalled()
    expect(f.controller.expandedText.get().size).toBe(1)
  })

  it('uncaps actual unsafe descendants on live changes and restores safe text', async () => {
    const warning = vi.spyOn(console, 'warn').mockImplementation(() => {})
    const f = await mount()
    f.f.componentInstance.unsafe.set(true)
    await settle(f.f)
    const unsafe = f.children().map((child) => child.context.value().projection)
    expect(unsafe).toEqual(['wide', 'card'])
    for (const child of f.root.querySelectorAll('test-text-child')) {
      const cap = child.closest('[id^="hilos-table-text-"]')!
      expect(cap.className).toBe('')
      expect(child.querySelector('button')).not.toBeNull()
    }
    expect(warning).toHaveBeenCalledWith(
      expect.stringContaining("heightContent: 'parts'"),
    )
    f.f.componentInstance.unsafe.set(false)
    await settle(f.f)
    await f.flush()
    expect(
      f.windows().filter((node) => node.className.includes('lines-3')),
    ).toHaveLength(6)
  })

  it.each([false, true])(
    'releases expanded text at departure and preserves external focus: %s',
    async (externalFocus) => {
      const f = await mount({
        title: 'Text',
        search: {},
        rowHeight: { mode: 'lines', count: 3 },
        columns,
      })
      const tableRoot = f.root.querySelector<HTMLElement>(
        'hilos-viewport-table > div',
      )!
      const search = f.root.querySelector<HTMLInputElement>(
        '[data-id="hilos-table-search"]',
      )!
      for (const node of [tableRoot, search])
        vi.spyOn(node, 'getBoundingClientRect').mockReturnValue(
          new DOMRect(0, 0, 200, 100),
        )
      const button = f.toggles()[0]!
      button.focus()
      button.click()
      await settle(f.f)
      f.controller.ingestDelta({
        kind: 'row_removed',
        rowKey: 'a',
        reason: 'deleted',
      })
      f.controller.apply()
      const other = document.createElement('button')
      document.body.append(other)
      if (externalFocus) other.focus()
      await settle(f.f)
      expect(f.windows()).toHaveLength(0)
      expect(f.toggles()).toHaveLength(0)
      expect(f.children()).toHaveLength(0)
      expect(f.controller.expandedText.get().size).toBe(0)
      expect(document.activeElement).toBe(externalFocus ? other : search)
    },
  )

  it.each(['reset', 'refusal', 'window', 'departure'] as const)(
    'rejects saved reader before %s flush, even with empty intent',
    async (change) => {
      const f = await mount()
      const read = f.children()[0]!.context.value().readRecord
      const record = read()!
      expect(f.controller.expandedText.get().size).toBe(0)
      if (change === 'reset') f.controller.resetWindowRequests()
      if (change === 'refusal') f.controller.ingestRefusal('internal_error')
      if (change === 'window') f.controller.setSearch('other')
      if (change === 'departure') {
        f.controller.ingestDelta({
          kind: 'row_removed',
          rowKey: 'a',
          reason: 'deleted',
        })
        f.controller.apply()
      }
      expect(read()).toBeNull()
      f.controller.expandText(record, 'name', true)
      expect(f.controller.expandedText.get().size).toBe(0)
      await settle(f.f)
      f.controller.ingestWindow(
        [{ rowKey: 'a', slots: { name: 'Reused key' } }],
        1,
        true,
        null,
        null,
        10,
      )
      await settle(f.f)
      expect(read()).toBeNull()
      expect(f.children()[0]!.context.value().readRecord()).not.toBe(record)
    },
  )

  it('disconnects both projections and invalidates observer callbacks when the mount is destroyed', async () => {
    const observers: { nodes: Set<Element>; callback: () => void }[] = []
    class Observer {
      readonly state: (typeof observers)[number]
      constructor(callback: () => void) {
        this.state = { nodes: new Set(), callback }
        observers.push(this.state)
      }
      observe(node: Element) {
        this.state.nodes.add(node)
      }
      unobserve(node: Element) {
        this.state.nodes.delete(node)
      }
      disconnect() {
        this.state.nodes.clear()
      }
    }
    vi.stubGlobal('ResizeObserver', Observer)
    vi.stubGlobal('MutationObserver', Observer)
    const f = await mount()
    const read = f.children()[0]!.context.value().readRecord
    const windows = f.windows()
    for (const node of windows)
      expect(observers.some((observer) => observer.nodes.has(node))).toBe(true)
    f.f.destroy()
    expect(observers.every((observer) => observer.nodes.size === 0)).toBe(true)
    expect(read()).toBeNull()
    const rect = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect')
    const style = vi.spyOn(window, 'getComputedStyle')
    // Include any already queued Angular scheduler callback as well as late
    // observer deliveries; none may read the destroyed presentation.
    const queued = [...frames.values()]
    frames.clear()
    for (const callback of queued) callback(0)
    for (const observer of observers) observer.callback()
    window.dispatchEvent(new Event('resize'))
    document.dispatchEvent(new Event('visibilitychange'))
    expect(rect).not.toHaveBeenCalled()
    expect(style).not.toHaveBeenCalled()
    expect(frames.size).toBe(0)
  })

  it('updates dynamic columns and controller without retaining old controls or intent', async () => {
    const dynamic = createSignal(columns)
    const f = await mount({
      title: 'Dynamic',
      rowHeight: { mode: 'height', rem: 4.5 },
      columns: () => dynamic.get(),
    })
    const button = f.toggles()[0]!
    button.click()
    await settle(f.f)
    const oldRead = f.children()[0]!.context.value().readRecord
    const independent = f
      .toggles()
      .find(
        (node) =>
          node.getAttribute('aria-label') === 'Show in full: Description',
      )!
    dynamic.set(
      columns.map((column) =>
        column.key === 'name'
          ? { ...column, heightContent: undefined }
          : column,
      ),
    )
    await settle(f.f)
    expect(f.controller.expandedText.get().size).toBe(0)
    expect(f.children()[0]!.context.value().heightContent).toBeUndefined()
    expect(button.isConnected).toBe(false)
    expect(f.toggles()).toContain(independent)
    const next = controller()
    f.f.componentInstance.controller.set(next)
    await settle(f.f)
    expect(oldRead()).toBeNull()
    expect(f.children()[0]!.context.value().controller).toBe(next)
    expect(f.children()[0]!.context.value().readRecord()).not.toBeNull()
  })
})
