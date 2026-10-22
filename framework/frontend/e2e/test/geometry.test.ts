// @vitest-environment happy-dom
import type { Expect, Page } from '@playwright/test'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { watchTableFirstLive } from '../geometry.js'

// Exercise the real observer callback and its RAF history, with numeric DOM
// stubs. Chromium separately proves the renderer's used tracks and native hits.
afterEach(() => {
  document.body.replaceChildren()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

function handle<T>(value: T) {
  return {
    evaluate: async <R>(read: (value: T) => R) => read(value),
    evaluateHandle: async <R>(read: (value: T) => R) => handle(read(value)),
    dispose: async () => {},
  }
}

async function firstLive() {
  const container = document.createElement('div')
  container.dataset.id = 'hilos-viewport-table'
  container.dataset.vApp = ''
  const vnode = { el: container, props: { controller: {} } }
  Object.defineProperty(container, '_vnode', { value: vnode })
  container.innerHTML =
    '<table><colgroup><col><col></colgroup>' +
    '<thead><tr><th></th><th></th></tr></thead><tbody></tbody></table>'
  document.body.append(container)
  const table = container.querySelector('table')!
  let frame: FrameRequestCallback | undefined
  vi.stubGlobal('requestAnimationFrame', (next: FrameRequestCallback) => {
    frame = next
    return 1
  })
  Object.defineProperty(table, 'currentCSSZoom', { value: 1 })
  const bounds = new Map<Element, DOMRect>([
    [container, new DOMRect(0, 0, 200, 60)],
    [table, new DOMRect(0, 0, 200, 60)],
    [table.querySelector('th')!, new DOMRect(0, 0, 120, 20)],
    [table.querySelector('th:last-child')!, new DOMRect(120, 0, 80, 20)],
  ])
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
    function (this: HTMLElement) {
      return bounds.get(this) ?? new DOMRect()
    },
  )
  const page = {
    getByTestId: () => ({
      locator: () => ({ elementHandle: async () => handle(table) }),
    }),
  } as unknown as Page
  const watch = await watchTableFirstLive(
    expect as unknown as Expect,
    page,
    'body',
    [],
  )
  table.classList.add('hilos-table-fixed')
  table.querySelector('tbody')!.innerHTML =
    '<tr data-id="hilos-table-row-a"><td>' +
    '<button data-id="page-action"></button></td><td>' +
    '<span class="hilos-table-marks">' +
    '<button data-id="hilos-table-expand-a"></button></span></td></tr>'
  const action = table.querySelector<HTMLElement>('[data-id="page-action"]')!
  const expand = table.querySelector<HTMLElement>(
    '[data-id="hilos-table-expand-a"]',
  )!
  for (const button of [action, expand]) {
    Object.defineProperty(button, 'currentCSSZoom', { value: 1 })
    Object.defineProperty(button, 'checkVisibility', { value: () => true })
  }
  bounds.set(table.querySelector('td')!, new DOMRect(0, 20, 120, 40))
  bounds.set(
    table.querySelector('td:last-child')!,
    new DOMRect(120, 20, 80, 40),
  )
  bounds.set(
    table.querySelector('.hilos-table-marks')!,
    new DOMRect(128, 24, 68, 32),
  )
  bounds.set(expand, new DOMRect(164, 24, 32, 32))
  bounds.set(action, new DOMRect(84, 24, 32, 32))
  vi.spyOn(document, 'elementFromPoint').mockImplementation((x) =>
    x >= 128 ? expand : action,
  )
  return {
    watch,
    paint: () => frame!(performance.now()),
    move: (left: number) => bounds.set(action, new DOMRect(left, 24, 32, 32)),
    moveExpand: () => bounds.set(expand, new DOMRect(194, 24, 32, 32)),
    replaceController: () => {
      vnode.props.controller = {}
    },
    evidence: async () => JSON.parse(await watch.evidence()),
  }
}

describe('first-live frame observer', () => {
  it('accepts the state room containing its own native expand button', async () => {
    const f = await firstLive()
    f.paint()
    await f.watch.check()
    const state = await f.evidence()
    expect(state.observation.liveFrames).toBe(1)
    expect(state.observation.violations).toEqual([])
    expect(state.observation.frames[0].actions).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          id: 'hilos-table-expand-a',
          insideMarks: true,
          hit: true,
        }),
      ]),
    )
    await f.watch.stop()
  })

  it('keeps an actual first-frame action overlap after the next frame recovers', async () => {
    const f = await firstLive()
    f.move(148)
    f.paint()
    f.move(84)
    f.paint()
    await expect(f.watch.check()).rejects.toThrow(
      'no rendered live frame covers an action',
    )
    const state = await f.evidence()
    expect(state.observation.liveFrames).toBe(2)
    expect(state.observation.violations).toEqual([
      expect.objectContaining({
        id: 'page-action',
        insideMarks: false,
        overlap: 32,
        hit: false,
      }),
    ])
    await f.watch.stop()
  })

  it('still refuses an action leaving its cell away from the marks', async () => {
    const f = await firstLive()
    f.move(-2)
    f.paint()
    await expect(f.watch.check()).rejects.toThrow()
    expect((await f.evidence()).observation.violations).toEqual([
      expect.objectContaining({ id: 'page-action', overlap: 0 }),
    ])
    await f.watch.stop()
  })

  it('does not record a queued frame after stop', async () => {
    const f = await firstLive()
    f.paint()
    await f.watch.stop()
    f.move(148)
    f.paint()
    expect((await f.evidence()).observation.liveFrames).toBe(1)
  })

  it('still refuses an expand button leaving its own state cell', async () => {
    const f = await firstLive()
    f.moveExpand()
    f.paint()
    await expect(f.watch.check()).rejects.toThrow()
    expect((await f.evidence()).observation.violations).toEqual([
      expect.objectContaining({
        id: 'hilos-table-expand-a',
        insideMarks: true,
      }),
    ])
    await f.watch.stop()
  })

  it('refuses a controller replacement even if the table element survives', async () => {
    const f = await firstLive()
    f.replaceController()
    f.paint()
    await expect(f.watch.check()).rejects.toThrow(
      'the original controller survives',
    )
    await f.watch.stop()
  })
})
