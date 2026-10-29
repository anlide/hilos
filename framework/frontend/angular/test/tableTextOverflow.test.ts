import { signal } from '@angular/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createSignal, TableViewportGeometry } from '@hilos/core/base'
import {
  createTableTextOverflow,
  type TableTextOverflowScope,
} from '../src/tableTextOverflow.js'

const cleanups: (() => void)[] = []
const fontsDescriptor = Object.getOwnPropertyDescriptor(document, 'fonts')
afterEach(() => {
  for (const cleanup of cleanups.splice(0)) cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  if (fontsDescriptor) Object.defineProperty(document, 'fonts', fontsDescriptor)
  else Reflect.deleteProperty(document, 'fonts')
  document.body.replaceChildren()
})

// Numeric DOM seam only; Sass wrapping is covered by native browser scenarios.
function fixture() {
  const frames = new Map<number, FrameRequestCallback>()
  let serial = 0
  vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
    frames.set(++serial, callback)
    return serial
  })
  const cancel = vi.fn((id: number) => frames.delete(id))
  vi.stubGlobal('cancelAnimationFrame', cancel)
  const observers: { nodes: Set<Element>; callback: () => void }[] = []
  const mutations: (() => void)[] = []
  vi.stubGlobal(
    'ResizeObserver',
    class {
      readonly state: (typeof observers)[number]
      constructor(callback: () => void) {
        this.state = { nodes: new Set(), callback }
        observers.push(this.state)
      }
      observe(node: Element) {
        this.state.nodes.add(node)
      }
      disconnect() {
        this.state.nodes.clear()
      }
    },
  )
  const disconnect = vi.fn()
  vi.stubGlobal(
    'MutationObserver',
    class {
      constructor(callback: () => void) {
        mutations.push(callback)
      }
      observe() {}
      disconnect = disconnect
    },
  )
  const owner = new TableViewportGeometry()
  owner.replaceWindow(['a'])
  const expandedText = createSignal<ReadonlyMap<number, ReadonlySet<string>>>(
    new Map(),
  )
  const expandText = vi.fn((record, columnKey, expanded) => {
    expandedText.set(
      expanded
        ? new Map([[record.occurrence, new Set([columnKey])]])
        : new Map(),
    )
  })
  let scope: TableTextOverflowScope | null = {
    controller: { expandedText, expandText },
    record: owner.record('a')!,
    columnKey: 'name',
    projection: 'wide',
  }
  const root = document.createElement('div')
  let target: HTMLElement | null = document.createElement('div')
  let content: HTMLElement | null = document.createElement('div')
  root.append(target)
  target.append(content)
  document.body.append(root)
  const sizes = { width: 100, height: 32, scroll: 96 }
  vi.spyOn(target, 'getBoundingClientRect').mockImplementation(
    () => new DOMRect(0, 0, sizes.width, sizes.height),
  )
  for (const [name, read] of Object.entries({
    clientWidth: () => sizes.width,
    clientHeight: () => sizes.height,
    scrollWidth: () => sizes.width,
    scrollHeight: () => sizes.scroll,
  }))
    Object.defineProperty(target, name, { configurable: true, get: read })
  let limited = true
  const adapter = createTableTextOverflow(
    () => scope,
    () => target,
    () => content,
    () => limited,
  )
  cleanups.push(adapter.dispose)
  function flush() {
    const pending = [...frames.values()]
    frames.clear()
    for (const callback of pending) callback(0)
  }
  return {
    adapter,
    root,
    target,
    content,
    sizes,
    expandedText,
    expandText,
    observers,
    mutations,
    frames,
    cancel,
    disconnect,
    flush,
    scope: () => scope,
    setScope: (next: TableTextOverflowScope | null) => {
      scope = next
    },
    setLimited: (value: boolean) => {
      limited = value
    },
    replaceRefs: () => {
      target = document.createElement('div')
      content = document.createElement('div')
      target.append(content)
    },
  }
}

describe('Angular text overflow DOM adapter', () => {
  it('observes the actual cap and uncapped node and measures after commit only', () => {
    const f = fixture()
    expect(f.observers).toHaveLength(0)
    expect(f.adapter.overflow()).toBeNull()
    f.adapter.commit()
    expect(f.observers[0]!.nodes.has(f.target!)).toBe(true)
    expect(f.observers[0]!.nodes.has(f.content!)).toBe(true)
    expect(f.adapter.overflow()).toBeNull()
    f.flush()
    expect(f.adapter.overflow()).toBe(true)
    f.sizes.scroll = 20
    f.observers[0]!.callback()
    f.flush()
    expect(f.adapter.overflow()).toBe(false)
  })

  it('keeps the last usable overflow through hidden, zero and expanded layout', () => {
    const f = fixture()
    f.adapter.commit()
    f.flush()
    f.root.hidden = true
    f.sizes.scroll = 20
    f.adapter.requestMeasure()
    f.flush()
    expect(f.adapter.overflow()).toBe(true)
    f.root.hidden = false
    f.sizes.height = 0
    f.adapter.requestMeasure()
    f.flush()
    expect(f.adapter.overflow()).toBe(true)
    f.sizes.height = 32
    f.adapter.setExpanded(true)
    expect(f.adapter.expanded()).toBe(true)
    f.adapter.commit()
    f.flush()
    expect(f.adapter.overflow()).toBe(true)
    f.adapter.setExpanded(false)
    f.adapter.commit()
    f.flush()
    expect(f.adapter.overflow()).toBe(false)
  })

  it.each([
    'controller',
    'record',
    'columnKey',
    'projection',
    'departure',
    'detached',
    'refs',
    'limited',
  ] as const)(
    'rejects saved commands and callbacks after a change of %s',
    (change) => {
      const f = fixture()
      f.adapter.commit()
      const callback = [...f.frames.values()][0]!
      const old = f.scope()!
      if (change === 'controller')
        f.setScope({ ...old, controller: { ...old.controller } })
      if (change === 'record') f.setScope({ ...old, record: { ...old.record } })
      if (change === 'columnKey') f.setScope({ ...old, columnKey: 'other' })
      if (change === 'projection') f.setScope({ ...old, projection: 'card' })
      if (change === 'departure') f.setScope(null)
      if (change === 'detached') f.target?.remove()
      if (change === 'refs') f.replaceRefs()
      if (change === 'limited') f.setLimited(false)
      f.adapter.setExpanded(true)
      callback(0)
      f.observers[0]!.callback()
      f.mutations[0]!()
      expect(f.expandText).not.toHaveBeenCalled()
      expect(f.adapter.isCurrent()).toBe(false)
      expect(f.observers[0]!.nodes.size).toBe(0)
      f.adapter.commit()
      expect(f.adapter.overflow()).toBeNull()
    },
  )

  it('does not render a previous scope mirror while the replacement commit is pending', () => {
    const f = fixture()
    const scope = signal(f.scope())
    const adapter = createTableTextOverflow(
      scope,
      () => f.target,
      () => f.content,
      () => true,
    )
    cleanups.push(adapter.dispose)
    adapter.commit()
    f.flush()
    adapter.setExpanded(true)
    expect(adapter.expanded()).toBe(true)
    expect(adapter.overflow()).toBe(true)
    scope.set({ ...scope()!, record: { ...scope()!.record } })
    expect(adapter.expanded()).toBe(false)
    expect(adapter.overflow()).toBeNull()
    expect(adapter.binding()).toBeNull()
    const calls = f.expandText.mock.calls.length
    adapter.setExpanded(false)
    expect(f.expandText).toHaveBeenCalledTimes(calls)
  })

  it('shares core intent across independently measured projections', () => {
    const f = fixture()
    f.adapter.commit()
    f.flush()
    const other = createTableTextOverflow(
      () => ({ ...f.scope()!, projection: 'card' }),
      () => f.target,
      () => f.content,
      () => true,
    )
    cleanups.push(other.dispose)
    other.commit()
    f.flush()
    f.adapter.setExpanded(true)
    expect(other.expanded()).toBe(true)
    other.setExpanded(false)
    expect(f.adapter.expanded()).toBe(false)
    expect(f.expandText).toHaveBeenLastCalledWith(
      f.scope()!.record,
      'name',
      false,
    )
  })

  it('clears callbacks, listeners, subscriptions and unresolved fonts tickets', async () => {
    let ready!: () => void
    const fonts = new EventTarget() as EventTarget & { ready: Promise<void> }
    fonts.ready = new Promise((resolve) => {
      ready = resolve
    })
    Object.defineProperty(document, 'fonts', {
      configurable: true,
      value: fonts,
    })
    const remove = vi.spyOn(fonts, 'removeEventListener')
    const f = fixture()
    f.adapter.commit()
    const queued = [...f.frames.values()][0]!
    const read = vi.spyOn(f.target!, 'getBoundingClientRect')
    f.adapter.dispose()
    queued(0)
    f.mutations[0]!()
    f.observers[0]!.callback()
    ready()
    await Promise.resolve()
    fonts.dispatchEvent(new Event('loadingdone'))
    window.dispatchEvent(new Event('resize'))
    document.dispatchEvent(new Event('visibilitychange'))
    f.expandedText.set(
      new Map([[f.scope()!.record.occurrence, new Set(['name'])]]),
    )
    f.adapter.commit()
    f.adapter.setExpanded(true)
    expect(f.adapter.expanded()).toBe(false)
    expect(f.adapter.binding()).toBeNull()
    expect(f.cancel).toHaveBeenCalled()
    expect(f.disconnect).toHaveBeenCalled()
    expect(remove).toHaveBeenCalledWith('loadingdone', expect.any(Function))
    expect(f.frames.size).toBe(0)
    expect(read).not.toHaveBeenCalled()
    expect(f.expandText).not.toHaveBeenCalled()
  })

  it('constructs and disposes without browser globals before Angular after-render', () => {
    vi.stubGlobal('document', undefined)
    vi.stubGlobal('window', undefined)
    const browser = vi.fn(() => {
      throw new Error('browser read')
    })
    for (const key of [
      'ResizeObserver',
      'MutationObserver',
      'requestAnimationFrame',
      'getComputedStyle',
    ])
      vi.stubGlobal(key, browser)
    const adapter = createTableTextOverflow(
      () => null,
      () => null,
      () => null,
      () => false,
    )
    adapter.dispose()
    expect(browser).not.toHaveBeenCalled()
  })
})
