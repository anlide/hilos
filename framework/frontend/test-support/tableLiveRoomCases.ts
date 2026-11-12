import type { TableViewportController } from '@hilos/core/base'
import {
  withTableLiveResources,
  type TableLiveMount,
  type TableLiveRunner,
} from './tableLiveFixtures.js'

// Literal semantic handles, independent of the component's private ROW_ID map.
const LINES = [
  '[data-id="hilos-table-bulk-report"]',
  '[data-id="hilos-table-progress-bulk"]',
  '[data-id="hilos-table-pending-row"]',
  '[data-id="hilos-table-announce"]',
  '[data-id="hilos-table-frozen"]',
  '[data-id="hilos-table-stale"]',
  '[data-id="hilos-table-progress"]',
].join(',')

function required(root: ParentNode, id: string): HTMLElement {
  const element = root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (element === null) throw new Error(`Missing TableLive element: ${id}`)
  return element
}

function liveThree(controller: TableViewportController<unknown>): void {
  controller.ingestDelta({
    kind: 'row_moved',
    rowKey: 'a',
    row: { rowKey: 'a', slots: { name: 'Alicia' } },
  })
  controller.ingestAnnounce('b', 'above', 2, true)
  controller.ingestDelta({
    kind: 'row_stale',
    rowKey: 'a',
    staleSources: ['connections'],
  })
}

/** One room contract, exercised by each real SDK component. */
export function registerTableLiveRoomCases(
  sdk: string,
  runner: TableLiveRunner,
  mount: TableLiveMount,
): void {
  const { describe, it, expect } = runner
  describe(`${sdk} TableLive room`, () => {
    it('TL-A1 keeps one twin and the same standing region through all phases', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const controller = resources.create()
        const view = await resources.mount(controller)
        const twins = () =>
          view.root.querySelectorAll('[data-id="hilos-table-live-idle"]')
        const status = required(view.root, 'hilos-table-live-status')
        expect(twins()).toHaveLength(1)
        expect(status.textContent).toBe('')

        await view.change(() =>
          controller.ingestAnnounce('b', 'above', 2, true),
        )
        expect(twins()).toHaveLength(1)
        expect(required(view.root, 'hilos-table-live-status')).toBe(status)

        await view.change(() => liveThree(controller))
        expect(twins()).toHaveLength(1)
        expect(required(view.root, 'hilos-table-live-status')).toBe(status)
      })
    })

    it('TL-A2 withdraws the default twin from sight, accessibility and focus', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        resources.useBootstrap()
        const view = await resources.mount(resources.create())
        const twin = required(view.root, 'hilos-table-live-idle')
        expect(getComputedStyle(twin).visibility).toBe('hidden')
        expect(twin.getAttribute('aria-hidden')).toBe('true')
        expect(twin.querySelector('button')).toBeNull()
        expect(
          twin.querySelector(
            'a[href], input, select, textarea, [tabindex], [contenteditable="true"], audio[controls], video[controls], summary',
          ),
        ).toBeNull()
      })
    })

    it('TL-A3 keeps an idle room and empty status without a message line', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const view = await resources.mount(resources.create())
        expect(required(view.root, 'hilos-table-live-slot')).toBeTruthy()
        expect(required(view.root, 'hilos-table-live-status').textContent).toBe(
          '',
        )
        expect(view.root.querySelectorAll(LINES)).toHaveLength(0)
      })
    })

    it('TL-A4 gives pending the line and announces the two decorative rest icons in order', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const controller = resources.create()
        liveThree(controller)
        const view = await resources.mount(controller)
        expect(view.root.querySelectorAll(LINES)).toHaveLength(1)
        const pending = required(view.root, 'hilos-table-pending-row')
        expect(pending.textContent).toContain('1 row will move or leave')
        const apply = required(pending, 'hilos-table-apply')
        expect(apply.tagName).toBe('BUTTON')
        expect(apply.textContent?.trim()).toBe('Apply')
        expect(
          view.root.querySelector('[data-id="hilos-table-announce"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-table-stale"]'),
        ).toBeNull()
        const icons = pending.querySelectorAll(
          '[data-id="hilos-table-live-rest"] i',
        )
        expect(icons).toHaveLength(2)
        for (const icon of icons)
          expect(icon.getAttribute('aria-hidden')).toBe('true')
        // Only template-edge whitespace is trimmed; punctuation and order stay exact.
        expect(
          required(view.root, 'hilos-table-live-status').textContent?.trim(),
        ).toBe('1 row will move or leave. Also: new rows, a source is behind.')
      })
    })

    it('TL-A5 speaks the exact sentence through the real standing status region', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const controller = resources.create()
        liveThree(controller)
        const view = await resources.mount(controller)
        expect(
          view.root.querySelectorAll('[data-id="hilos-table-live-status"]'),
        ).toHaveLength(1)
        const status = required(view.root, 'hilos-table-live-status')
        expect(status.getAttribute('role')).toBe('status')
        expect(view.root.querySelectorAll('[role="status"]')).toHaveLength(1)
        expect(view.root.querySelector('[role="status"]')).toBe(status)
        expect(status.textContent?.trim()).toBe(
          '1 row will move or leave. Also: new rows, a source is behind.',
        )
      })
    })

    it('TL-A6 keeps the live region separate from the pending line', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const controller = resources.create()
        liveThree(controller)
        const view = await resources.mount(controller)
        const pending = required(view.root, 'hilos-table-pending-row')
        expect(pending.querySelector('[role]')).toBeNull()
        expect(pending.hasAttribute('role')).toBe(false)
        expect(pending.hasAttribute('aria-live')).toBe(false)
        expect(pending.querySelector('[aria-live]')).toBeNull()
        const status = required(view.root, 'hilos-table-live-status')
        expect(pending.contains(status)).toBe(false)
        expect(status.getAttribute('role')).toBe('status')
      })
    })
  })
}
