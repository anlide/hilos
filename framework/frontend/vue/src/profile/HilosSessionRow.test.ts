import {
  bindTimeZoneState,
  type HilosConnection,
  type HilosProfileSession,
} from '@hilos/core/base'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, nextTick, ref, type PropType } from 'vue'

import HilosSessionRow from './HilosSessionRow.vue'

let releaseTimeZone: (() => void) | undefined

afterEach(() => {
  releaseTimeZone?.()
  releaseTimeZone = undefined
  vi.restoreAllMocks()
})
enableAutoUnmount(afterEach)

/** Reader zone the next format call resolves; a window focus re-reads it. */
let readerZone = 'Asia/Tokyo'

function bindReaderZone(zone: string): void {
  readerZone = zone
  const resolved = new Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockImplementation(
    () => ({ ...resolved, timeZone: readerZone }),
  )
  releaseTimeZone = bindTimeZoneState({
    on: () => () => {},
  } as unknown as HilosConnection)
}

async function moveReaderTo(zone: string): Promise<void> {
  readerZone = zone
  window.dispatchEvent(new Event('focus'))
  await nextTick()
}

function makeSession(
  id: number,
  fields: Partial<HilosProfileSession> = {},
): HilosProfileSession {
  return {
    id,
    deviceName: `Device ${id}`,
    browserTimeZone: null,
    createdAt: 1767227400000,
    lastSeenAt: 1767227460000,
    expiresAt: 1767313800000,
    current: false,
    impersonated: false,
    tabs: [],
    ...fields,
  }
}

function makeTab(
  acceptKey: string,
  connectedAt = 1767227520000,
  current = false,
): HilosProfileSession['tabs'][number] {
  return { acceptKey, connectedAt, current }
}

function mountRow(
  session: HilosProfileSession,
  options: {
    expanded?: boolean
    idPrefix?: string
    slots?: Record<string, () => unknown>
    attrs?: Record<string, unknown>
  } = {},
) {
  return mount(HilosSessionRow, {
    props: {
      session,
      expanded: options.expanded ?? false,
      idPrefix: options.idPrefix ?? 'profile-session',
    },
    slots: options.slots,
    attrs: options.attrs,
  })
}

/**
 * Two rows under one parent that keeps which of them are open by session id —
 * the way every surface drawing the row is meant to hold it.
 */
const TwoRows = defineComponent({
  props: {
    sessions: {
      type: Array as PropType<readonly HilosProfileSession[]>,
      required: true,
    },
  },
  setup(props) {
    const open = ref<ReadonlySet<number>>(new Set())
    const flip = (id: number): void => {
      const next = new Set(open.value)
      if (!next.delete(id)) next.add(id)
      open.value = next
    }
    return () =>
      h(
        'div',
        props.sessions.map((session) =>
          h(HilosSessionRow, {
            key: session.id,
            session,
            expanded: open.value.has(session.id),
            idPrefix: 'user-session',
            onToggle: () => flip(session.id),
          }),
        ),
      )
  },
})

describe('HilosSessionRow (Vue)', () => {
  it.each([
    ['Chrome on macOS', 'Europe/Kyiv', 'Chrome on macOS · Europe/Kyiv'],
    [null, 'US/Eastern', 'Unknown device · US/Eastern'],
    ['Firefox on Linux', null, 'Firefox on Linux'],
    [null, null, 'Unknown device'],
  ])(
    'names device %s with the zone %s its browser reported',
    (deviceName, browserTimeZone, caption) => {
      const row = mountRow(makeSession(7, { deviceName, browserTimeZone }))
      expect(row.get('[data-id="profile-session-device"]').text()).toBe(caption)
    },
  )

  it('names the session in a heading of its own and its marks inside it', () => {
    const row = mountRow(makeSession(4821), {
      slots: { badge: () => h('span', 'Acting as · Alex') },
    })
    const heading = row.get('h3')
    expect(heading.text()).toBe('Session #4821 Acting as · Alex')
    expect(row.findAll('h1, h2, h4').length).toBe(0)
  })

  it('keeps every icon decorative, so the words carry the meaning', async () => {
    const row = mountRow(
      makeSession(1, { impersonated: true, tabs: [makeTab('tab-a')] }),
      { expanded: true },
    )
    const icons = row.findAll('i')
    expect(icons.length).toBeGreaterThan(0)
    for (const icon of icons) {
      expect(icon.attributes('aria-hidden')).toBe('true')
    }
    expect(row.get('[data-id="profile-session-tabs"]').text()).toBe('tabs: 1')

    await row.setProps({ expanded: false })
    for (const icon of row.findAll('i')) {
      expect(icon.attributes('aria-hidden')).toBe('true')
    }
  })

  it('reads created, last seen and expires in the reader zone and again when it moves', async () => {
    bindReaderZone('Asia/Tokyo')
    const tabbed = mountRow(
      makeSession(1, {
        tabs: [makeTab('tab-current', 1767227520000, true)],
      }),
      { expanded: true },
    )
    const closed = mountRow(makeSession(2, { expiresAt: null }))
    const tab = tabbed.get('[data-id="profile-session-tab"]').element

    expect(tabbed.text()).toContain('created 1/1/2026, 9:30:00 AM')
    expect(tabbed.text()).toContain('last seen now')
    expect(tabbed.text()).toContain('expires 1/2/2026, 9:30:00 AM')
    expect(tab.textContent).toContain('opened 1/1/2026, 9:32:00 AM')
    expect(closed.text()).toContain('last seen 1/1/2026, 9:31:00 AM')
    expect(closed.text()).toContain('expires never')

    await moveReaderTo('America/New_York')

    expect(tabbed.text()).toContain('created 12/31/2025, 7:30:00 PM')
    expect(tabbed.text()).toContain('expires 1/1/2026, 7:30:00 PM')
    expect(closed.text()).toContain('last seen 12/31/2025, 7:31:00 PM')
    expect(tabbed.get('[data-id="profile-session-tab"]').element).toBe(tab)
    expect(tab.textContent).toContain('opened 12/31/2025, 7:32:00 PM')
    expect(
      tabbed
        .get('[data-id="profile-session-tabs"]')
        .attributes('aria-expanded'),
    ).toBe('true')
  })

  it('asks its surface to open the tabs and opens nothing by itself', async () => {
    const row = mountRow(makeSession(3, { tabs: [makeTab('tab-a')] }))
    const toggle = row.get('[data-id="profile-session-tabs"]')
    expect(toggle.element.tagName).toBe('BUTTON')
    expect(toggle.attributes('type')).toBe('button')
    expect(toggle.attributes('aria-expanded')).toBe('false')

    await toggle.trigger('click')
    expect(row.emitted('toggle')).toEqual([[]])
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(row.find('[data-id="profile-session-tab"]').exists()).toBe(false)

    await row.setProps({ expanded: true })
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(row.findAll('[data-id="profile-session-tab"]')).toHaveLength(1)
  })

  it('opens one row at a time and keeps it open through a server update', async () => {
    const first = makeSession(1, { tabs: [makeTab('tab-a')] })
    const second = makeSession(2, {
      tabs: [makeTab('tab-b'), makeTab('tab-c')],
    })
    const surface = mount(TwoRows, { props: { sessions: [first, second] } })
    const toggleOf = (id: number) =>
      surface
        .findAll('[data-id="user-session-row"]')
        .find((row) => row.get('h3').text() === `Session #${id}`)!
        .get('[data-id="user-session-tabs"]')

    await toggleOf(2).trigger('click')
    expect(toggleOf(1).attributes('aria-expanded')).toBe('false')
    expect(toggleOf(2).attributes('aria-expanded')).toBe('true')

    await surface.setProps({
      sessions: [
        makeSession(3),
        { ...second, tabs: [...second.tabs, makeTab('tab-d')] },
        first,
      ],
    })
    expect(toggleOf(3).attributes('aria-expanded')).toBe('false')
    expect(toggleOf(2).attributes('aria-expanded')).toBe('true')
    expect(toggleOf(2).text()).toBe('tabs: 3')
    expect(toggleOf(1).attributes('aria-expanded')).toBe('false')
    expect(surface.findAll('[data-id="user-session-tab"]')).toHaveLength(3)
  })

  it('says a session with no open tabs still lives', () => {
    const row = mountRow(makeSession(4), { expanded: true })
    expect(row.text()).toContain(
      'No open tabs: the sign-in stays valid, but the browser is closed right now.',
    )
    expect(row.find('[data-id="profile-session-tab"]').exists()).toBe(false)
    expect(row.find('ul').exists()).toBe(false)
    expect(row.get('[data-id="profile-session-tabs"]').text()).toBe('tabs: 0')
  })

  it('shortens a long tab key to its edges and keeps each tab by its full key', async () => {
    const longKey = 'x3Jb123456789f2a'
    const sameEdges = 'x3Jb0000000009f2a'
    const session = makeSession(5, {
      tabs: [
        makeTab(longKey, 1767227520000, true),
        makeTab(sameEdges, 1767227580000),
        makeTab('ab12', 1767227640000),
        makeTab('abcd1234', 1767227700000),
      ],
    })
    const row = mountRow(session, { expanded: true })
    const tabs = () => row.findAll('[data-id="profile-session-tab"]')
    expect(tabs().map((tab) => tab.get('code').text())).toEqual([
      'x3Jb…9f2a',
      'x3Jb…9f2a',
      'ab12',
      'abcd1234',
    ])
    expect(
      tabs().map((tab) =>
        tab.find('[data-id="profile-session-tab-this"]').exists(),
      ),
    ).toEqual([true, false, false, false])
    expect(tabs()[0]!.get('[data-id="profile-session-tab-this"]').text()).toBe(
      'this tab',
    )

    const [longTab, sameEdgesTab] = tabs().map((tab) => tab.element)
    await row.setProps({
      session: { ...session, tabs: [...session.tabs].reverse() },
    })
    expect(tabs()[3]!.element).toBe(longTab)
    expect(tabs()[2]!.element).toBe(sameEdgesTab)
    expect(
      tabs()[3]!.find('[data-id="profile-session-tab-this"]').exists(),
    ).toBe(true)
  })

  it('draws the surface control after the tabs toggle and none of its own', () => {
    const bare = mountRow(makeSession(6))
    expect(bare.findAll('button').map((b) => b.attributes('data-id'))).toEqual([
      'profile-session-tabs',
    ])

    const withEnd = mountRow(makeSession(6), {
      idPrefix: 'user-session',
      slots: {
        actions: () =>
          h('button', { type: 'button', 'data-id': 'user-session-end' }, 'End'),
      },
    })
    expect(
      withEnd.findAll('button').map((b) => b.attributes('data-id')),
    ).toEqual(['user-session-tabs', 'user-session-end'])
    expect(withEnd.get('[data-id="user-session-end"]').text()).toBe('End')
  })

  it('carries the handles of its surface and the record it is handed', async () => {
    const row = mountRow(
      makeSession(4390, {
        browserTimeZone: 'Europe/Warsaw',
        tabs: [makeTab('Vv1K0000008bd', 1767227520000, true)],
      }),
      {
        expanded: true,
        idPrefix: 'user-session',
        attrs: { 'data-record': 4390 },
      },
    )
    expect(row.attributes('data-id')).toBe('user-session-row')
    expect(row.attributes('data-record')).toBe('4390')
    for (const handle of ['device', 'tabs', 'tab', 'tab-this']) {
      expect(row.find(`[data-id="user-session-${handle}"]`).exists()).toBe(true)
    }
    expect(row.html()).not.toContain('profile-session')
  })
})
