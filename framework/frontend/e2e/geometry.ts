import type { Expect, Locator, Page } from '@playwright/test'

import { ROWS, TABLE, shownByTestId } from './table.js'

// A repaint may round a box differently without moving what a person sees.
// Keep both the number and its comparison here: the spec takes a bookmark and
// asks it whether anything moved, after waiting for the state it is proving.
// Measuring once on each call preserves a real move; polling could hide one.

/** How far a box may drift through a repaint's rounding, in CSS pixels. */
const SLACK_PX = 1

/** The module's private way into Watched, installed by the class itself. */
let take: (what: string, measure: () => Promise<number>) => Promise<Watched>

/** A measurement that can check itself without handing the spec a number. */
export class Watched {
  /** The element and measure named in a failed check. */
  readonly what: string

  /** The original reading, retained across every check. */
  #taken: number

  /** The same one-pass measurement used to take the bookmark. */
  #measure: () => Promise<number>

  static {
    take = async (what, measure) => new Watched(what, await measure(), measure)
  }

  /** Only this module's measurement functions can take a bookmark. */
  private constructor(
    what: string,
    taken: number,
    measure: () => Promise<number>,
  ) {
    this.what = what
    this.#taken = taken
    this.#measure = measure
  }

  /** Measure once again and refuse a move larger than a repaint may cost. */
  async unchanged(): Promise<void> {
    const now = await this.#measure()
    if (Math.abs(now - this.#taken) > SLACK_PX) {
      throw new Error(
        `${this.what}: was ${this.#taken}, now ${now} — more than the ${SLACK_PX}px of slack a repaint may cost`,
      )
    }
  }
}

/** Take a bookmark of the element's top edge in the viewport. */
export async function watchTop(element: Locator): Promise<Watched> {
  const what = `${element.toString()} top`

  return take(what, () => measureBox(element, 'y', what))
}

/** Take a bookmark of the element's left edge in the viewport. */
export async function watchLeft(element: Locator): Promise<Watched> {
  const what = `${element.toString()} left`

  return take(what, () => measureBox(element, 'x', what))
}

/**
 * Take a bookmark of how far below the top edge of `frame` the element's top
 * edge stands.
 *
 * Read against the frame rather than against the screen, for the reason
 * {@link watchFirstRowTop} gives: what stands above the frame moves. On a narrow
 * screen the shell's navigation wraps onto a second row the moment a session
 * signs in, and a button that stood still inside its card read as moved by that
 * row (HIL-1107). Both edges are read in one pass, so no scroll or reflow can
 * fall between them.
 *
 * @param element The element whose place inside the frame is watched.
 * @param frame The frame it stands in, such as the sign-in card.
 * @returns A bookmark of the element's distance from the frame's top edge.
 */
export async function watchTopWithin(
  element: Locator,
  frame: Locator,
): Promise<Watched> {
  const what = `${element.toString()} top within ${frame.toString()}`

  return take(what, async () => {
    const root = await frame.elementHandle()
    if (root === null) {
      throw new Error(
        `${what}: the frame is not on screen and cannot be measured`,
      )
    }
    try {
      return await element.evaluate(
        (node, { root, what }) => {
          if (
            !node.checkVisibility({ visibilityProperty: true }) ||
            !root.checkVisibility({ visibilityProperty: true })
          ) {
            throw new Error(
              `${what}: the element is not on screen and cannot be measured`,
            )
          }

          return (
            node.getBoundingClientRect().top - root.getBoundingClientRect().top
          )
        },
        { root, what },
      )
    } finally {
      await root.dispose()
    }
  })
}

/** Take a bookmark of the room the element occupies vertically. */
export async function watchHeight(element: Locator): Promise<Watched> {
  const what = `${element.toString()} height`

  return take(what, () => measureBox(element, 'height', what))
}

/** Text policy expected by the spec, independently of the shipped CSS classes. */
export type TableTextPolicy =
  | { readonly mode: 'content' }
  | { readonly mode: 'lines'; readonly count: number }
  | { readonly mode: 'height'; readonly rem: number }

/** The single visible full-text window; a hidden reserve has no id. */
export function tableTextRegion(page: Page, rowKey: string): Locator {
  return page
    .getByTestId(`hilos-table-row-${rowKey}`)
    .or(page.getByTestId(`hilos-table-card-${rowKey}`))
    .filter({ visible: true })
    .locator('.hilos-table-text[id]')
}

/**
 * Check authentic text/track bounds, including non-whitespace glyphs in the
 * unbroken token, in both the natural and the capped reading window.
 */
export async function expectTableTextTrack(
  expect: Expect,
  page: Page,
  rowKey: string,
  lines: number | null,
  expanded = false,
  overflow = true,
): Promise<string> {
  const region = tableTextRegion(page, rowKey)
  await expect(region).toHaveCount(1)
  const sample = await region.evaluate((node) => {
    const box = node.getBoundingClientRect()
    const style = getComputedStyle(node)
    const range = document.createRange()
    const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT)
    const glyphs: DOMRect[] = []
    // pre-wrap can hang trailing spaces; only actual glyphs must fit.
    for (let text = walker.nextNode(); text; text = walker.nextNode()) {
      for (const word of text.textContent!.matchAll(/\S+/g)) {
        range.setStart(text, word.index)
        range.setEnd(text, word.index + word[0].length)
        glyphs.push(...range.getClientRects())
      }
    }
    const table = node.closest('table')!
    const room = node.parentElement!.querySelector(':scope > .hilos-stack')
    return {
      height: box.height,
      lineHeight: Number.parseFloat(style.lineHeight),
      minHeight: Number.parseFloat(style.minBlockSize) || 0,
      whiteSpace: style.whiteSpace,
      overflowWrap: style.overflowWrap,
      overflowY: style.overflowY,
      scrollHeight: node.scrollHeight,
      clientHeight: node.clientHeight,
      scrollWidth: node.scrollWidth,
      clientWidth: node.clientWidth,
      tableWidth: table.getBoundingClientRect().width,
      portWidth: table.parentElement!.clientWidth,
      tracks: [...table.querySelectorAll('col')].map(
        (col) => col.getBoundingClientRect().width,
      ),
      escapedGlyphs: glyphs.filter(
        (glyph) => glyph.left < box.left - 1 || glyph.right > box.right + 1,
      ).length,
      firstGlyph: glyphs.length
        ? Math.min(...glyphs.map((glyph) => glyph.top)) - box.top
        : 0,
      lastGlyph: glyphs.length
        ? Math.max(...glyphs.map((glyph) => glyph.bottom)) - box.top
        : 0,
      lineCount: new Set(glyphs.map((glyph) => glyph.top)).size,
      hidden: !!node.closest('[aria-hidden="true"], [inert]'),
      open: node.classList.contains('is-open'),
      room: room
        ? {
            height: room.getBoundingClientRect().height,
            hidden: room
              .querySelector('.invisible')
              ?.getAttribute('aria-hidden'),
            inert: room.querySelector('.invisible')?.hasAttribute('inert'),
          }
        : null,
    }
  })
  const diagnostic = JSON.stringify(sample)
  expect(sample.tableWidth, diagnostic).toBeLessThanOrEqual(
    sample.portWidth + SLACK_PX,
  )
  expect(sample.escapedGlyphs, diagnostic).toBe(0)
  expect(sample.scrollWidth, diagnostic).toBeLessThanOrEqual(
    sample.clientWidth + SLACK_PX,
  )
  expect(sample.whiteSpace).toBe('pre-wrap')
  expect(sample.overflowWrap).toBe('anywhere')
  expect(sample.hidden).toBe(false)
  expect(sample.open).toBe(expanded)
  expect(sample.lineHeight).toBeGreaterThan(0)
  if (lines === null) {
    expect(sample.room).toBeNull()
    expect(sample.overflowY).toBe('visible')
  } else {
    const reserve = lines * sample.lineHeight
    if (expanded) {
      expect(sample.minHeight).toBe(reserve)
      expect(sample.height).toBeGreaterThanOrEqual(reserve - SLACK_PX)
    } else {
      expect(Math.abs(sample.height - reserve)).toBeLessThanOrEqual(SLACK_PX)
      expect(sample.scrollHeight > sample.clientHeight).toBe(overflow)
    }
    expect(sample.room!.height).toBeGreaterThan(0)
    expect(sample.room!.hidden).toBe('true')
    expect(sample.room!.inert).toBe(true)
  }
  if (expanded || lines === null) {
    expect(sample.scrollHeight).toBeLessThanOrEqual(
      sample.clientHeight + SLACK_PX,
    )
    expect(sample.firstGlyph).toBeGreaterThanOrEqual(-SLACK_PX)
    expect(sample.lastGlyph).toBeLessThanOrEqual(sample.height + SLACK_PX)
  } else if (overflow) {
    expect(sample.lastGlyph).toBeGreaterThan(sample.height)
  }
  return JSON.stringify({ rowKey, lines, expanded, ...sample }, null, 2)
}

/** Watch reserved reading room across changes of text and overflow controls. */
export async function watchTableTextRoom(
  page: Page,
  rowKey: string,
): Promise<Watched> {
  return watchHeight(
    tableTextRegion(page, rowKey)
      .locator('..')
      .locator(':scope > .hilos-stack'),
  )
}

/**
 * Watch the inline footprint across a text-only update or disclosure toggle.
 * Whole-port excess stays diagnostic (invisible mark reserves: P-698); text
 * must add none, and neither the used table nor any track may grow or shrink.
 * Keep the same browser chrome and layout conditions between these readings.
 */
export async function watchTableTextInline(
  expect: Expect,
  page: Page,
  rowKey: string,
): Promise<{ unchanged(): Promise<void>; evidence(): Promise<string> }> {
  const read = () =>
    tableTextRegion(page, rowKey).evaluate((node) => {
      const table = node.closest('table')!
      const port = table.parentElement!
      const zoom = node.currentCSSZoom
      return {
        available: port.clientWidth,
        excess: Math.max(0, port.scrollWidth - port.clientWidth),
        extent: port.scrollWidth,
        table: table.getBoundingClientRect().width / zoom,
        tracks: [...table.querySelectorAll('col')].map(
          (column) => column.getBoundingClientRect().width / zoom,
        ),
      }
    })
  const initial = await read()
  const frames = [initial]
  return {
    async unchanged() {
      const current = await read()
      frames.push(current)
      const diagnostic = JSON.stringify({ rowKey, initial, current })
      expect(
        Math.abs(current.available - initial.available),
        diagnostic,
      ).toBeLessThanOrEqual(SLACK_PX)
      expect(current.excess, diagnostic).toBeLessThanOrEqual(
        initial.excess + SLACK_PX,
      )
      expect(
        Math.abs(current.table - initial.table),
        diagnostic,
      ).toBeLessThanOrEqual(SLACK_PX)
      expect(current.tracks.length).toBe(initial.tracks.length)
      for (const [index, width] of current.tracks.entries())
        expect(
          Math.abs(width - initial.tracks[index]!),
          diagnostic,
        ).toBeLessThanOrEqual(SLACK_PX)
    },
    async evidence() {
      return JSON.stringify(
        {
          rowKey,
          wholePortAttribution:
            'P-698: invisible mark reserves; age not established',
          frames,
        },
        null,
        2,
      )
    },
  }
}

/**
 * Prove real used text height and readable glyph bounds, not just textContent.
 * The range covers the sole live value, including its last wrapped line. It
 * never changes clipping, measures a clone, or substitutes browser rectangles.
 */
export async function expectTableTextGeometry(
  expect: Expect,
  page: Page,
  rowKey: string,
  policy: TableTextPolicy,
  expanded = false,
  overflow = false,
): Promise<string> {
  const region = tableTextRegion(page, rowKey)
  await expect(region).toHaveCount(1)
  const sample = await region.evaluate((node) => {
    const box = node.getBoundingClientRect()
    const style = getComputedStyle(node)
    const zoom = node.currentCSSZoom
    // pre-wrap may hang trailing spaces outside a line box. Measure every
    // non-whitespace glyph run instead, so readability means actual ink fits.
    const range = document.createRange()
    const walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT)
    const lines: DOMRect[] = []
    for (let text = walker.nextNode(); text; text = walker.nextNode()) {
      for (const word of text.textContent!.matchAll(/\S+/g)) {
        range.setStart(text, word.index)
        range.setEnd(text, word.index + word[0].length)
        lines.push(...range.getClientRects())
      }
    }
    const room = node.parentElement!.querySelector(':scope > .hilos-stack')
    const holder = room?.querySelector(':scope > .invisible')
    const roomBox = room?.getBoundingClientRect()
    const holderBox = holder?.getBoundingClientRect()
    const scrollport = node.closest('.table-responsive')!
    const table = node.closest('table')!
    const tableBox = table.getBoundingClientRect()
    const cell = node.closest('td')!
    const cellBox = cell.getBoundingClientRect()
    const portBox = scrollport.getBoundingClientRect()
    return {
      height: box.height / zoom,
      width: box.width / zoom,
      lineHeight: Number.parseFloat(style.lineHeight),
      fontSize: Number.parseFloat(style.fontSize),
      rootFont: Number.parseFloat(
        getComputedStyle(document.documentElement).fontSize,
      ),
      minHeight: Number.parseFloat(style.minBlockSize) || 0,
      overflowY: style.overflowY,
      whiteSpace: style.whiteSpace,
      open: node.classList.contains('is-open'),
      hidden: !!node.closest('[aria-hidden="true"], [inert]'),
      content: node.textContent,
      scrollHeight: node.scrollHeight,
      clientHeight: node.clientHeight,
      scrollWidth: node.scrollWidth,
      clientWidth: node.clientWidth,
      scrollportWidth: scrollport.clientWidth,
      scrollportExtent: scrollport.scrollWidth,
      wholePortAttribution:
        'P-698: invisible mark reserves; age not established',
      tableWidth: tableBox.width / zoom,
      tableLeft: (tableBox.left - portBox.left) / zoom,
      tableClass: table.className,
      cellWidth: cellBox.width / zoom,
      cellScrollWidth: cell.scrollWidth,
      cellClientWidth: cell.clientWidth,
      textLeft: (box.left - cellBox.left) / zoom,
      textRight: (box.right - cellBox.left) / zoom,
      tracks: [...table.querySelectorAll('col')].map(
        (column) => column.getBoundingClientRect().width / zoom,
      ),
      overflowWrap: style.overflowWrap,
      lineCount: new Set(lines.map((line) => line.top)).size,
      firstLine: lines.length
        ? (Math.min(...lines.map((line) => line.top)) - box.top) / zoom
        : 0,
      lastLine: lines.length
        ? (Math.max(...lines.map((line) => line.bottom)) - box.top) / zoom
        : 0,
      escapedLines: lines.filter(
        (line) => line.left < box.left - 1 || line.right > box.right + 1,
      ).length,
      room: roomBox
        ? {
            height: roomBox.height / zoom,
            separation: (roomBox.top - box.bottom) / zoom,
            holderHeight: (holderBox?.height ?? 0) / zoom,
            holderText: holder?.textContent?.trim(),
            holderHidden: holder?.getAttribute('aria-hidden'),
            holderInert: holder?.hasAttribute('inert'),
            holderUnsafe: holder?.querySelectorAll(
              '[id], [data-id], button, a, input, [tabindex]',
            ).length,
            holderOwnId:
              holder?.hasAttribute('id') || holder?.hasAttribute('data-id'),
          }
        : null,
    }
  })
  expect(
    sample.hidden,
    'full plain text remains in the accessibility tree',
  ).toBe(false)
  expect(sample.lineHeight).toBeGreaterThan(0)
  expect(sample.whiteSpace).toBe('pre-wrap')
  expect(sample.lineCount).toBeGreaterThanOrEqual(
    (sample.content ?? '').split('\n').filter((line) => line.length > 0).length,
  )
  expect(sample.open).toBe(expanded)
  const diagnostic = JSON.stringify({
    ...sample,
    content: sample.content?.slice(0, 40),
  })
  expect(
    sample.escapedLines,
    `long tokens wrap within their track: ${diagnostic}`,
  ).toBe(0)
  expect(sample.scrollWidth).toBeLessThanOrEqual(sample.clientWidth + SLACK_PX)
  expect(sample.cellScrollWidth, diagnostic).toBeLessThanOrEqual(
    sample.cellClientWidth + SLACK_PX,
  )
  expect(sample.textLeft, diagnostic).toBeGreaterThanOrEqual(-SLACK_PX)
  expect(sample.textRight, diagnostic).toBeLessThanOrEqual(
    sample.cellWidth + SLACK_PX,
  )
  expect(sample.tableLeft, diagnostic).toBeGreaterThanOrEqual(-SLACK_PX)
  expect(sample.tableLeft + sample.tableWidth, diagnostic).toBeLessThanOrEqual(
    sample.scrollportWidth + SLACK_PX,
  )
  expect(
    sample.tracks.reduce((sum, width) => sum + width, 0),
    diagnostic,
  ).toBeLessThanOrEqual(sample.tableWidth + SLACK_PX)
  expect(['auto', 'scroll']).not.toContain(sample.overflowY)
  if (policy.mode === 'content') {
    expect(sample.room).toBeNull()
    expect(sample.minHeight).toBe(0)
  } else {
    const reserve =
      policy.mode === 'lines'
        ? policy.count * sample.lineHeight
        : policy.rem * sample.rootFont
    if (expanded) {
      expect(Math.abs(sample.minHeight - reserve)).toBeLessThanOrEqual(SLACK_PX)
      expect(sample.height).toBeGreaterThanOrEqual(reserve - SLACK_PX)
    } else {
      expect(
        Math.abs(sample.height - reserve),
        'used block-size follows the declared lh/rem preset',
      ).toBeLessThanOrEqual(SLACK_PX)
      expect(sample.scrollHeight > sample.clientHeight).toBe(overflow)
    }
    expect(sample.room).not.toBeNull()
    expect(sample.room!.height).toBeGreaterThan(0)
    expect(
      Math.abs(sample.room!.height - sample.room!.holderHeight),
    ).toBeLessThanOrEqual(SLACK_PX)
    expect(sample.room!.separation).toBeGreaterThanOrEqual(-SLACK_PX)
    expect(sample.room!.holderText).toBe('Show in full')
    expect(sample.room!.holderHidden).toBe('true')
    expect(sample.room!.holderInert).toBe(true)
    expect(sample.room!.holderUnsafe).toBe(0)
    expect(sample.room!.holderOwnId).toBe(false)
  }
  if (expanded || policy.mode === 'content') {
    expect(sample.scrollHeight).toBeLessThanOrEqual(
      sample.clientHeight + SLACK_PX,
    )
    expect(sample.firstLine).toBeGreaterThanOrEqual(-SLACK_PX)
    expect(
      sample.lastLine,
      'the last glyph line is inside the full reading window',
    ).toBeLessThanOrEqual(sample.height + SLACK_PX)
  } else if (overflow) {
    expect(sample.lastLine).toBeGreaterThan(sample.height)
  }
  return JSON.stringify(
    { rowKey, policy, expanded, overflow, ...sample },
    null,
    2,
  )
}

/** Mixed-value metadata and actions must remain outside every clipped ancestor. */
export async function expectTableTextParts(
  expect: Expect,
  page: Page,
  rowKey: string,
  outsideIds: readonly string[],
): Promise<void> {
  const row = page
    .getByTestId(`hilos-table-row-${rowKey}`)
    .or(page.getByTestId(`hilos-table-card-${rowKey}`))
    .filter({ visible: true })
  for (const id of outsideIds) {
    const part = row.getByTestId(id)
    await expect(part).toBeVisible()
    const state = await part.evaluate((node) => {
      const box = node.getBoundingClientRect()
      const clipped: string[] = []
      for (
        let ancestor = node.parentElement;
        ancestor;
        ancestor = ancestor.parentElement
      ) {
        const style = getComputedStyle(ancestor)
        const bounds = ancestor.getBoundingClientRect()
        if (
          /(hidden|clip)/.test(style.overflowY) &&
          (box.top < bounds.top - 1 || box.bottom > bounds.bottom + 1)
        )
          clipped.push(ancestor.tagName)
        if (
          /(hidden|clip|auto|scroll)/.test(style.overflowX) &&
          (box.left < bounds.left - 1 || box.right > bounds.right + 1)
        )
          clipped.push(`${ancestor.tagName}:inline`)
      }
      return { inText: !!node.closest('.hilos-table-text'), clipped }
    })
    expect(state.inText).toBe(false)
    expect(state.clipped).toEqual([])
  }
}

/** Intrinsic rows keep their own height rather than borrowing the tallest row. */
export async function expectTableIntrinsicRows(
  expect: Expect,
  page: Page,
  tallKey: string,
  shortKey: string,
): Promise<void> {
  const heights = await page.getByTestId(TABLE).evaluate(
    (root, keys) =>
      keys.map((key) => {
        const row = root.querySelector(`[data-id="hilos-table-row-${key}"]`)!
        return row.getBoundingClientRect().height / row.currentCSSZoom
      }),
    [tallKey, shortKey],
  )
  expect(heights[0]).toBeGreaterThan(heights[1]! + SLACK_PX)
}

/**
 * Find the point of `under` that `over` lies on, as a click position on `under`.
 *
 * A spec proving that a click passes through something drawn on top of its
 * target proves nothing when the click lands where nothing lies: two boxes can
 * share a strip along one edge while the target's center, where a plain click
 * goes, stays clear (HIL-1097 — a toast card over a dialog's footer covers the
 * lower edge of its button and never the middle). So the spec clicks here, in
 * the middle of the shared area, and a missing meeting refuses instead of
 * turning into a click that proves nothing. One read each, no polling: wait in
 * the spec for the state that puts both on screen.
 *
 * @param over The element drawn on top, such as a toast card.
 * @param under The element it covers, such as a dialog's button.
 * @returns The point, relative to the top left corner of `under`.
 */
export async function overlapSpot(
  over: Locator,
  under: Locator,
): Promise<{ x: number; y: number }> {
  const top = await readBox(over, `${over.toString()} box`)
  const bottom = await readBox(under, `${under.toString()} box`)
  const left = Math.max(top.x, bottom.x)
  const right = Math.min(top.x + top.width, bottom.x + bottom.width)
  const upper = Math.max(top.y, bottom.y)
  const lower = Math.min(top.y + top.height, bottom.y + bottom.height)
  if (left >= right || upper >= lower) {
    throw new Error(
      `${over.toString()} does not lie over ${under.toString()}: ${JSON.stringify(top)} and ${JSON.stringify(bottom)} share no area`,
    )
  }

  return {
    x: (left + right) / 2 - bottom.x,
    y: (upper + lower) / 2 - bottom.y,
  }
}

/**
 * Refuse `upper` unless its bottom edge stands no lower than the top edge of
 * `lower` — that is, unless it is drawn above it and over none of it.
 *
 * A live control that must not cover the content it belongs to is proven clear
 * of it here, in the toolbox, rather than by boxes compared in the spec
 * (HIL-1024 — the way back to the tail stands in the strip over the log pane,
 * not on its lines). One read each, no polling: wait in the spec for the state
 * that puts both on screen. A tolerance of 1px is allowed for subpixel rounding.
 *
 * @param upper The element that must stand above, such as a button.
 * @param lower The element it must not cover, such as a scrolling pane.
 */
export async function liesAbove(upper: Locator, lower: Locator): Promise<void> {
  const top = await readBox(upper, `${upper.toString()} box`)
  const bottom = await readBox(lower, `${lower.toString()} box`)
  if (top.y + top.height > bottom.y + SLACK_PX) {
    throw new Error(
      `${upper.toString()} does not lie above ${lower.toString()}: its bottom edge ${top.y + top.height} is below the other's top edge ${bottom.y}`,
    )
  }
}

/** Read a box field, refusing an absent box on both the first and later reads. */
async function measureBox(
  element: Locator,
  field: 'x' | 'y' | 'height',
  what: string,
): Promise<number> {
  return (await readBox(element, what))[field]
}

/** Read the element's whole box, refusing one that is not on screen. */
async function readBox(
  element: Locator,
  what: string,
): Promise<{ x: number; y: number; width: number; height: number }> {
  const box = await element.boundingBox()
  if (box === null) {
    throw new Error(
      `${what}: the element is not on screen and cannot be measured`,
    )
  }

  return box
}

/**
 * Refuse an element clipped by the viewport or a scrolling ancestor.
 *
 * Call after scrolling the element into view and waiting for its live state.
 * Unlike an area ratio of exactly one, this uses the toolbox's 1px allowance
 * for fractional scroll rounding while still rejecting a clipped control.
 *
 * @param element The control or label that must fit in its visible scrollport.
 */
export async function fitsInScrollport(element: Locator): Promise<void> {
  await element.evaluate(
    (node, { slack, what }) => {
      if (!node.checkVisibility({ visibilityProperty: true })) {
        throw new Error(`${what}: the element is not visible`)
      }
      const box = node.getBoundingClientRect()
      let left = 0
      let top = 0
      let right = document.documentElement.clientWidth
      let bottom = document.documentElement.clientHeight
      for (
        let parent = node.parentElement;
        parent;
        parent = parent.parentElement
      ) {
        const style = getComputedStyle(parent)
        const clipsX = /^(auto|scroll|hidden|clip)$/.test(style.overflowX)
        const clipsY = /^(auto|scroll|hidden|clip)$/.test(style.overflowY)
        if (!clipsX && !clipsY) continue
        const bounds = parent.getBoundingClientRect()
        if (clipsX) {
          const start = bounds.left + parent.clientLeft
          left = Math.max(left, start)
          right = Math.min(right, start + parent.clientWidth)
        }
        if (clipsY) {
          const start = bounds.top + parent.clientTop
          top = Math.max(top, start)
          bottom = Math.min(bottom, start + parent.clientHeight)
        }
      }
      const clipped = {
        left: Math.max(0, left - box.left),
        top: Math.max(0, top - box.top),
        right: Math.max(0, box.right - right),
        bottom: Math.max(0, box.bottom - bottom),
      }
      if (Object.values(clipped).some((pixels) => pixels > slack)) {
        throw new Error(
          `${what}: clipped by ${JSON.stringify(clipped)} px; allowance ${slack}px`,
        )
      }
    },
    { slack: SLACK_PX, what: element.toString() },
  )
}

/**
 * Watch how far below the top of the table's root its first row stands.
 *
 * A live message over the table takes room that was held before it arrived, so
 * this distance is the same before a message comes and after it goes
 * (styling-rules.md, "The room a live message takes"): the room stands between
 * the root's top and the rows, and any change of its height changes the distance.
 *
 * It is read against the root rather than against the screen or the document:
 * the shell scrolls its own main container (HilosLayout), a click scrolls its
 * target into view there, and a row that stood still would read as moved
 * (HIL-1032, a 22px drift after Show). Both edges are read in one pass, so no
 * scroll can fall between them.
 *
 * @param page The Playwright page showing the table.
 * @returns A bookmark of the first row's distance from the root's top edge.
 */
export async function watchFirstRowTop(page: Page): Promise<Watched> {
  const what = `${TABLE} first row top relative to its root`

  return take(what, () =>
    page.getByTestId(TABLE).evaluate(
      (root, { selector, what }) => {
        const row = root.querySelector(selector)
        if (
          row === null ||
          !root.checkVisibility({ visibilityProperty: true }) ||
          !row.checkVisibility({ visibilityProperty: true })
        ) {
          throw new Error(
            `${what}: the element is not on screen and cannot be measured`,
          )
        }

        return (
          row.getBoundingClientRect().top - root.getBoundingClientRect().top
        )
      },
      { selector: ROWS, what },
    ),
  )
}

/** Every independently measured renderer projection. */
export type TableGeometryProjection = 'wide' | 'card' | 'tiles'

/** Stable identities selected by a scenario, all measured against one body. */
export interface TableGeometryPlan {
  readonly bodyId: string
  readonly projection: TableGeometryProjection
  readonly rowKeys: readonly string[]
  readonly lastKey: string
}

interface TableBounds {
  readonly records: Record<
    string,
    { top: number; bottom: number; left: number; right: number }
  >
  readonly tracks: readonly { left: number; right: number }[]
  readonly marks: Record<string, { left: number; right: number } | null>
  readonly occupiedBottom: number
}

interface TableFrame {
  readonly time: number
  readonly bounds: TableBounds | null
  readonly error?: string
  readonly environment?: {
    readonly viewport: readonly number[]
    readonly rootWidth: number
    readonly tableLayout: string | undefined
    readonly tableWidth: string | undefined
    readonly declaredWidth: string | undefined
    readonly columns: readonly string[]
  }
}

interface BrowserGeometryWatch {
  readonly frames: TableFrame[]
  sample(): void
  stop(): void
}

type GeometryRoot = HTMLElement & {
  __hilosGeometryWatch?: BrowserGeometryWatch
}

/** A continuous observation; evidence stays fractional and is safe to attach. */
export interface TableGeometryWatch {
  check(): Promise<void>
  stop(): Promise<void>
  evidence(): Promise<string>
}

/**
 * Watch complete records, fixed tracks and the final occupied edge on every
 * rendered frame. Mutation samples also preserve the first replacement state.
 * No polling can discard a bad frame, and the final edge has one allowance,
 * independent of how many records departed.
 *
 * @param expect The spec's expect.
 * @param page The page showing the real table renderer.
 * @param plan Stable body and record identities selected by the scenario.
 * @returns An observation that can check all frames and expose opaque evidence.
 */
export async function watchTableGeometry(
  expect: Expect,
  page: Page,
  plan: TableGeometryPlan,
): Promise<TableGeometryWatch> {
  expect(
    plan.rowKeys.length,
    'watch at least one occupied record',
  ).toBeGreaterThanOrEqual(1)
  const root = page.getByTestId(plan.bodyId)
  const observation = await root.evaluateHandle((element, plan) => {
    const root = element as GeometryRoot
    root.__hilosGeometryWatch?.stop()
    const frames: TableFrame[] = []
    let stopped = false
    let frame = 0
    const prefix = { wide: 'row', card: 'card', tiles: 'tile' }[plan.projection]
    function read(): TableBounds {
      const origin = root.getBoundingClientRect()
      const zoom = root.currentCSSZoom
      if (!Number.isFinite(zoom) || zoom <= 0)
        throw new Error('a visible body requires native effective zoom')
      const records: TableBounds['records'] = {}
      const marks: TableBounds['marks'] = {}
      const visible = (node: Element | null): node is HTMLElement =>
        node instanceof HTMLElement &&
        node.checkVisibility({ visibilityProperty: true })
      function record(key: string) {
        const id = `hilos-table-${prefix}-${key}`
        const main = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
        if (!visible(main)) throw new Error(`Missing visible record ${id}`)
        const first = main.getBoundingClientRect()
        let bottom = first.bottom
        if (plan.projection === 'wide') {
          for (
            let next = main.nextElementSibling;
            next;
            next = next.nextElementSibling
          ) {
            if (
              next.matches(
                '[data-id^="hilos-table-row-"]:not([data-id^="hilos-table-row-detail-"])',
              )
            )
              break
            if (visible(next)) bottom = next.getBoundingClientRect().bottom
          }
        } else {
          const style = getComputedStyle(main)
          bottom += (Number.parseFloat(style.marginBottom) || 0) * zoom
          if (plan.projection === 'tiles') {
            // The persistent Bootstrap column owns the grid's vertical gutter.
            bottom +=
              (Number.parseFloat(
                getComputedStyle(main.parentElement!).marginTop,
              ) || 0) * zoom
          }
        }
        return {
          top: (first.top - origin.top) / zoom,
          bottom: (bottom - origin.top) / zoom,
          left: (first.left - origin.left) / zoom,
          right: (first.right - origin.left) / zoom,
        }
      }
      for (const key of plan.rowKeys) {
        records[key] = record(key)
        const id = `hilos-table-${prefix}-${key}`
        const mark = root.querySelector(`[data-id="${id}"] .hilos-table-marks`)
        // The inert sizing twin is invisible but still occupies layout room.
        // A display:none branch, in contrast, has no client rects.
        if (mark instanceof HTMLElement && mark.getClientRects().length > 0) {
          const box = mark.getBoundingClientRect()
          marks[key] = {
            left: (box.left - origin.left) / zoom,
            right: (box.right - origin.left) / zoom,
          }
        } else if (
          root.querySelector(
            `[data-id="${id}"] [data-id="hilos-table-placeholder"]`,
          )
        ) {
          marks[key] = null
        }
      }
      const tracks =
        plan.projection === 'wide'
          ? [...root.querySelectorAll('table thead tr > th')].map((cell) => {
              const box = cell.getBoundingClientRect()
              return {
                left: (box.left - origin.left) / zoom,
                right: (box.right - origin.left) / zoom,
              }
            })
          : []
      return {
        records,
        tracks,
        marks,
        occupiedBottom: record(plan.lastKey).bottom,
      }
    }
    function sample() {
      if (stopped) return
      const table = root.querySelector('table')
      const style = table ? getComputedStyle(table) : null
      const environment = {
        viewport: [window.innerWidth, window.innerHeight],
        rootWidth: root.getBoundingClientRect().width / root.currentCSSZoom,
        tableLayout: style?.tableLayout,
        tableWidth: style?.width,
        declaredWidth: table?.style.getPropertyValue('--hilos-table-width-px'),
        columns: table
          ? [...table.querySelectorAll('col')].map((column) =>
              column.style.getPropertyValue('--hilos-table-track-px'),
            )
          : [],
      }
      try {
        frames.push({ time: performance.now(), bounds: read(), environment })
      } catch (error) {
        frames.push({
          time: performance.now(),
          bounds: null,
          error: String(error),
          environment,
        })
      }
    }
    function tick() {
      if (stopped) return
      sample()
      frame = requestAnimationFrame(tick)
    }
    const mutations = new MutationObserver(sample)
    mutations.observe(root, {
      subtree: true,
      childList: true,
      attributes: true,
      characterData: true,
    })
    root.__hilosGeometryWatch = {
      frames,
      sample,
      stop() {
        if (stopped) return
        sample()
        stopped = true
        cancelAnimationFrame(frame)
        mutations.disconnect()
      },
    }
    sample()
    frame = requestAnimationFrame(tick)
    return root.__hilosGeometryWatch
  }, plan)
  async function frames(): Promise<TableFrame[]> {
    return observation.evaluate((watch) => {
      watch.sample()
      return watch.frames
    })
  }
  return {
    async check() {
      const all = await frames()
      const baseline = all[0]?.bounds
      expect(baseline, 'a visible initial layout is required').not.toBeNull()
      const failures: unknown[] = []
      function compare(
        before: unknown,
        after: unknown,
        path: string,
        time: number,
      ): void {
        if (typeof before === 'number' && typeof after === 'number') {
          if (Math.abs(before - after) > SLACK_PX)
            failures.push({ path, before, after, time })
        } else if (path.startsWith('marks.') && after === null) {
          // Only a positively identified placeholder can retire its marks.
          return
        } else if (
          before &&
          after &&
          typeof before === 'object' &&
          typeof after === 'object'
        ) {
          for (const key of Object.keys(before)) {
            compare(
              Reflect.get(before, key),
              Reflect.get(after, key),
              path ? `${path}.${key}` : key,
              time,
            )
          }
        } else if (before !== after)
          failures.push({ path, before, after, time })
      }
      for (const current of all) {
        if (current.error) failures.push(current)
        else compare(baseline, current.bounds, '', current.time)
      }
      expect(
        failures,
        'every boundary and total extent, including intermediate frames, within 1 CSS px',
      ).toEqual([])
    },
    async stop() {
      await observation.evaluate((watch) => watch.stop())
    },
    async evidence() {
      return JSON.stringify(
        {
          plan,
          slack: SLACK_PX,
          timeOrigin: await page.evaluate(() => performance.timeOrigin),
          frames: await frames(),
        },
        null,
        2,
      )
    },
  }
}

/**
 * Wait for initial fonts and observer-driven layout, never for a departure to
 * recover. Transition observations must already be armed before its trigger.
 *
 * @param page The page whose initial layout is settling.
 */
export async function settleTableLayout(page: Page): Promise<void> {
  await page.evaluate(async () => {
    await document.fonts.ready
    await new Promise<void>((resolve) =>
      requestAnimationFrame(() =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
      ),
    )
  })
}

/** Check decoded pictures and the used, equal 4:3 picture/empty-image frames. */
export async function expectTableTilePictures(
  expect: Expect,
  page: Page,
  pictureKeys: readonly string[],
  emptyKeys: readonly string[],
): Promise<string> {
  for (const key of pictureKeys) {
    const picture = page
      .getByTestId(`hilos-table-tile-image-${key}`)
      .locator('img')
    await expect(picture).toBeVisible()
    await expect
      .poll(() =>
        picture.evaluate(
          (node: HTMLImageElement) =>
            node.complete && node.naturalWidth > 0 && node.naturalHeight > 0,
        ),
      )
      .toBe(true)
  }
  for (const key of emptyKeys) {
    const frame = page.getByTestId(`hilos-table-tile-image-${key}`)
    await expect(frame).toHaveText('No image')
    await expect(frame.locator('img')).toHaveCount(0)
  }
  const frames = await page.getByTestId('hilos-table-tiles').evaluate(
    (root, keys) =>
      keys.map((key) => {
        const frame = root.querySelector<HTMLElement>(
          `[data-id="hilos-table-tile-image-${key}"]`,
        )!
        const box = frame.getBoundingClientRect()
        const tile = root.querySelector(`[data-id="hilos-table-tile-${key}"]`)!
        return {
          key,
          width: box.width,
          height: box.height,
          pictures: tile.querySelectorAll('img').length,
          outsideFrame: [...tile.querySelectorAll('img')].filter(
            (image) => !frame.contains(image),
          ).length,
        }
      }),
    [...pictureKeys, ...emptyKeys],
  )
  expect(frames.length).toBeGreaterThan(1)
  for (const frame of frames) {
    expect(frame.width).toBeGreaterThan(0)
    expect(
      Math.abs(frame.height - (frame.width * 3) / 4),
      'used 4:3 image frame',
    ).toBeLessThanOrEqual(SLACK_PX)
    expect(
      Math.abs(frame.height - frames[0]!.height),
      'image and No image take equal room',
    ).toBeLessThanOrEqual(SLACK_PX)
    expect(
      frame.outsideFrame,
      'the ordinary picture column is not repeated in the tile body',
    ).toBe(0)
    expect(frame.pictures).toBe(pictureKeys.includes(frame.key) ? 1 : 0)
  }
  return JSON.stringify(frames, null, 2)
}

/** A connected layout read is distinct from the layout a browser can paint. */
interface TableHandoffFrame {
  readonly fixed: boolean
  readonly paint: boolean
  readonly bounds: readonly number[]
  readonly text: readonly {
    readonly height: number
    readonly lineHeight: number
    readonly rootFont: number
    readonly open: boolean
  }[]
}

/**
 * Arm before mounting a table. Observe its actual auto layout and first fixed
 * layout, including calibration. A reading policy also checks every paint's
 * reserve and block bounds; pending DOM guards may change height before paint,
 * while the auto-to-fixed inline tracks must still agree on every native read.
 *
 * @param expect The spec's expect.
 * @param page The page about to mount the table.
 * @param bodyId The stable body surrounding the mount.
 * @param readingPolicy A declared text policy whose pending guard can change height.
 * @returns An observation using the same evidence/check lifetime as departures.
 */
export async function watchTableHandoff(
  expect: Expect,
  page: Page,
  bodyId: string,
  readingPolicy?: TableTextPolicy,
): Promise<TableGeometryWatch> {
  const root = page.getByTestId(bodyId)
  await root.evaluate((root) => {
    const readings: TableHandoffFrame[] = []
    let stopped = false
    let frame = 0
    const original = Element.prototype.getBoundingClientRect
    function sample(paint = false) {
      const table = root.querySelector('table')
      if (!table || !table.checkVisibility()) return
      const origin = original.call(table)
      const zoom = table.currentCSSZoom
      const cells = [...table.querySelectorAll('thead th')]
      const rows = [...table.querySelectorAll('tbody tr')]
      if (rows.length === 0) return
      readings.push({
        fixed: table.classList.contains('hilos-table-fixed'),
        paint,
        bounds: [...cells, ...rows].flatMap((element) => {
          const box = original.call(element)
          return [
            (box.top - origin.top) / zoom,
            (box.bottom - origin.top) / zoom,
            (box.left - origin.left) / zoom,
            (box.right - origin.left) / zoom,
          ]
        }),
        text: [
          ...table.querySelectorAll<HTMLElement>('.hilos-table-text[id]'),
        ].map((node) => ({
          height: original.call(node).height / node.currentCSSZoom,
          lineHeight: Number.parseFloat(getComputedStyle(node).lineHeight),
          rootFont: Number.parseFloat(
            getComputedStyle(document.documentElement).fontSize,
          ),
          open: node.classList.contains('is-open'),
        })),
      })
    }
    // A view can finish its mount and fixed-layout patch before mutation delivery.
    // Native reads preserve that calibration without treating it as a painted frame.
    function observed(this: Element): DOMRect {
      const result = original.call(this)
      if (!stopped && this === root.querySelector('table') && this.isConnected)
        sample()
      return result
    }
    Element.prototype.getBoundingClientRect = observed
    function tick() {
      if (stopped) return
      sample(true)
      frame = requestAnimationFrame(tick)
    }
    const observer = new MutationObserver(() => sample())
    observer.observe(root, { attributes: true, subtree: true, childList: true })
    Object.assign(root, {
      __hilosHandoff: {
        readings,
        stop() {
          stopped = true
          cancelAnimationFrame(frame)
          observer.disconnect()
          if (Element.prototype.getBoundingClientRect === observed)
            Element.prototype.getBoundingClientRect = original
          sample()
        },
      },
    })
    frame = requestAnimationFrame(tick)
  })
  type HandoffRoot = HTMLElement & {
    __hilosHandoff: {
      readings: TableHandoffFrame[]
      stop(): void
    }
  }
  const readings = () =>
    root.evaluate((root) => (root as HandoffRoot).__hilosHandoff.readings)
  return {
    async check() {
      const all = await readings()
      const first = all.findIndex((reading) => !reading.fixed)
      expect(
        first,
        'actual auto layout was observed before fixed tracks',
      ).toBeGreaterThanOrEqual(0)
      const fixed = all.slice(first + 1).filter((reading) => reading.fixed)
      expect(fixed.length, 'actual fixed handoff was observed').toBeGreaterThan(
        0,
      )
      const before = all[first]!.bounds
      for (const reading of fixed) {
        expect(reading.bounds.length).toBe(before.length)
        for (let index = 0; index < before.length; index++) {
          // A pending text DOM verdict is allowed to add its cap before paint.
          // Inline tracks still agree through all intermediate connected reads.
          if (readingPolicy && index % 4 < 2) continue
          expect(
            Math.abs(reading.bounds[index]! - before[index]!),
            `handoff boundary ${index}: ${before[index]} -> ${reading.bounds[index]}`,
          ).toBeLessThanOrEqual(SLACK_PX)
        }
      }
      if (readingPolicy) {
        const paints = all.filter((reading) => reading.paint)
        expect(
          paints.length,
          'first and subsequent native paints were observed',
        ).toBeGreaterThan(0)
        const firstPaint = paints[0]!
        for (const paint of paints) {
          expect(
            paint.fixed,
            'fixed tracks are ready before the first paint',
          ).toBe(true)
          expect(paint.bounds.length).toBe(firstPaint.bounds.length)
          for (let index = 0; index < paint.bounds.length; index++)
            expect(
              Math.abs(paint.bounds[index]! - firstPaint.bounds[index]!),
              `paint boundary ${index}: ${firstPaint.bounds[index]} -> ${paint.bounds[index]}`,
            ).toBeLessThanOrEqual(SLACK_PX)
          expect(
            paint.text.length,
            'paint contains the declared reading windows',
          ).toBeGreaterThan(0)
          for (const text of paint.text) {
            expect(text.open).toBe(false)
            if (readingPolicy.mode !== 'content') {
              const reserve =
                readingPolicy.mode === 'lines'
                  ? readingPolicy.count * text.lineHeight
                  : readingPolicy.rem * text.rootFont
              expect(
                Math.abs(text.height - reserve),
                'the declared cap is ready before the first paint',
              ).toBeLessThanOrEqual(SLACK_PX)
            }
          }
        }
      }
    },
    async stop() {
      await root.evaluate((root) => (root as HandoffRoot).__hilosHandoff.stop())
    },
    async evidence() {
      return JSON.stringify(await readings(), null, 2)
    },
  }
}

/**
 * Establish the stress conditions instead of passing on an ordinary short row:
 * a genuinely widest name, a taller full record, and wrapped surviving text
 * whose first line reaches within one word of the available right boundary.
 *
 * @param expect The spec's expect.
 * @param page The page containing the fixture's selected content.
 * @param widestId The widest name's stable data-id.
 * @param ordinaryId An ordinary name's stable data-id.
 * @param paragraphId A surviving paragraph's stable data-id.
 * @returns Opaque raw evidence of the actual stress condition.
 */
export async function expectTableWrappingStress(
  expect: Expect,
  page: Page,
  widestId: string,
  ordinaryId: string,
  paragraphId: string,
): Promise<string> {
  const reading = await page.evaluate(
    ({ widestId, ordinaryId, paragraphId }) => {
      function visible(id: string): HTMLElement {
        const element = [
          ...document.querySelectorAll<HTMLElement>(`[data-id="${id}"]`),
        ].find((node) => node.checkVisibility())
        if (!element) throw new Error(`No visible ${id}`)
        return element
      }
      const widest = visible(widestId).getBoundingClientRect()
      const ordinary = visible(ordinaryId).getBoundingClientRect()
      const paragraph = visible(paragraphId)
      const text = paragraph.firstChild
      if (!text) throw new Error('Missing paragraph text')
      const range = document.createRange()
      range.selectNodeContents(paragraph)
      const lines = [...range.getClientRects()]
      const box = paragraph.getBoundingClientRect()
      const canvas = document.createElement('canvas').getContext('2d')!
      canvas.font = getComputedStyle(paragraph).font
      const word = Math.max(
        ...(paragraph.textContent ?? '')
          .split(/\s+/)
          .map((word) => canvas.measureText(word).width),
      )
      return {
        widest: widest.width,
        ordinary: ordinary.width,
        lines: lines.map((line) => ({
          left: line.left,
          right: line.right,
          top: line.top,
          bottom: line.bottom,
        })),
        right: box.right,
        word,
      }
    },
    { widestId, ordinaryId, paragraphId },
  )
  expect(reading.widest, JSON.stringify(reading)).toBeGreaterThan(
    reading.ordinary,
  )
  expect(reading.lines.length, JSON.stringify(reading)).toBeGreaterThan(1)
  expect(reading.right - reading.lines[0]!.right).toBeLessThanOrEqual(
    reading.word + SLACK_PX,
  )
  return JSON.stringify(reading, null, 2)
}

/**
 * Prove a real table's title supplies the widest text and a survivor wraps.
 * Titles need no page-owned instrumentation: read their first visible text node
 * from the stable record selected by the spec, in either mounted projection.
 *
 * @param expect The spec's expect.
 * @param page The page containing the real table.
 * @param recordIds All record IDs, with the widest title first.
 * @param wrappingId The unchanged title that must actually wrap near its edge.
 * @returns Opaque fractional evidence of the title widths and rendered lines.
 */
export async function expectTableRecordWrappingStress(
  expect: Expect,
  page: Page,
  recordIds: readonly string[],
  wrappingId: string,
): Promise<string> {
  const reading = await page.evaluate(
    ({ recordIds, wrappingId }) => {
      function title(id: string) {
        const record = document.querySelector(`[data-id="${id}"]`)
        if (!record?.checkVisibility()) throw new Error(`No visible ${id}`)
        const walker = document.createTreeWalker(record, NodeFilter.SHOW_TEXT)
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
          const parent = node.parentElement
          if (!node.textContent?.trim() || !parent?.checkVisibility()) continue
          const range = document.createRange()
          range.selectNodeContents(node)
          const canvas = document.createElement('canvas').getContext('2d')!
          canvas.font = getComputedStyle(parent).font
          const text = node.textContent.trim()
          const container = parent.closest('td') ?? parent.parentElement!
          return {
            id,
            text,
            naturalWidth: canvas.measureText(text).width,
            word: Math.max(
              ...text
                .split(/\s+/)
                .map((word) => canvas.measureText(word).width),
            ),
            left:
              container.getBoundingClientRect().left +
              Number.parseFloat(getComputedStyle(container).paddingLeft || '0'),
            right:
              container.getBoundingClientRect().right -
              Number.parseFloat(
                getComputedStyle(container).paddingRight || '0',
              ),
            lines: [...range.getClientRects()].map((line) => ({
              top: line.top,
              bottom: line.bottom,
              left: line.left,
              right: line.right,
            })),
          }
        }
        throw new Error(`No title text in ${id}`)
      }
      return { titles: recordIds.map(title), wrapping: title(wrappingId) }
    },
    { recordIds, wrappingId },
  )
  const widest = reading.titles[0]!
  for (const other of reading.titles.slice(1)) {
    expect(widest.naturalWidth, JSON.stringify(reading)).toBeGreaterThan(
      other.naturalWidth,
    )
  }
  expect(
    reading.wrapping.lines.length,
    JSON.stringify(reading),
  ).toBeGreaterThan(1)
  for (const line of reading.wrapping.lines) {
    expect(line.left, JSON.stringify(reading)).toBeGreaterThanOrEqual(
      reading.wrapping.left - SLACK_PX,
    )
    expect(line.right, JSON.stringify(reading)).toBeLessThanOrEqual(
      reading.wrapping.right + SLACK_PX,
    )
  }
  expect(
    reading.wrapping.right -
      Math.max(...reading.wrapping.lines.map((line) => line.right)),
    JSON.stringify(reading),
  ).toBeLessThanOrEqual(reading.wrapping.word + SLACK_PX)
  return JSON.stringify(reading, null, 2)
}

/**
 * Follow one initially empty table through its first live action group.
 * Native bounds stay in the toolbox; selectors and ingress belong to the spec.
 *
 * @param expect The spec's expect.
 * @param page The fixture page.
 * @param bodyId The stable wrapper containing the mounted table.
 * @param actionIds The first row's action controls, in keyboard order.
 * @param controllerAttribute Optional fixture attribute exposing opaque controller identity.
 */
export async function watchTableFirstLive(
  expect: Expect,
  page: Page,
  bodyId: string,
  actionIds: readonly string[],
  controllerAttribute?: string,
) {
  const root = page.getByTestId(bodyId)
  const table = await root.locator('table').elementHandle()
  if (!table) throw new Error('Initially empty table is not mounted')
  const initial = await table.evaluate((table) => ({
    empty:
      table.querySelectorAll('tbody tr[data-id^="hilos-table-row-"]').length ===
      0,
    width:
      table.parentElement!.getBoundingClientRect().width / table.currentCSSZoom,
    fixed: table.classList.contains('hilos-table-fixed'),
  }))
  const observation = await table.evaluateHandle(
    (table, controllerAttribute) => {
      // Read the mounted renderer's existing props, as watchTableSearch does.
      // Keeping the object here observes replacement without a product test hook.
      interface VNode {
        readonly props?: { controller?: object }
        readonly el?: Node
        readonly component?: { subTree: VNode; isUnmounted: boolean }
        readonly suspense?: { activeBranch?: VNode }
        readonly children?: unknown
      }
      const currentController = () => {
        // Optimized kits without a browser component inspector can declare an
        // opaque identity on the test fixture wrapper. Read it on every frame.
        if (controllerAttribute) {
          return (
            table
              .closest(`[${controllerAttribute}]`)
              ?.getAttribute(controllerAttribute) ?? null
          )
        }
        let found: object | null = null
        const visit = (node: VNode | null | undefined): void => {
          if (!node || typeof node !== 'object' || found) return
          if (
            node.props?.controller &&
            node.el instanceof Element &&
            node.el.contains(table)
          )
            found = node.props.controller
          if (node.component && !node.component.isUnmounted)
            visit(node.component.subTree)
          else if (Array.isArray(node.children))
            for (const child of node.children) visit(child)
          visit(node.suspense?.activeBranch)
        }
        for (const root of document.querySelectorAll('[data-v-app]'))
          visit((root as Element & { _vnode?: VNode })._vnode)
        // React's committed host fiber links upward to the public table props.
        // Read its controller identity only; no SDK registry or test hook is needed.
        interface Fiber {
          readonly memoizedProps?: { controller?: object }
          readonly return?: Fiber
        }
        const key = Object.keys(table).find((key) =>
          key.startsWith('__reactFiber$'),
        )
        let fiber = key
          ? (table as unknown as Record<string, Fiber>)[key]
          : undefined
        while (!found && fiber) {
          found = fiber.memoizedProps?.controller ?? null
          fiber = fiber.return
        }
        return found
      }
      const controller = currentController()
      let running = true
      let frame = 0
      let liveFrames = 0
      let sameController = controller !== null
      let sameContainer = true
      let sameColumns = true
      let sameLayout = true
      const violations: unknown[] = []
      const frames: unknown[] = []
      const container = table.parentElement
      const columns = table.querySelector('colgroup')
      const layout = () => {
        const root = table.closest<HTMLElement>(
          '[data-id="hilos-viewport-table"]',
        )!
        const style = getComputedStyle(root)
        return [
          container!.getBoundingClientRect().width / table.currentCSSZoom,
          table.currentCSSZoom,
          style.fontFamily,
          style.fontSize,
          style.lineHeight,
          style.letterSpacing,
          style.wordSpacing,
          getComputedStyle(document.documentElement).fontSize,
          columns!.children.length,
        ].join('|')
      }
      const initialLayout = layout()
      const tracks = (selector: string) =>
        [...table.querySelectorAll(selector)].map((cell) => {
          const bounds = cell.getBoundingClientRect()
          return [bounds.left, bounds.right]
        })
      const sample = () => {
        if (!running) return
        const actions = []
        const rows = [
          ...table.querySelectorAll('tbody tr[data-id^="hilos-table-row-"]'),
        ]
        if (rows.length > 0) {
          liveFrames += 1
          for (const row of rows) {
            const marks = row.querySelector<HTMLElement>(
              'td:last-child .hilos-table-marks:not(.invisible)',
            )
            if (!marks) continue
            const state = marks.getBoundingClientRect()
            for (const action of row.querySelectorAll<HTMLElement>(
              'td button[data-id]',
            )) {
              if (!action.checkVisibility()) continue
              const box = action.getBoundingClientRect()
              const host = action.closest('td')!.getBoundingClientRect()
              const hit = document.elementFromPoint(
                (box.left + box.right) / 2,
                (box.top + box.bottom) / 2,
              )
              const overlap =
                Math.max(
                  0,
                  Math.min(box.right, state.right) -
                    Math.max(box.left, state.left),
                ) / action.currentCSSZoom
              const reading = {
                id: action.dataset.id,
                overlap,
                bounds: [box.left, box.top, box.right, box.bottom],
                cell: [host.left, host.top, host.right, host.bottom],
                marks: [state.left, state.top, state.right, state.bottom],
                insideMarks: marks.contains(action),
                hit: hit === action || action.contains(hit),
                interceptor: hit?.outerHTML.slice(0, 240),
              }
              actions.push(reading)
              if (
                // A state/detail button belongs inside its own state room. It
                // still has to fit its cell; page actions must stay outside marks.
                (!marks.contains(action) && overlap > 0) ||
                box.left < host.left - 1 ||
                box.right > host.right + 1
              ) {
                violations.push({
                  at: performance.now(),
                  ...reading,
                })
              }
            }
          }
        }
        sameController &&= currentController() === controller
        sameContainer &&= table.parentElement === container
        sameColumns &&= table.querySelector('colgroup') === columns
        sameLayout &&= layout() === initialLayout
        frames.push({
          at: performance.now(),
          connected: table.isConnected,
          sameController,
          sameContainer,
          sameColumns,
          sameLayout,
          zoom: table.currentCSSZoom,
          fixed: table.classList.contains('hilos-table-fixed'),
          tracks: tracks('thead th'),
          usedTracks: tracks('tbody tr[data-id^="hilos-table-row-"] > td'),
          actions,
        })
      }
      const tick = () => {
        sample()
        if (running) frame = requestAnimationFrame(tick)
      }
      const mutations = new MutationObserver(sample)
      mutations.observe(table, {
        subtree: true,
        childList: true,
        attributes: true,
        characterData: true,
      })
      sample()
      frame = requestAnimationFrame(tick)
      return {
        read: () => ({
          liveFrames,
          sameController,
          sameContainer,
          sameColumns,
          sameLayout,
          violations,
          frames,
        }),
        stop: () => {
          sample()
          running = false
          cancelAnimationFrame(frame)
          mutations.disconnect()
        },
      }
    },
    controllerAttribute,
  )
  async function read() {
    const layout = await table!.evaluate((table) => {
      const zoom = table.currentCSSZoom
      const rect = table.getBoundingClientRect()
      return {
        connected: table.isConnected,
        width: table.parentElement!.getBoundingClientRect().width / zoom,
        zoom,
        fixed: table.classList.contains('hilos-table-fixed'),
        tracks: [...table.querySelectorAll('thead th')].map((cell) => {
          const bounds = cell.getBoundingClientRect()
          return [(bounds.left - rect.left) / zoom, bounds.width / zoom]
        }),
        usedTracks: [
          ...table.querySelectorAll(
            'tbody tr[data-id^="hilos-table-row-"]:first-child td',
          ),
        ].map((cell) => {
          const bounds = cell.getBoundingClientRect()
          return [(bounds.left - rect.left) / zoom, bounds.width / zoom]
        }),
      }
    })
    const actions = []
    for (const id of actionIds) {
      const action = shownByTestId(page, id)
      await action.scrollIntoViewIfNeeded()
      actions.push(
        await action.evaluate((node) => {
          const cell = node.closest('td')!
          const row = cell.parentElement!
          const marks = row.querySelector<HTMLElement>(
            'td:last-child .hilos-table-marks:not(.invisible)',
          )!
          const zoom = node.currentCSSZoom
          const origin = row.getBoundingClientRect()
          const box = node.getBoundingClientRect()
          const host = cell.getBoundingClientRect()
          const state = marks.getBoundingClientRect()
          const hit = document.elementFromPoint(
            (box.left + box.right) / 2,
            (box.top + box.bottom) / 2,
          )
          return {
            id: node.getAttribute('data-id'),
            bounds: [box.left, box.top, box.right, box.bottom].map(
              (value, index) =>
                (value - (index % 2 ? origin.top : origin.left)) / zoom,
            ),
            cell: [
              (host.left - origin.left) / zoom,
              (host.right - origin.left) / zoom,
            ],
            marks: [
              (state.left - origin.left) / zoom,
              (state.right - origin.left) / zoom,
            ],
            fitsCell:
              box.left >= host.left - zoom && box.right <= host.right + zoom,
            overlap:
              Math.max(
                0,
                Math.min(box.right, state.right) -
                  Math.max(box.left, state.left),
              ) / zoom,
            hit: hit === node || node.contains(hit),
            interceptor: hit?.outerHTML.slice(0, 240),
          }
        }),
      )
    }
    return {
      initial,
      layout,
      actions,
      observation: await observation.evaluate((watch) => watch.read()),
    }
  }
  let latest: Awaited<ReturnType<typeof read>> | undefined
  return {
    async evidence() {
      latest = await read()
      return JSON.stringify(latest, null, 2)
    },
    async check() {
      const result = latest ?? (await read())
      expect(result.initial.empty, 'the original mounted body was empty').toBe(
        true,
      )
      expect(result.initial.fixed, 'empty headers do not latch tracks').toBe(
        false,
      )
      expect(result.layout.connected, 'the original table mount survives').toBe(
        true,
      )
      expect(
        result.observation.sameController,
        'the original controller survives',
      ).toBe(true)
      expect(
        result.observation.sameContainer,
        'the original container survives',
      ).toBe(true)
      expect(
        result.observation.sameColumns,
        'the original colgroup survives',
      ).toBe(true)
      expect(
        result.observation.sameLayout,
        'the original layout condition survives',
      ).toBe(true)
      expect(result.layout.width).toBe(result.initial.width)
      expect(
        result.layout.fixed,
        'first live content calibrates fixed tracks',
      ).toBe(true)
      expect(result.layout.usedTracks).toHaveLength(result.layout.tracks.length)
      for (const [index, track] of result.layout.tracks.entries()) {
        const used = result.layout.usedTracks[index]!
        expect(Math.abs(track[0]! - used[0]!)).toBeLessThanOrEqual(SLACK_PX)
        expect(Math.abs(track[1]! - used[1]!)).toBeLessThanOrEqual(SLACK_PX)
      }
      expect(
        result.observation.liveFrames,
        'real live frames were observed',
      ).toBeGreaterThan(0)
      expect(
        result.observation.violations,
        'no rendered live frame covers an action',
      ).toEqual([])
      for (const action of result.actions) {
        expect(action.fitsCell, `${action.id} fits its live column`).toBe(true)
        expect(action.overlap, `${action.id} is clear of state marks`).toBe(0)
        expect(action.hit, `${action.id}: ${action.interceptor}`).toBe(true)
      }
    },
    async history() {
      return JSON.stringify(
        {
          initial,
          observation: await observation.evaluate((watch) => watch.read()),
        },
        null,
        2,
      )
    },
    async stop() {
      await observation.evaluate((watch) => watch.stop())
      await observation.dispose()
      await table.dispose()
    },
  }
}
