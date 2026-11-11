import type { CDPSession, JSHandle, Locator, Page } from '@playwright/test'

// A person's scroll, for the spec that has to prove what a wheel or a finger
// moves: the page, the scroll container under the point, or nothing at all. A
// DOM scroll (`scrollTop`, `scrollIntoView`) moves a box whatever the page has
// asked the browser to refuse, and a dispatched `WheelEvent` or `TouchEvent` is
// never turned into a scroll; only input that enters through the browser walks
// the scroll chain a person's gesture walks. Chromium takes such input over the
// DevTools protocol (https://chromedevtools.github.io/devtools-protocol/tot/Input/):
// the wheel through Input.synthesizeScrollGesture, turned at one point, and the
// finger through Input.dispatchTouchEvent, dragged from it.
//
// Why the finger does not take synthesizeScrollGesture too: the headless
// Chromium 156 of the suites' Playwright sends only the touchstart and the
// touchend of such a gesture — no touchmove reaches the page and nothing
// scrolls. The drag is therefore sent one touch point at a time, each stamped
// at the gesture's speed, and the finger lifts once it has come to rest: a
// finger lifted while still moving makes Chromium fling the content on after
// the gesture is over, which is what synthesizeScrollGesture's preventFling
// keeps from the wheel.
//
// The helper aims, sends one short gesture, waits for the browser to finish it
// and reads the scroll counters before and after — the document's and those of
// the scroll container under the point. It owns no scenario and passes no
// verdict: the spec decides what had to move. Only the point is measured here,
// so a spec never takes a box itself.

/** Where a native scroll gesture comes from: a mouse wheel or one finger. */
export type ScrollGestureSource = 'mouse' | 'touch'

/**
 * The scroll counters around one gesture. These are scroll positions, never
 * boxes: `x` is how far a box is scrolled sideways and `y` how far down.
 */
export interface ScrollGestureResult {
  /** The document's scroll position just before the gesture. */
  documentBefore: { x: number; y: number }
  /** The document's scroll position once the gesture and a frame are over. */
  documentAfter: { x: number; y: number }
  /**
   * The position of the nearest scroll container around the target, inside the
   * page, before the gesture; null when the target lies in none.
   */
  contentBefore: { x: number; y: number } | null
  /** The same container's position once the gesture and a frame are over. */
  contentAfter: { x: number; y: number } | null
}

/** How far inside the target's top-left corner a gesture aimed there starts. */
const CORNER_INSET_PX = 8

/**
 * How far a finger moves before Chromium takes its drag for a scroll — the
 * touch slop. The drag travels this much beyond the distance asked, and its
 * path needs room for it on the screen.
 */
const TOUCH_SLOP_PX = 16

/** The gesture's speed: a short flick of a person, not a slow pull. */
const GESTURE_SPEED_PX_PER_SECOND = 1200

/** How far a finger moves between two touch points of its drag. */
const TOUCH_STEP_PX = 10

/**
 * How long a finger rests before it lifts, in seconds — longer than Chromium
 * takes a pointer that has stopped to have stopped, so the drag ends without a
 * fling.
 */
const TOUCH_REST_SECONDS = 0.2

/**
 * Scroll by a native gesture over the target and report what moved.
 *
 * The point is the middle of the target's visible part, or its top-left corner
 * a few pixels inside, and must be on screen with the target itself under it —
 * nothing drawn over it — or the gesture is refused before it is sent. The
 * finger's path, or the wheel's equal distance, must stay on screen as well:
 * the gesture is short, and a spec that needs it longer repeats it.
 *
 * @param page The page the gesture is sent to.
 * @param target The element the gesture starts over.
 * @param options How the gesture is made: `source` is the device, `deltaY` the
 *   distance in CSS pixels the content is asked to scroll — positive down,
 *   negative up — and `at` where on the target it starts (the middle when
 *   omitted).
 * @returns The document's scroll position and that of the scroll container
 *   around the target, both read before the gesture and after it is done.
 */
export async function scrollWithGesture(
  page: Page,
  target: Locator,
  options: {
    source: ScrollGestureSource
    deltaY: number
    at?: 'center' | 'top-left'
  },
): Promise<ScrollGestureResult> {
  const { source, deltaY, at = 'center' } = options
  if (!Number.isFinite(deltaY) || deltaY === 0) {
    throw new Error(
      `${target.toString()}: a scroll gesture needs a finite, nonzero deltaY, got ${deltaY}`,
    )
  }
  const point = await target.evaluate(
    (node, { at, corner, travel, what }) => {
      if (!node.checkVisibility({ visibilityProperty: true })) {
        throw new Error(`${what}: the element is not visible`)
      }
      const root = document.documentElement
      const box = node.getBoundingClientRect()
      let left = Math.max(box.left, 0)
      let top = Math.max(box.top, 0)
      let right = Math.min(box.right, root.clientWidth)
      let bottom = Math.min(box.bottom, root.clientHeight)
      // Clip by every box between the target and the page that cuts what
      // overflows it; a fixed box is clipped by the window alone.
      let fixed = getComputedStyle(node).position === 'fixed'
      for (
        let parent = node.parentElement;
        parent && !fixed && parent !== document.body && parent !== root;
        parent = parent.parentElement
      ) {
        const style = getComputedStyle(parent)
        fixed = style.position === 'fixed'
        const bounds = parent.getBoundingClientRect()
        if (style.overflowX !== 'visible') {
          const start = bounds.left + parent.clientLeft
          left = Math.max(left, start)
          right = Math.min(right, start + parent.clientWidth)
        }
        if (style.overflowY !== 'visible') {
          const start = bounds.top + parent.clientTop
          top = Math.max(top, start)
          bottom = Math.min(bottom, start + parent.clientHeight)
        }
      }
      const x = at === 'center' ? (left + right) / 2 : left + corner
      const y = at === 'center' ? (top + bottom) / 2 : top + corner
      if (x >= right || y >= bottom) {
        throw new Error(
          `${what}: no visible ${at} point on screen; visible part ${JSON.stringify({ left, top, right, bottom })}`,
        )
      }
      const hit = document.elementFromPoint(x, y)
      if (hit === null || !node.contains(hit)) {
        const id = hit?.getAttribute('data-id')
        const name = hit?.tagName.toLowerCase() ?? 'nothing'
        throw new Error(
          `${what}: ${id ? `${name}[data-id="${id}"]` : name} lies over its ${at} point (${x}, ${y})`,
        )
      }
      // A finger moving up scrolls the content down.
      const room = travel > 0 ? y : root.clientHeight - y
      if (Math.abs(travel) >= room) {
        throw new Error(
          `${what}: a ${Math.abs(travel)}px drag from (${x}, ${y}) leaves the window, which has ${room}px that way`,
        )
      }

      return { x, y }
    },
    {
      at,
      corner: CORNER_INSET_PX,
      travel: deltaY + Math.sign(deltaY) * TOUCH_SLOP_PX,
      what: target.toString(),
    },
  )
  const content = await target.evaluateHandle((node) => {
    for (
      let box: Element | null = node;
      box && box !== document.body && box !== document.documentElement;
      box = box.parentElement
    ) {
      const overflow = getComputedStyle(box).overflowY
      if (
        (overflow === 'auto' || overflow === 'scroll') &&
        box.scrollHeight > box.clientHeight
      ) {
        return box
      }
    }

    return null
  })
  try {
    const before = await readScroll(page, content)
    const session = await page.context().newCDPSession(page)
    try {
      if (source === 'mouse') {
        await session.send('Input.synthesizeScrollGesture', {
          x: point.x,
          y: point.y,
          xDistance: 0,
          yDistance: -deltaY,
          speed: GESTURE_SPEED_PX_PER_SECOND,
          gestureSourceType: source,
          preventFling: true,
        })
      } else {
        await dragFinger(session, point, deltaY)
      }
    } finally {
      await session.detach()
    }
    // The command answers when the input is over; the scroll it caused reaches
    // the page's counters with the frames that follow.
    await page.evaluate(
      () =>
        new Promise<void>((resolve) =>
          requestAnimationFrame(() => requestAnimationFrame(() => resolve())),
        ),
    )
    const after = await readScroll(page, content)

    return {
      documentBefore: before.document,
      documentAfter: after.document,
      contentBefore: before.content,
      contentAfter: after.content,
    }
  } finally {
    await content.dispose()
  }
}

/**
 * Drag one finger vertically from the point and lift it at rest.
 *
 * Each touch point waits for the browser to take it before the next is sent,
 * and carries the moment it would have at the gesture's speed, so the browser
 * reads the drag's pace from the points rather than from how fast they came.
 *
 * @param session The page's DevTools session.
 * @param point Where the finger touches down, in CSS pixels of the window.
 * @param deltaY How far the content is asked to scroll — positive down.
 */
async function dragFinger(
  session: CDPSession,
  point: { x: number; y: number },
  deltaY: number,
): Promise<void> {
  // A finger moving up scrolls the content down; the slop moves no content.
  const travel = deltaY + Math.sign(deltaY) * TOUCH_SLOP_PX
  const steps = Math.ceil(Math.abs(travel) / TOUCH_STEP_PX)
  const start = Date.now() / 1000
  await session.send('Input.dispatchTouchEvent', {
    type: 'touchStart',
    touchPoints: [point],
    timestamp: start,
  })
  for (let step = 1; step <= steps; step++) {
    const moved = (travel * step) / steps
    await session.send('Input.dispatchTouchEvent', {
      type: 'touchMove',
      touchPoints: [{ x: point.x, y: point.y - moved }],
      timestamp: start + Math.abs(moved) / GESTURE_SPEED_PX_PER_SECOND,
    })
  }
  await session.send('Input.dispatchTouchEvent', {
    type: 'touchEnd',
    touchPoints: [],
    timestamp:
      start +
      Math.abs(travel) / GESTURE_SPEED_PX_PER_SECOND +
      TOUCH_REST_SECONDS,
  })
}

/** Read the document's and the container's scroll counters in one pass. */
async function readScroll(
  page: Page,
  content: JSHandle<Element | null>,
): Promise<{
  document: { x: number; y: number }
  content: { x: number; y: number } | null
}> {
  return page.evaluate(
    (box) => ({
      document: { x: window.scrollX, y: window.scrollY },
      content: box ? { x: box.scrollLeft, y: box.scrollTop } : null,
    }),
    content,
  )
}
