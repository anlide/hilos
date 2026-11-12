import type { HilosTableColumn, HilosTableFrame } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import type {
  TableBarMount,
  TableBarResources,
  TableBarRunner,
  TableBarView,
} from './tableBarFixtures.js'
import { withTableBarResources } from './tableBarFixtures.js'

type ListActionRunner = TableBarRunner & Pick<typeof Vitest, 'vi'>

const COLUMNS: readonly HilosTableColumn[] = [{ key: 'name', label: 'Name' }]

const SORTABLE_COLUMNS: readonly HilosTableColumn[] = [
  { key: 'channel', label: 'Kind', sortable: true },
  { key: 'createdAt', label: 'Date', sortable: true },
]

const FILTERED: HilosTableFrame = {
  title: 'Deliveries',
  search: {},
  filters: [
    { kind: 'toggle', key: 'state', label: 'Failed only', on: 'failed' },
    { kind: 'toggle', key: 'unread', label: 'Unread only', on: true },
  ],
  columns: COLUMNS,
}

function makeActions(runner: ListActionRunner, bulk: boolean) {
  const copy = runner.vi.fn()
  const download = runner.vi.fn()
  const frame: HilosTableFrame = {
    columns: COLUMNS,
    auxiliaryActions: [
      {
        key: 'copy',
        label: 'Copy the list',
        icon: 'bi-clipboard',
        press: copy,
      },
      {
        key: 'download',
        label: 'Download as a file',
        icon: 'bi-download',
        press: download,
      },
    ],
    ...(bulk
      ? {
          bulkActions: [
            {
              key: 'archive',
              label: 'Archive',
              refusalTitle: 'Cannot archive',
              run: () => {
                throw new Error('Not pressed')
              },
            },
          ],
        }
      : {}),
  }
  return { frame, copy, download }
}

function required<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  selector: string,
): T {
  const node = root.querySelector<T>(selector)
  if (node === null) throw new Error('Missing table bar node: ' + selector)
  return node
}

function byId<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  id: string,
): T {
  return required<T>(root, '[data-id="' + id + '"]')
}

function dialogs(view: TableBarView, initial: Set<Element>): HTMLElement[] {
  return Array.from(
    view.root.ownerDocument.querySelectorAll<HTMLElement>('[role="dialog"]'),
  ).filter((dialog) => !initial.has(dialog))
}

function isVisible(node: HTMLElement): boolean {
  if (!node.isConnected) return false
  for (
    let ancestor: HTMLElement | null = node;
    ancestor !== null;
    ancestor = ancestor.parentElement
  ) {
    if (
      ancestor.hidden ||
      ancestor.hasAttribute('inert') ||
      ancestor.getAttribute('aria-hidden') === 'true'
    )
      return false
    const style = ancestor.ownerDocument.defaultView?.getComputedStyle(ancestor)
    if (
      style?.display === 'none' ||
      style?.visibility === 'hidden' ||
      style?.visibility === 'collapse'
    )
      return false
  }
  return true
}

/** Shared list-action behavior through each SDK's real Bar and modal. */
export function registerTableBarListActionCases(
  sdk: string,
  runner: ListActionRunner,
  mount: TableBarMount,
): void {
  const { describe, it, expect } = runner
  function scenario(
    name: string,
    steps: (resources: TableBarResources) => Promise<void>,
  ): void {
    it(name, () => withTableBarResources(runner, mount, steps))
  }

  describe('HilosTableBar list actions on ' + sdk, () => {
    for (const bulk of [false, true]) {
      scenario(
        'L01 exposes named list buttons; bulk: ' + bulk,
        async (resources) => {
          const { frame, copy, download } = makeActions(runner, bulk)
          const fixture = resources.create(frame)
          const view = await resources.mount(fixture)
          const groups = view.root.querySelectorAll(
            '[data-id="hilos-table-actions"]',
          )
          expect(groups).toHaveLength(1)
          const group = groups[0]!
          expect(group.getAttribute('role')).toBe('group')
          expect(group.getAttribute('aria-label')).toBe('List actions')
          expect(
            Array.from(group.querySelectorAll('button')).map((button) => [
              button.textContent?.trim(),
              button.type,
              button.getAttribute('data-id'),
              button.disabled,
            ]),
          ).toEqual([
            ['Copy the list', 'button', 'hilos-table-action-copy', false],
            [
              'Download as a file',
              'button',
              'hilos-table-action-download',
              false,
            ],
          ])
          expect(
            Array.from(group.querySelectorAll('i')).map((icon) =>
              icon.getAttribute('aria-hidden'),
            ),
          ).toEqual(['true', 'true'])
          expect(copy).not.toHaveBeenCalled()
          expect(download).not.toHaveBeenCalled()
          expect(
            view.root.querySelector('[data-id="hilos-table-search"]'),
          ).toBeNull()
          expect(
            view.root.querySelector('[data-id="hilos-table-main-action"]'),
          ).toBeNull()

          await view.change(() =>
            byId(group, 'hilos-table-action-copy').click(),
          )
          expect(copy).toHaveBeenCalledExactlyOnceWith()
          expect(download).not.toHaveBeenCalled()

          await view.change(() =>
            byId(group, 'hilos-table-action-download').click(),
          )
          expect(copy).toHaveBeenCalledExactlyOnceWith()
          expect(download).toHaveBeenCalledExactlyOnceWith()
          expect(fixture.sent).toEqual([])
        },
      )

      for (const body of ['empty', 'empty_filtered'] as const) {
        scenario(
          'L02 keeps actions available for ' + body + '; bulk: ' + bulk,
          async (resources) => {
            const { frame, copy, download } = makeActions(runner, bulk)
            const initialFilter =
              body === 'empty_filtered' ? { search: 'missing' } : undefined
            const fixture = resources.create(
              { ...frame, search: {} },
              initialFilter,
            )
            fixture.controller.ingestWindow([], 0, true, null, null, 10)
            expect(fixture.controller.frame.body.get()).toBe(body)
            const view = await resources.mount(fixture)

            expect(copy).not.toHaveBeenCalled()
            expect(download).not.toHaveBeenCalled()
            for (const key of ['copy', 'download']) {
              const button = byId<HTMLButtonElement>(
                view.root,
                'hilos-table-action-' + key,
              )
              expect(isVisible(button)).toBe(true)
              expect(button.disabled).toBe(false)
              await view.change(() => button.click())
              if (key === 'copy') {
                expect(copy).toHaveBeenCalledExactlyOnceWith()
                expect(download).not.toHaveBeenCalled()
              } else {
                expect(copy).toHaveBeenCalledExactlyOnceWith()
                expect(download).toHaveBeenCalledExactlyOnceWith()
              }
            }
          },
        )
      }

      for (const sortable of [false, true]) {
        scenario(
          'L03 keeps list actions outside the filters modal; sortable: ' +
            sortable +
            '; bulk: ' +
            bulk,
          async (resources) => {
            const initialDialogs = new Set(
              document.querySelectorAll('[role="dialog"]'),
            )
            const initiallyLocked =
              document.body.classList.contains('modal-open')
            const { frame, copy, download } = makeActions(runner, bulk)
            const fixture = resources.create({
              ...FILTERED,
              ...frame,
              columns: sortable ? SORTABLE_COLUMNS : COLUMNS,
            })
            const view = await resources.mount(fixture)
            const group = byId(view.root, 'hilos-table-actions')
            const opener = byId<HTMLButtonElement>(
              view.root,
              'hilos-table-filters-open',
            )
            expect(isVisible(group)).toBe(true)
            expect(copy).not.toHaveBeenCalled()
            expect(download).not.toHaveBeenCalled()
            opener.focus()
            expect(view.root.ownerDocument.activeElement).toBe(opener)

            await view.change(() => opener.click())
            expect(dialogs(view, initialDialogs)).toHaveLength(1)
            const dialog = dialogs(view, initialDialogs)[0]!
            expect(
              dialog.querySelector('[data-id="hilos-table-actions"]'),
            ).toBeNull()
            expect(
              view.root.querySelector('[data-id="hilos-table-actions"]'),
            ).toBe(group)
            expect(isVisible(group)).toBe(true)
            expect(dialog.contains(view.root.ownerDocument.activeElement)).toBe(
              true,
            )
            expect(document.body.classList.contains('modal-open')).toBe(true)
            expect(copy).not.toHaveBeenCalled()
            expect(download).not.toHaveBeenCalled()

            await view.change(() =>
              byId(dialog, 'hilos-table-filters-done').click(),
            )
            expect(dialogs(view, initialDialogs)).toEqual([])
            expect(dialog.isConnected).toBe(false)
            expect(
              view.root.querySelector('[data-id="hilos-table-actions"]'),
            ).toBe(group)
            expect(isVisible(group)).toBe(true)
            expect(view.root.ownerDocument.activeElement).toBe(opener)
            expect(document.body.classList.contains('modal-open')).toBe(
              initiallyLocked,
            )

            await view.change(() =>
              byId(group, 'hilos-table-action-copy').click(),
            )
            expect(copy).toHaveBeenCalledExactlyOnceWith()
            expect(download).not.toHaveBeenCalled()
          },
        )
      }

      scenario(
        'L04 keeps main and list callbacks independent; bulk: ' + bulk,
        async (resources) => {
          const { frame, copy, download } = makeActions(runner, bulk)
          const main = runner.vi.fn()
          const fixture = resources.create({
            ...frame,
            mainAction: { label: 'Create backup', press: main },
          })
          const view = await resources.mount(fixture)
          const button = byId<HTMLButtonElement>(
            view.root,
            'hilos-table-main-action',
          )
          expect(button.textContent?.trim()).toBe('Create backup')
          expect(main).not.toHaveBeenCalled()

          await view.change(() => button.click())
          expect(main).toHaveBeenCalledExactlyOnceWith()
          expect(copy).not.toHaveBeenCalled()
          expect(download).not.toHaveBeenCalled()

          await view.change(() =>
            byId(view.root, 'hilos-table-action-copy').click(),
          )
          expect(main).toHaveBeenCalledExactlyOnceWith()
          expect(copy).toHaveBeenCalledExactlyOnceWith()
          expect(download).not.toHaveBeenCalled()
        },
      )

      for (const auxiliaryActions of [undefined, []] as const) {
        scenario(
          'L05 preserves main action when auxiliaryActions is ' +
            (auxiliaryActions === undefined ? 'undefined' : 'empty') +
            ' bulk: ' +
            bulk,
          async (resources) => {
            const { frame } = makeActions(runner, bulk)
            const main = runner.vi.fn()
            const fixture = resources.create({
              ...frame,
              auxiliaryActions,
              mainAction: { label: 'Create backup', press: main },
            })
            const view = await resources.mount(fixture)
            expect(
              view.root.querySelector('[data-id="hilos-table-actions"]'),
            ).toBeNull()
            const button = byId<HTMLButtonElement>(
              view.root,
              'hilos-table-main-action',
            )
            expect(button.textContent?.trim()).toBe('Create backup')
            await view.change(() => button.click())
            expect(main).toHaveBeenCalledExactlyOnceWith()
          },
        )
      }

      scenario(
        'L06 omits ordinary controls with no actions; bulk: ' + bulk,
        async (resources) => {
          const { frame } = makeActions(runner, bulk)
          const view = await resources.mount(
            resources.create({ ...frame, auxiliaryActions: [] }),
          )
          expect(
            view.root.querySelector('[data-id="hilos-table-actions"]'),
          ).toBeNull()
          expect(
            view.root.querySelector('[data-id="hilos-table-controls"]'),
          ).toBeNull()
        },
      )

      scenario(
        'L07 draws a text-only action; bulk: ' + bulk,
        async (resources) => {
          const { frame } = makeActions(runner, bulk)
          const copy = runner.vi.fn()
          const view = await resources.mount(
            resources.create({
              ...frame,
              auxiliaryActions: [
                { key: 'copy', label: 'Copy the list', press: copy },
              ],
            }),
          )
          const button = byId<HTMLButtonElement>(
            view.root,
            'hilos-table-action-copy',
          )
          expect(button.textContent?.trim()).toBe('Copy the list')
          expect(button.querySelector('i')).toBeNull()
          expect(copy).not.toHaveBeenCalled()
          await view.change(() => button.click())
          expect(copy).toHaveBeenCalledExactlyOnceWith()
        },
      )
    }
  })
}
