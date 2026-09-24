import type { Locator, Page } from '@playwright/test'

import { ROWS, TABLE } from './table.js'

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

  /** Only this module's three measurement functions can take a bookmark. */
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

/** Take a bookmark of the room the element occupies vertically. */
export async function watchHeight(element: Locator): Promise<Watched> {
  const what = `${element.toString()} height`

  return take(what, () => measureBox(element, 'height', what))
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

/** Read a box field, refusing an absent box on both the first and later reads. */
async function measureBox(
  element: Locator,
  field: 'y' | 'height',
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
