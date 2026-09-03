import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import { createHilosToastStore, createSignal } from '@hilos/core'
import type {
  HilosRouter,
  HilosSessionToast,
  HilosToastSeverity,
  HilosToastStore,
  PageRouteMatch,
} from '@hilos/core'

import HilosToastHost from './HilosToastHost.vue'
import type { HilosToastCorner } from './hilosToastCorner.js'
import { hilosRouterKey } from './hilosRouterKey.js'

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

// Every host is given back after its test: while it is mounted it holds a
// viewer on the store, and with it the countdown timers of the cards on screen.
const mounted: VueWrapper[] = []

afterEach(() => {
  while (mounted.length > 0) {
    mounted.pop()?.unmount()
  }
})

/**
 * Mount a host over one stack of its own.
 *
 * @param store The stack to render.
 * @param visited The paths its links navigated to, in order.
 * @param corner The corner the shell chose, if it chose one.
 */
function mountHost(
  store: HilosToastStore,
  visited: string[] = [],
  corner?: HilosToastCorner,
): VueWrapper {
  const wrapper = mount(HilosToastHost, {
    props: { store, corner },
    global: { provide: { [hilosRouterKey as symbol]: router(visited) } },
  })
  mounted.push(wrapper)

  return wrapper
}

/** What names each severity on the card: the rail, the icon, the accent. */
const VISUALS: [HilosToastSeverity, string, string, string][] = [
  ['error', 'border-danger', 'bi-x-circle-fill', 'text-danger'],
  ['success', 'border-success', 'bi-check-circle-fill', 'text-success'],
  ['warning', 'border-warning', 'bi-exclamation-triangle-fill', 'text-warning'],
  ['info', 'border-primary', 'bi-info-circle-fill', 'text-primary'],
]

describe('HilosToastHost', () => {
  it('names a severity with a rail and an icon, not with a filled surface', async () => {
    const store = createHilosToastStore()
    const wrapper = mountHost(store)

    for (const [severity] of VISUALS) {
      store.push(`${severity} happened`, { severity })
    }
    await nextTick()

    for (const [severity, rail, icon, accent] of VISUALS) {
      const card = wrapper.find(`[data-id="hilos-toast-${severity}"]`)
      expect(card.classes()).not.toContain('text-bg-danger')
      expect(card.find(`.border-start.${rail}`).exists()).toBe(true)
      expect(card.find(`i.${icon}`).classes()).toContain(accent)
      expect(card.find(`i.${icon}`).attributes('aria-hidden')).toBe('true')
    }
  })

  it('signs a background notice and leaves the connection its own', async () => {
    const store = createHilosToastStore()
    const wrapper = mountHost(store)

    store.syncSession([sessionToast()])
    store.push('Saved', { severity: 'success' })
    await nextTick()

    const background = wrapper.find('[data-id="hilos-toast-info"]')
    expect(background.find('[data-id="hilos-toast-source"]').text()).toBe(
      'Backup',
    )
    const own = wrapper.find('[data-id="hilos-toast-success"]')
    expect(own.find('[data-id="hilos-toast-source"]').exists()).toBe(false)
  })

  it('counts a repeat on the card instead of drawing a second one', async () => {
    const store = createHilosToastStore()
    const wrapper = mountHost(store)

    store.push('Nothing to send', { severity: 'warning' })
    await nextTick()
    expect(wrapper.find('[data-id="hilos-toast-repeats"]').exists()).toBe(false)

    store.push('Nothing to send', { severity: 'warning' })
    store.push('Nothing to send', { severity: 'warning' })
    await nextTick()

    expect(wrapper.findAll('[data-id="hilos-toast-warning"]')).toHaveLength(1)
    expect(wrapper.find('[data-id="hilos-toast-repeats"]').text()).toBe('×3')
  })

  it('makes the whole card a link only when the notice leads somewhere', async () => {
    const store = createHilosToastStore()
    const wrapper = mountHost(store)

    store.syncSession([sessionToast()])
    store.push('Saved', { severity: 'success' })
    await nextTick()

    const link = wrapper.find('[data-id="hilos-toast-info"] a')
    expect(link.classes()).toContain('stretched-link')
    expect(link.attributes('href')).toBe('/hilos/backup')
    expect(wrapper.find('[data-id="hilos-toast-success"] a').exists()).toBe(
      false,
    )
  })

  it('closes the card whose link took the reader away', async () => {
    const store = createHilosToastStore()
    const visited: string[] = []
    const wrapper = mountHost(store, visited)

    store.syncSession([sessionToast()])
    await nextTick()
    await wrapper.find('[data-id="hilos-toast-info"] a').trigger('click')

    expect(visited).toEqual(['/hilos/backup'])
    // Reading it by following the link is closing it, and closing a card of the
    // session is an answer rather than a removal (HIL-768): it leaves every tab
    // on the frame that follows, or leaves none of them.
    expect(store.dismissedSessionKeys.get()).toEqual(['toast-key'])
    expect(store.toasts.get()).toHaveLength(1)
  })

  it('keeps the card when the click only opened another tab', async () => {
    const store = createHilosToastStore()
    const visited: string[] = []
    const wrapper = mountHost(store, visited)

    store.syncSession([sessionToast()])
    await nextTick()
    await wrapper
      .find('[data-id="hilos-toast-info"] a')
      .trigger('click', { ctrlKey: true })

    expect(visited).toEqual([])
    expect(store.toasts.get()).toHaveLength(1)
  })

  it('gives a card its life bar only once its height has been reported', async () => {
    const store = createHilosToastStore()
    store.push('Saved', { severity: 'success' })
    const wrapper = mountHost(store)

    // The first frame is the card as the store still had it when it rendered:
    // unmeasured, with no countdown started and so no bar. The host reports the
    // height from its mounted hook, and the bar arrives with the next render —
    // which is the order on screen too.
    expect(wrapper.find('[data-id="hilos-toast-life"]').exists()).toBe(false)

    await nextTick()
    expect(store.toasts.get()[0].measured).toBe(true)
    const bar = wrapper.find('[data-id="hilos-toast-life"]')
    expect(bar.classes()).toContain('text-success')
    expect(bar.classes()).not.toContain('hilos-toast-life-paused')
  })

  it('draws no life bar on an error, because an error does not expire', async () => {
    const store = createHilosToastStore()
    store.push('The report could not be built', { severity: 'error' })
    const wrapper = mountHost(store)
    await nextTick()

    expect(store.toasts.get()[0].measured).toBe(true)
    expect(wrapper.find('[data-id="hilos-toast-life"]').exists()).toBe(false)
  })

  it('stands the bar still while the cursor holds the countdown', async () => {
    const store = createHilosToastStore()
    store.push('Saved', { severity: 'success' })
    const wrapper = mountHost(store)
    await nextTick()

    const stack = wrapper.find('[data-id="hilos-toasts"]')
    await stack.trigger('mouseover')
    expect(wrapper.find('[data-id="hilos-toast-life"]').classes()).toContain(
      'hilos-toast-life-paused',
    )

    await stack.trigger('mouseleave')
    expect(
      wrapper.find('[data-id="hilos-toast-life"]').classes(),
    ).not.toContain('hilos-toast-life-paused')
  })
  it('tells the store which hold it is taking, not just that it took one', async () => {
    const store = createHilosToastStore()
    const wrapper = mountHost(store)
    await nextTick()
    const stack = wrapper.find('[data-id="hilos-toasts"]')

    await stack.trigger('mouseover')
    expect(store.reading.get()).toBe(true)

    await stack.trigger('mouseleave')
    expect(store.reading.get()).toBe(false)
  })

  it('sits in the bottom end corner until a shell moves it', () => {
    const store = createHilosToastStore()
    const bottomEnd = mountHost(store).find('[data-id="hilos-toasts"]')
    expect(bottomEnd.classes()).toContain('end-0')
    expect(bottomEnd.classes()).toContain('hilos-toast-stack-bottom')

    const topStart = mountHost(createHilosToastStore(), [], 'top-start').find(
      '[data-id="hilos-toasts"]',
    )
    expect(topStart.classes()).toContain('start-0')
    expect(topStart.classes()).toContain('hilos-toast-stack-top')
  })

  it('announces a measured notice, an error apart from the rest', async () => {
    const store = createHilosToastStore()
    store.push('The report could not be built', { severity: 'error' })
    store.syncSession([sessionToast()])
    const wrapper = mountHost(store)
    const assertive = '[data-id="hilos-toast-live-assertive"]'
    const polite = '[data-id="hilos-toast-live-polite"]'

    // Both regions exist from the first frame — that is the point of declaring
    // them in advance — and stay empty until the cards are measured.
    expect(wrapper.find(assertive).exists()).toBe(true)
    expect(wrapper.find(assertive).text()).toBe('')
    expect(wrapper.find(polite).text()).toBe('')

    await nextTick()
    expect(wrapper.find(assertive).text()).toBe('The report could not be built')
    expect(wrapper.find(polite).text()).toBe('Backup: The export is ready')
  })

  it('keeps the live regions outside the stack container', async () => {
    const store = createHilosToastStore()
    store.push('Saved', { severity: 'success' })
    const wrapper = mountHost(store)
    await nextTick()

    // Inside, they would double every visible line, and the demo suites look a
    // notice up by its text within the container.
    expect(wrapper.find('[data-id="hilos-toasts"] [aria-live]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-toasts"]').text()).toBe('Saved')
  })
})
