import type { Expect, Page } from '@playwright/test'
import type { TableGeometryProjection, TableGeometryWatch } from './geometry.js'
import { expectDataFreeDeparture, TABLE } from './table.js'

/** The third projection retires data in every mounted branch, including src. */
export async function expectTableTileDeparture(
  expect: Expect,
  page: Page,
  rowKey: string,
  reason: string,
  valueIds: readonly string[],
): Promise<void> {
  await expectDataFreeDeparture(expect, page, rowKey, reason, valueIds)
  const tile = page.getByTestId(`hilos-table-tile-${rowKey}`)
  await expect(tile.getByTestId('hilos-table-placeholder')).toHaveText(reason)
  await expect(
    tile.locator('img, [src], button, input, a, [tabindex], [id]'),
  ).toHaveCount(0)
  await expect(
    tile.locator('[data-id]:not([data-id="hilos-table-placeholder"])'),
  ).toHaveCount(0)
  await expect(
    page.getByTestId(`hilos-table-tile-image-${rowKey}`),
  ).toHaveCount(0)
}

/** Save real native observer deliveries, then replay their old callback tickets. */
export async function watchTableGeometryCallbacks(page: Page) {
  await page.addInitScript(() => {
    const deliveries = new Map<ResizeObserver, () => void>()
    const NativeObserver = window.ResizeObserver
    window.ResizeObserver = class extends NativeObserver {
      constructor(callback: ResizeObserverCallback) {
        super((entries, observer) => {
          deliveries.set(observer, () => callback(entries, observer))
          callback(entries, observer)
        })
      }
    }
    Object.assign(window, {
      __tableGeometryCallbacks: () => [...deliveries.values()],
    })
  })
  return {
    async capture() {
      const callbacks = await page.evaluateHandle(() =>
        (
          window as unknown as { __tableGeometryCallbacks(): (() => void)[] }
        ).__tableGeometryCallbacks(),
      )
      return {
        async replay(expect: Expect) {
          const count = await callbacks.evaluate((saved) => {
            for (const callback of saved) callback()
            return saved.length
          })
          expect(
            count,
            'real pre-transition observer deliveries were replayed',
          ).toBeGreaterThan(0)
        },
        dispose: () => callbacks.dispose(),
      }
    },
  }
}

/** The DOM numbers of one projection, without exposing boxes to a spec. */
interface AllocationFrame {
  readonly extent: number
  readonly floor: number
  readonly fallback: number
  readonly width: number
  readonly liveHeight: number
  readonly tracks: readonly number[]
  readonly font: string
  readonly rootFont: string
  readonly zoom: string
  readonly effectiveZoom: number
  readonly spacing: string
  readonly departed: boolean
  readonly sameOccurrence: boolean
  readonly sameShell: boolean
}

/** Condition checks operate on the same raw frames attached as evidence. */
export interface TableAllocationWatch extends TableGeometryWatch {
  changed(
    field:
      | 'width'
      | 'extent'
      | 'liveHeight'
      | 'tracks'
      | 'font'
      | 'rootFont'
      | 'zoom'
      | 'spacing',
    direction?: 'grew' | 'shrank',
  ): Promise<void>
}

/**
 * Observe a projection's own occupied allocation on mutation and every frame.
 * A hidden tree has no sample; its zero rectangles cannot become a baseline.
 * The generic path checks the first visible allocation against real Sass chrome.
 * connectedReads also records synchronous live reads before an immediate departure,
 * where neither a mutation delivery nor an animation frame can see the new extent.
 */
export async function watchTableAllocation(
  expect: Expect,
  page: Page,
  rowKey: string,
  projection: TableGeometryProjection,
  generic = false,
  liveKey = rowKey,
  connectedReads = false,
): Promise<TableAllocationWatch> {
  const handle = await page.evaluateHandle(
    ({ rowKey, projection, liveKey, connectedReads }) => {
      const root = document.querySelector('[data-id="hilos-viewport-table"]')!
      const frames: AllocationFrame[] = []
      let stopped = false
      let raf = 0
      const prefix = { wide: 'row', card: 'card', tiles: 'tile' }[projection]
      const selector = `[data-id="hilos-table-${prefix}-${rowKey}"]`
      const occurrence = root.querySelector(selector)
      const shell = occurrence?.parentElement
      const original = Element.prototype.getBoundingClientRect
      const px = (value: string) => Number.parseFloat(value) || 0
      function sample() {
        if (stopped) return
        const node = root.querySelector<HTMLElement>(selector)
        if (!node?.isConnected || !node.checkVisibility()) return
        const first = original.call(node)
        // CSS zoom changes viewport rectangles. The retained allocation is in
        // local CSS px; margins/computed Sass values already use that unit.
        const zoom = node.currentCSSZoom
        if (!Number.isFinite(zoom) || zoom <= 0)
          throw new Error('a visible allocation requires native effective zoom')
        let bottom = first.bottom
        let chrome: number
        let gap = 0
        const room = root.querySelector<HTMLElement>(
          `.hilos-table-room-${projection}`,
        )!
        const twins = room.querySelector<HTMLElement>(
          '.hilos-table-reason-twins',
        )!
        const reasonBox = original.call(twins)
        if (projection === 'wide') {
          for (
            let next = node.nextElementSibling;
            next;
            next = next.nextElementSibling
          ) {
            if (
              next.matches(
                '[data-id^="hilos-table-row-"]:not([data-id^="hilos-table-row-detail-"])',
              )
            )
              break
            if (next.checkVisibility()) bottom = original.call(next).bottom
          }
          chrome =
            (original.call(room.querySelector('.hilos-table-replacement-row')!)
              .height -
              reasonBox.height) /
            zoom
        } else {
          const style = getComputedStyle(room)
          const body = getComputedStyle(room.querySelector('.card-body')!)
          chrome =
            px(style.borderTopWidth) +
            px(style.borderBottomWidth) +
            px(body.paddingTop) +
            px(body.paddingBottom)
          gap = px(style.marginBottom) + px(style.marginTop)
          if (projection === 'tiles')
            gap += px(getComputedStyle(node.parentElement!).marginTop)
          bottom += px(getComputedStyle(node).marginBottom) * zoom
          if (projection === 'tiles')
            bottom += px(getComputedStyle(node.parentElement!).marginTop) * zoom
        }
        const live = root.querySelector<HTMLElement>(
          `[data-id="hilos-table-${prefix}-${liveKey}"]`,
        )
        const style = getComputedStyle(root)
        const readable = node.querySelector<HTMLElement>(
          '.hilos-table-record-space > span',
        )
        frames.push({
          extent: (bottom - first.top) / zoom,
          floor:
            (readable ? original.call(readable).height : reasonBox.height) /
              zoom +
            chrome +
            gap,
          fallback:
            px(
              getComputedStyle(room.querySelector('.hilos-table-fallback')!)
                .height,
            ) +
            chrome +
            gap,
          width: first.width / zoom,
          liveHeight: (live ? original.call(live).height : 0) / zoom,
          tracks: [...root.querySelectorAll('thead th')].map(
            (cell) => original.call(cell).width / zoom,
          ),
          font: style.fontFamily,
          rootFont: getComputedStyle(document.documentElement).fontSize,
          zoom: getComputedStyle(document.documentElement).zoom,
          effectiveZoom: zoom,
          spacing: `${style.lineHeight}|${style.letterSpacing}|${style.wordSpacing}`,
          departed: !!node.querySelector('[data-id="hilos-table-placeholder"]'),
          sameOccurrence: node === occurrence,
          sameShell: node.parentElement === shell,
        })
      }
      // Use native results internally so observing the adapter's read cannot recurse.
      function observed(this: Element): DOMRect {
        const result = original.call(this)
        if (!stopped && this === occurrence && this.isConnected) sample()
        return result
      }
      if (connectedReads) Element.prototype.getBoundingClientRect = observed
      function tick() {
        sample()
        if (!stopped) raf = requestAnimationFrame(tick)
      }
      const observer = new MutationObserver(sample)
      observer.observe(root, {
        subtree: true,
        attributes: true,
        childList: true,
        characterData: true,
      })
      sample()
      raf = requestAnimationFrame(tick)
      return {
        frames,
        sample,
        stop() {
          sample()
          stopped = true
          cancelAnimationFrame(raf)
          observer.disconnect()
          if (
            connectedReads &&
            Element.prototype.getBoundingClientRect === observed
          )
            Element.prototype.getBoundingClientRect = original
        },
      }
    },
    { rowKey, projection, liveKey, connectedReads },
  )
  const read = () =>
    handle.evaluate((watch) => {
      watch.sample()
      return watch.frames
    })
  return {
    async changed(field, direction) {
      const frames = await read()
      const first = frames[0]![field]
      const last = frames.at(-1)![field]
      expect(
        JSON.stringify(last),
        `actual layout condition changed: ${field}`,
      ).not.toBe(JSON.stringify(first))
      if (direction && typeof first === 'number' && typeof last === 'number') {
        if (direction === 'grew') expect(last).toBeGreaterThan(first + 1)
        else expect(last).toBeLessThan(first - 1)
      }
    },
    async check() {
      const frames = await read()
      expect(
        frames.length,
        'visible frames of the requested projection',
      ).toBeGreaterThan(0)
      const failures: unknown[] = []
      let retained: number | undefined
      for (const [index, frame] of frames.entries()) {
        if (!frame.departed) {
          if (!generic) retained = frame.extent
          continue
        }
        if (!frame.sameOccurrence) failures.push({ index, occurrence: frame })
        if (!frame.sameShell) failures.push({ index, shell: frame })
        const expected = Math.max(
          retained ?? (generic ? frame.fallback : frame.extent),
          frame.floor,
        )
        if (Math.abs(frame.extent - expected) > 1)
          failures.push({ index, expected, frame })
        retained = Math.max(retained ?? 0, frame.extent)
      }
      expect(
        failures,
        'Q1=A: own capture or first generic base, then grow only for reason',
      ).toEqual([])
    },
    async stop() {
      await handle.evaluate((watch) => watch.stop())
    },
    async evidence() {
      return JSON.stringify(
        { rowKey, projection, generic, liveKey, frames: await read() },
        null,
        2,
      )
    },
  }
}

/** A deliberate test-only change to the reader's browser environment. */
export type TableEnvironment =
  | { readonly kind: 'container'; readonly narrow: boolean }
  | { readonly kind: 'projection'; readonly projection: 'wide' | 'card' }
  | { readonly kind: 'font'; readonly size: number }
  | {
      readonly kind: 'text-font'
      readonly size: number
      readonly lineHeight: number
    }
  | { readonly kind: 'spacing'; readonly enabled: boolean }
  | {
      readonly kind: 'zoom'
      readonly factor: number
      readonly ancestorFactor?: number
    }
  | {
      readonly kind: 'family'
      readonly family:
        'monospace' | 'serif' | 'inherit' | 'GeometryLateFont, monospace'
    }

/** Change actual layout without a product test hook or a simulated resize event. */
export async function setTableEnvironment(
  page: Page,
  change: TableEnvironment,
  bodyId: string = TABLE,
): Promise<void> {
  if (change.kind === 'container' || change.kind === 'projection') {
    await page.evaluate(
      ({ change, bodyId }) => {
        const root = document.querySelector<HTMLElement>(
          `[data-id="${bodyId}"]`,
        )!
        if (change.kind === 'container') {
          root.classList.toggle('w-75', change.narrow)
        } else {
          const wide = root.querySelector<HTMLElement>('.table-responsive')!
          const cards = root.querySelector<HTMLElement>(
            '[data-id="hilos-table-cards"]',
          )!
          wide.classList.remove('d-md-block')
          cards.classList.remove('d-md-none')
          wide.classList.toggle('d-none', change.projection !== 'wide')
          cards.classList.toggle('d-none', change.projection !== 'card')
        }
      },
      { change, bodyId },
    )
    return
  }
  // A reader's override sheet is a browser input, like a viewport size. Keep it
  // outside the product's styling/inline channels and replace just that input.
  const id = `hilos-table-reader-${change.kind}`
  await page
    .getByTestId(id)
    .evaluateAll((nodes) => nodes.forEach((node) => node.remove()))
  let content: string
  switch (change.kind) {
    case 'font':
      content = `html { font-size: ${change.size}px; }`
      break
    case 'text-font':
      content = `[data-id="${bodyId}"] .hilos-table-text { font-size: ${change.size}px; line-height: ${change.lineHeight}; }`
      break
    case 'zoom':
      content = `html { zoom: ${change.factor}; } [data-id="${bodyId}"] { zoom: ${change.ancestorFactor ?? 1}; }`
      break
    case 'family':
      content = `[data-id="${bodyId}"] { font-family: ${change.family}; }`
      break
    case 'spacing':
      content = change.enabled
        ? `[data-id="${bodyId}"] { line-height: 1.5; letter-spacing: .12em; word-spacing: .16em; } [data-id="${bodyId}"] p { margin-bottom: 2em !important; }`
        : ''
  }
  if (content === '') return
  const sheet = await page.addStyleTag({ content })
  await sheet.evaluate((node, id) => {
    if (!(node instanceof HTMLStyleElement))
      throw new Error('the reader override must be a style element')
    node.dataset.id = id
  }, id)
}

/** Real late FontFace loading with Latin glyphs and a measurable metric change. */
export async function loadLateTableFont(
  expect: Expect,
  page: Page,
  bodyId: string,
  sampleId: string,
): Promise<string> {
  await page.evaluate(() => {
    document.fonts.forEach((font) => {
      if (font.family === 'GeometryLateFont') document.fonts.delete(font)
    })
  })
  await setTableEnvironment(
    page,
    { kind: 'family', family: 'GeometryLateFont, monospace' },
    bodyId,
  )
  const result = await page.evaluate(async (sampleId) => {
    const live = [
      ...document.querySelectorAll<HTMLElement>(`[data-id="${sampleId}"]`),
    ].find((node) => node.checkVisibility())!
    const before = live.getBoundingClientRect().width
    // The stand already supplies these system fonts. A fresh family loaded
    // after fonts.ready, with serif glyph metrics, makes completion observable
    // on actual Latin content rather than loading an unused icon-only face.
    const font = new FontFace(
      'GeometryLateFont',
      'local("Liberation Serif"), local("DejaVu Serif"), local("Times New Roman")',
    )
    const events: string[] = []
    const listener = () => events.push('loadingdone')
    document.fonts.addEventListener('loadingdone', listener)
    document.fonts.add(font)
    await document.fonts.load('16px GeometryLateFont', live.textContent ?? '')
    await document.fonts.ready
    document.fonts.removeEventListener('loadingdone', listener)
    return {
      status: font.status,
      events,
      before,
      after: live.getBoundingClientRect().width,
    }
  }, sampleId)
  expect(result.status).toBe('loaded')
  expect(result.events).toContain('loadingdone')
  expect(result.after, 'late font changes actual Latin glyph metrics').not.toBe(
    result.before,
  )
  return JSON.stringify(result)
}

/** Check native effective layout zoom separately from computed own zoom and DPR. */
export async function expectTableLayoutZoom(
  expect: Expect,
  page: Page,
  sampleId: string,
  effectiveZoom: number,
  deviceScaleFactor: number,
): Promise<string> {
  const condition = await page.getByTestId(sampleId).evaluateAll((nodes) => {
    const node = nodes.find((node) => node.checkVisibility())!
    return {
      effectiveZoom: node.currentCSSZoom,
      ownZoom: getComputedStyle(node).zoom,
      rootZoom: getComputedStyle(document.documentElement).zoom,
      deviceScaleFactor: window.devicePixelRatio,
    }
  })
  expect(
    condition.effectiveZoom,
    'native effective CSS layout zoom',
  ).toBeCloseTo(effectiveZoom, 8)
  expect(condition.ownZoom, 'the sample itself has no zoom override').toBe('1')
  expect(condition.deviceScaleFactor, 'DPR is independent of layout zoom').toBe(
    deviceScaleFactor,
  )
  return JSON.stringify(condition)
}

/** Install native observer accounting before mounting the fixture. */
export async function watchTableNativeLifecycle(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const active = new Map<object, Set<Element>>()
    const listeners = new Map<EventTarget, Map<string, Set<unknown>>>()
    for (const name of ['ResizeObserver', 'MutationObserver'] as const) {
      if (name === 'ResizeObserver') {
        window.ResizeObserver = class extends ResizeObserver {
          observe(target: Element, options?: ResizeObserverOptions) {
            super.observe(target, options)
            if (!active.has(this)) active.set(this, new Set())
            active.get(this)!.add(target)
          }
          unobserve(target: Element) {
            super.unobserve(target)
            active.get(this)?.delete(target)
          }
          disconnect() {
            super.disconnect()
            active.delete(this)
          }
        }
      } else {
        window.MutationObserver = class extends MutationObserver {
          observe(target: Node, options?: MutationObserverInit) {
            super.observe(target, options)
            if (target instanceof Element) {
              if (!active.has(this)) active.set(this, new Set())
              active.get(this)!.add(target)
            }
          }
          disconnect() {
            super.disconnect()
            active.delete(this)
          }
        }
      }
    }
    const add = EventTarget.prototype.addEventListener
    const remove = EventTarget.prototype.removeEventListener
    EventTarget.prototype.addEventListener = function (
      type,
      listener,
      options,
    ) {
      if (
        (this === window && type === 'resize') ||
        (this === document && type === 'visibilitychange') ||
        (this === document.fonts && type === 'loadingdone')
      ) {
        if (!listeners.has(this)) listeners.set(this, new Map())
        if (!listeners.get(this)!.has(type))
          listeners.get(this)!.set(type, new Set())
        listeners.get(this)!.get(type)!.add(listener)
      }
      return add.call(this, type, listener, options)
    }
    EventTarget.prototype.removeEventListener = function (
      type,
      listener,
      options,
    ) {
      listeners.get(this)?.get(type)?.delete(listener)
      return remove.call(this, type, listener, options)
    }
    Object.assign(window, {
      __tableLifecycle: () => ({
        targets: [...active.values()].reduce(
          (sum, targets) => sum + targets.size,
          0,
        ),
        detached: [...active.values()]
          .flatMap((targets) => [...targets])
          .filter((node) => !node.isConnected).length,
        listeners: [...listeners.values()]
          .flatMap((types) => [...types.values()])
          .reduce((sum, entries) => sum + entries.size, 0),
      }),
    })
  })
}

/** Browser lifecycle statistics contain counts only, never retained row data. */
export async function expectTableNativeLifecycle(
  expect: Expect,
  page: Page,
  mounted: boolean,
  expectedListeners = 3,
): Promise<string> {
  const stats = await page.evaluate(() =>
    (
      window as unknown as {
        __tableLifecycle(): {
          targets: number
          detached: number
          listeners: number
        }
      }
    ).__tableLifecycle(),
  )
  expect(stats.detached, 'no disconnected nodes remain observed').toBe(0)
  if (!mounted) {
    expect(stats.targets).toBe(0)
    expect(stats.listeners).toBe(0)
  } else {
    expect(stats.targets).toBeGreaterThan(0)
    expect(stats.listeners).toBe(expectedListeners)
  }
  return JSON.stringify(stats)
}
