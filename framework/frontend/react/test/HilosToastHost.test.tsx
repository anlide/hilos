import { createHilosToastStore, createSignal } from '@hilos/core'
import type {
  HilosRouter,
  HilosSessionToast,
  HilosToastSeverity,
  HilosToastStore,
  PageRouteMatch,
} from '@hilos/core'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import type { RenderResult } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { HilosToastHost } from '../src/HilosToastHost.js'
import type { HilosToastCorner } from '../src/hilosToastCorner.js'

// The host is the only place the hold events, the measurement reports and the
// live-region wiring are written down, so this covers them once for the three
// SDKs — the store's own behavior (lifetimes, holds, the height cap) is a core
// unit test. It also carries the card's form for the Angular host, which has no
// component test of its own: ng test is blocked upstream and the Angular SDK
// project is a node environment without jsdom (HIL-491).
function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
}

/** The one card of the session used by the cases about a signed, leading notice. */
function sessionToast(): HilosSessionToast {
  return {
    key: 'toast-key',
    message: 'The export is ready',
    severity: 'info',
    source: 'Backup',
    destination: '/hilos/backup',
    repeats: 1,
  }
}

/**
 * A router that only records where it was asked to go.
 *
 * It has to be there for the click cases at all: HilosLink hands the plain
 * click to the navigator and swallows the event only then, and the swallow is
 * what tells the host the reader left the page.
 *
 * @param visited The paths the link navigated to, in order.
 */
function router(visited: string[]): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: 'user',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: (path: string) => {
      visited.push(path)
    },
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/**
 * Render a host over one stack of its own, with a router behind its links.
 *
 * @param store The stack to render.
 * @param visited The paths its links navigated to, in order.
 * @param corner The corner the shell chose, if it chose one.
 */
function renderHost(
  store: HilosToastStore,
  visited: string[] = [],
  corner?: HilosToastCorner,
): RenderResult {
  return render(
    <HilosRouterContext.Provider value={router(visited)}>
      <HilosToastHost store={store} corner={corner} />
    </HilosRouterContext.Provider>,
  )
}

/**
 * The same stack, with a lens on the frame in which the host reports a height.
 *
 * React flushes the effects before render() returns, so the frame before the
 * measurement cannot be read out of the DOM afterwards. The host reports from
 * that very frame though — the card is committed and the store has not been
 * told yet — so the callback runs with exactly the DOM in question on screen.
 *
 * @param store The stack to watch.
 * @param seen Called once, at the first height report.
 */
function watchFirstReport(
  store: HilosToastStore,
  seen: () => void,
): HilosToastStore {
  let reported = false

  return {
    ...store,
    attach: () => {
      const viewer = store.attach()

      return {
        ...viewer,
        reportHeight: (id: number, pixels: number) => {
          if (!reported) {
            reported = true
            seen()
          }
          viewer.reportHeight(id, pixels)
        },
      }
    },
  }
}

/**
 * Switch the tab away or back, the way the browser reports it.
 *
 * @param hidden Whether the document is now hidden.
 */
function switchTab(hidden: boolean): void {
  Object.defineProperty(document, 'hidden', {
    configurable: true,
    get: () => hidden,
  })
  fireEvent(document, new Event('visibilitychange'))
}

/** What names each severity on the card: the rail, the icon, the accent. */
const VISUALS: [HilosToastSeverity, string, string, string][] = [
  ['error', 'border-danger', 'bi-x-circle-fill', 'text-danger'],
  ['success', 'border-success', 'bi-check-circle-fill', 'text-success'],
  ['warning', 'border-warning', 'bi-exclamation-triangle-fill', 'text-warning'],
  ['info', 'border-primary', 'bi-info-circle-fill', 'text-primary'],
]

describe('HilosToastHost', () => {
  afterEach(() => {
    cleanup()
    switchTab(false)
  })

  it('reports the height of every card it drew, so the store can admit it', () => {
    const store = createHilosToastStore()
    store.push('Backup created.', { severity: 'success' })
    render(<HilosToastHost store={store} />)

    expect(store.toasts.get()[0].measured).toBe(true)
  })

  it('holds the countdown while the tab is not the one being looked at', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup created.', { severity: 'success' })
      const { container } = render(<HilosToastHost store={store} />)

      switchTab(true)
      act(() => {
        vi.advanceTimersByTime(60_000)
      })
      expect(byId(container, 'hilos-toast-success')).not.toBeNull()

      switchTab(false)
      act(() => {
        vi.advanceTimersByTime(20_000)
      })
      expect(byId(container, 'hilos-toast-success')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('stops the countdown once it is no longer there to watch the stack', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup created.', { severity: 'success' })
      const { unmount } = render(<HilosToastHost store={store} />)

      unmount()
      act(() => {
        vi.advanceTimersByTime(60_000)
      })

      expect(store.toasts.get()).toHaveLength(1)
    } finally {
      vi.useRealTimers()
    }
  })

  it('holds the countdown while the cursor rests on the stack', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup created.', { severity: 'success' })
      const { container } = render(<HilosToastHost store={store} />)
      const stack = byId(container, 'hilos-toasts') as HTMLElement

      // mouseover fires again for every element the cursor crosses inside the
      // stack, so the host takes at most one hold and a single leave gives it
      // back — otherwise the surplus would freeze the stack for good.
      fireEvent.mouseOver(stack)
      fireEvent.mouseOver(stack)
      act(() => {
        vi.advanceTimersByTime(60_000)
      })
      expect(byId(container, 'hilos-toast-success')).not.toBeNull()

      fireEvent.mouseLeave(stack)
      act(() => {
        vi.advanceTimersByTime(20_000)
      })
      expect(byId(container, 'hilos-toast-success')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('names a severity with a rail and an icon, not with a filled surface', () => {
    const store = createHilosToastStore()
    for (const [severity] of VISUALS) {
      store.push(`${severity} happened`, { severity })
    }
    const { container } = renderHost(store)

    for (const [severity, rail, icon, accent] of VISUALS) {
      const card = byId(container, `hilos-toast-${severity}`) as HTMLElement
      expect(card.className).not.toContain('text-bg-danger')
      expect(card.querySelector(`.border-start.${rail}`)).not.toBeNull()
      const glyph = card.querySelector(`i.${icon}`) as HTMLElement
      expect(glyph.className).toContain(accent)
      expect(glyph.getAttribute('aria-hidden')).toBe('true')
    }
  })

  it('signs a background notice and leaves the connection its own', () => {
    const store = createHilosToastStore()
    store.syncSession([sessionToast()])
    store.push('Saved', { severity: 'success' })
    const { container } = renderHost(store)

    const background = byId(container, 'hilos-toast-info') as HTMLElement
    expect(byId(background, 'hilos-toast-source')?.textContent).toBe('Backup')
    const own = byId(container, 'hilos-toast-success') as HTMLElement
    expect(byId(own, 'hilos-toast-source')).toBeNull()
  })

  it('counts a repeat on the card instead of drawing a second one', () => {
    const store = createHilosToastStore()
    store.push('Nothing to send', { severity: 'warning' })
    const { container } = renderHost(store)
    expect(byId(container, 'hilos-toast-repeats')).toBeNull()

    act(() => {
      store.push('Nothing to send', { severity: 'warning' })
      store.push('Nothing to send', { severity: 'warning' })
    })

    expect(
      container.querySelectorAll('[data-id="hilos-toast-warning"]'),
    ).toHaveLength(1)
    expect(byId(container, 'hilos-toast-repeats')?.textContent).toBe('×3')
  })

  it('makes the whole card a link only when the notice leads somewhere', () => {
    const store = createHilosToastStore()
    store.syncSession([sessionToast()])
    store.push('Saved', { severity: 'success' })
    const { container } = renderHost(store)

    const link = container.querySelector(
      '[data-id="hilos-toast-info"] a',
    ) as HTMLElement
    expect(link.className).toContain('stretched-link')
    expect(link.getAttribute('href')).toBe('/hilos/backup')
    expect(
      container.querySelector('[data-id="hilos-toast-success"] a'),
    ).toBeNull()
  })

  it('closes the card whose link took the reader away', () => {
    const store = createHilosToastStore()
    const visited: string[] = []
    store.syncSession([sessionToast()])
    const { container } = renderHost(store, visited)

    fireEvent.click(
      container.querySelector('[data-id="hilos-toast-info"] a') as HTMLElement,
    )

    expect(visited).toEqual(['/hilos/backup'])
    // Reading it by following the link is closing it, and closing a card of the
    // session is an answer rather than a removal (HIL-768): it leaves every tab
    // on the frame that follows, or leaves none of them.
    expect(store.dismissedSessionKeys.get()).toEqual(['toast-key'])
    expect(store.toasts.get()).toHaveLength(1)
  })

  it('keeps the card when the click only opened another tab', () => {
    const store = createHilosToastStore()
    const visited: string[] = []
    store.syncSession([sessionToast()])
    const { container } = renderHost(store, visited)

    fireEvent.click(
      container.querySelector('[data-id="hilos-toast-info"] a') as HTMLElement,
      { ctrlKey: true },
    )

    expect(visited).toEqual([])
    expect(store.toasts.get()).toHaveLength(1)
  })

  it('gives a card its life bar only once its height has been reported', () => {
    const store = createHilosToastStore()
    store.push('Saved', { severity: 'success' })
    let cardBeforeMeasurement: Element | null = null
    let barBeforeMeasurement: Element | null = null
    const { container } = renderHost(
      watchFirstReport(store, () => {
        cardBeforeMeasurement = document.querySelector(
          '[data-id="hilos-toast-success"]',
        )
        barBeforeMeasurement = document.querySelector(
          '[data-id="hilos-toast-life"]',
        )
      }),
    )

    // The card is drawn before it is measured — unmeasured it has no countdown
    // started, so it has no bar either — and the bar arrives with the render
    // that follows the report, which is the order on screen too.
    expect(cardBeforeMeasurement).not.toBeNull()
    expect(barBeforeMeasurement).toBeNull()
    expect(store.toasts.get()[0].measured).toBe(true)
    const bar = byId(container, 'hilos-toast-life') as HTMLElement
    expect(bar.className).toContain('text-success')
    expect(bar.className).not.toContain('hilos-toast-life-paused')
  })

  it('draws no life bar on an error, because an error does not expire', () => {
    const store = createHilosToastStore()
    store.push('The report could not be built', { severity: 'error' })
    const { container } = renderHost(store)

    expect(store.toasts.get()[0].measured).toBe(true)
    expect(byId(container, 'hilos-toast-life')).toBeNull()
  })

  it('stands the bar still while the cursor holds the countdown', () => {
    const store = createHilosToastStore()
    store.push('Saved', { severity: 'success' })
    const { container } = renderHost(store)
    const stack = byId(container, 'hilos-toasts') as HTMLElement

    fireEvent.mouseOver(stack)
    expect(
      (byId(container, 'hilos-toast-life') as HTMLElement).className,
    ).toContain('hilos-toast-life-paused')

    fireEvent.mouseLeave(stack)
    expect(
      (byId(container, 'hilos-toast-life') as HTMLElement).className,
    ).not.toContain('hilos-toast-life-paused')
  })

  it('sits in the bottom end corner until a shell moves it', () => {
    const bottomEnd = byId(
      renderHost(createHilosToastStore()).container,
      'hilos-toasts',
    ) as HTMLElement
    expect(bottomEnd.className).toContain('end-0')
    expect(bottomEnd.className).toContain('hilos-toast-stack-bottom')

    const topStart = byId(
      renderHost(createHilosToastStore(), [], 'top-start').container,
      'hilos-toasts',
    ) as HTMLElement
    expect(topStart.className).toContain('start-0')
    expect(topStart.className).toContain('hilos-toast-stack-top')
  })

  it('announces a measured notice, an error apart from the rest', () => {
    const store = createHilosToastStore()
    store.push('The report could not be built', { severity: 'error' })
    store.syncSession([sessionToast()])
    const { container } = renderHost(store)

    // Both regions exist from the first frame — that is the point of declaring
    // them in advance — and a background notice names its sender there, the way
    // its card signs itself.
    expect(byId(container, 'hilos-toast-live-assertive')?.textContent).toBe(
      'The report could not be built',
    )
    expect(byId(container, 'hilos-toast-live-polite')?.textContent).toBe(
      'Backup: The export is ready',
    )
  })

  it('keeps the live regions outside the stack container', () => {
    const store = createHilosToastStore()
    store.push('Saved', { severity: 'success' })
    const { container } = renderHost(store)

    // Inside, they would double every visible line, and the demo suites look a
    // notice up by its text within the container.
    expect(
      container.querySelector('[data-id="hilos-toasts"] [aria-live]'),
    ).toBeNull()
    expect((byId(container, 'hilos-toasts') as HTMLElement).textContent).toBe(
      'Saved',
    )
  })

  it('tells the store which hold it is taking, not just that it took one', () => {
    const store = createHilosToastStore()
    const { container } = render(<HilosToastHost store={store} />)
    const stack = byId(container, 'hilos-toasts') as HTMLElement

    fireEvent.mouseOver(stack)
    expect(store.reading.get()).toBe(true)

    fireEvent.mouseLeave(stack)
    expect(store.reading.get()).toBe(false)

    // A hidden tab freezes the countdown like the other two and is reported like
    // neither: nobody is reading it, and a background tab of the admin panel that
    // said otherwise would make every toast immortal in the window in use.
    switchTab(true)
    expect(store.reading.get()).toBe(false)
    switchTab(false)
  })

  it('gives back the cursor hold when the toast under it closes itself', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup failed.', { severity: 'error' })
      store.push('Backup created.', { severity: 'success' })
      const { container } = render(<HilosToastHost store={store} />)

      // The cursor rests on the stack and the notice under it takes itself out of
      // the DOM: the browser reports no mouseleave for it and never makes one up,
      // so unless the host gives the hold back by hand the stack stays frozen for
      // good. What is still on screen keeps its own hold, re-taken from the
      // mouseover the browser does send for the notice sliding underneath.
      fireEvent.mouseOver(byId(container, 'hilos-toasts') as HTMLElement)
      fireEvent.click(byId(container, 'hilos-toast-close') as HTMLElement)

      act(() => {
        vi.advanceTimersByTime(20_000)
      })
      expect(store.toasts.get()).toEqual([])
      expect(byId(container, 'hilos-toast-success')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('gives back the focus hold when a close button removes itself', () => {
    vi.useFakeTimers()
    try {
      const store = createHilosToastStore()
      store.push('Backup failed.', { severity: 'error' })
      store.push('Backup created.', { severity: 'success' })
      const { container } = render(<HilosToastHost store={store} />)

      // Keyboard focus reaches a close button, and then that button takes itself
      // out of the DOM: the browser reports no focusout for it, so unless the host
      // gives the hold back by hand the stack stays frozen for good.
      fireEvent.focusIn(byId(container, 'hilos-toasts') as HTMLElement)
      fireEvent.click(byId(container, 'hilos-toast-close') as HTMLElement)

      act(() => {
        vi.advanceTimersByTime(20_000)
      })
      expect(store.toasts.get()).toEqual([])
      expect(byId(container, 'hilos-toast-success')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('closes a notice from its close button', () => {
    const store = createHilosToastStore()
    store.push('Backup created.', { severity: 'success' })
    const { container } = render(<HilosToastHost store={store} />)

    fireEvent.click(byId(container, 'hilos-toast-close') as HTMLElement)

    expect(byId(container, 'hilos-toast-success')).toBeNull()
    expect(store.toasts.get()).toEqual([])
  })
})
