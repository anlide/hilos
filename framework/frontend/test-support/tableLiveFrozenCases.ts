import { hilosTableFrozenLabel } from '@hilos/core/base'
import {
  withTableLiveResources,
  type TableLiveMount,
  type TableLiveRunner,
} from './tableLiveFixtures.js'

const FROZEN_SINCE = Date.parse('2026-10-09T10:11:12.000Z')

function required<T extends HTMLElement>(
  root: ParentNode,
  selector: string,
): T {
  const element = root.querySelector<T>(selector)
  if (element === null)
    throw new Error(`Missing TableLive element: ${selector}`)
  return element
}

/** Quiet-source and frozen-table behavior, exercised by each real SDK. */
export function registerTableLiveFrozenCases(
  sdk: string,
  runner: TableLiveRunner,
  mount: TableLiveMount,
): void {
  const { describe, it, expect } = runner
  describe(`${sdk} TableLive frozen source`, () => {
    it('TL-B1 names the quiet source on the freshness line', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const controller = resources.create()
        controller.ingestDelta({
          kind: 'row_stale',
          rowKey: 'a',
          staleSources: ['connections'],
        })
        const view = await resources.mount(controller)
        const stale = required(view.root, '[data-id="hilos-table-stale"]')

        expect(stale.textContent?.trim()).toBe(
          'Presence is not updating: the link to its source was lost. The other columns are live.',
        )
      })
    })

    it('TL-B2 shows the frozen label and decorative icon without an action', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const controller = resources.create()
        controller.ingestFrozen(FROZEN_SINCE)
        const view = await resources.mount(controller)
        const line = required(view.root, '[data-id="hilos-table-frozen"]')
        const expected = hilosTableFrozenLabel(FROZEN_SINCE)
        const icon = required(line, 'i')
        const status = required(
          view.root,
          '[data-id="hilos-table-live-status"]',
        )

        expect(line.textContent?.trim()).toBe(expected)
        expect(line.textContent).toContain('This table has not updated since')
        expect(icon.getAttribute('aria-hidden')).toBe('true')
        expect(line.querySelector('button')).toBeNull()
        expect(status.textContent?.trim()).toBe(expected)
      })
    })

    it('TL-B3 keeps pending changes ahead of the frozen message', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const controller = resources.create()
        controller.ingestDelta({
          kind: 'row_moved',
          rowKey: 'a',
          row: { rowKey: 'a', slots: { name: 'Alicia' } },
        })
        controller.ingestFrozen(FROZEN_SINCE)
        const view = await resources.mount(controller)
        required(view.root, '[data-id="hilos-table-pending-row"]')
        const rest = required(view.root, '[data-id="hilos-table-live-rest"]')
        const icons = rest.querySelectorAll('i')
        const status = required(
          view.root,
          '[data-id="hilos-table-live-status"]',
        )

        expect(icons).toHaveLength(1)
        for (const icon of icons)
          expect(icon.getAttribute('aria-hidden')).toBe('true')
        expect(status.textContent?.trim()).toBe(
          '1 row will move or leave. Also: the table stopped updating.',
        )
      })
    })

    it('TL-B4 suppresses the quiet-source message while frozen', async () => {
      await withTableLiveResources(runner, mount, async (resources) => {
        const controller = resources.create()
        controller.ingestDelta({
          kind: 'row_stale',
          rowKey: 'a',
          staleSources: ['connections'],
        })
        controller.ingestFrozen(FROZEN_SINCE)
        const view = await resources.mount(controller)

        expect(
          view.root.querySelector('[data-id="hilos-table-frozen"]'),
        ).not.toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-table-stale"]'),
        ).toBeNull()
        expect(
          view.root.querySelector('[data-id="hilos-table-live-rest"]'),
        ).toBeNull()
      })
    })
  })
}
