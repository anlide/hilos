// The full PageHead centering and row-lifecycle union on real SDK components.
import type * as Vitest from 'vitest'
import type { MountPageHead, PageHeadView } from './pageHeadBlockCases.js'
import {
  SECTION,
  sectionHeadInput,
  type PageHeadInput,
} from './pageHeadFixtures.js'
import { PAGE_HEAD_ROW, installPageHeadGeometry } from './pageHeadGeometry.js'

type PageHeadRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect' | 'vi'>
type Geometry = ReturnType<typeof installPageHeadGeometry>

/** Read the real row; an absent row cannot satisfy a scrolling case. */
function rowOf(view: PageHeadView): HTMLElement {
  const row = view.root.querySelector<HTMLElement>(PAGE_HEAD_ROW)
  if (row === null) throw new Error('The head draws no row.')
  return row
}

/** Register controlled geometry events without implementing the centering. */
export function registerPageHeadCenteringCases(
  sdk: string,
  runner: PageHeadRunner,
  mount: MountPageHead,
): void {
  const { describe, it, expect, vi } = runner

  /** Own one view, including cases that explicitly release it before asserting. */
  async function withHead(
    input: PageHeadInput,
    run: (
      view: PageHeadView,
      geometry: Geometry,
      unmount: () => Promise<void>,
    ) => Promise<void>,
  ): Promise<void> {
    const geometry = installPageHeadGeometry(vi)
    let view: PageHeadView | undefined
    async function unmount(): Promise<void> {
      const owned = view
      view = undefined
      await owned?.unmount()
    }
    try {
      view = await mount(input)
      await run(view, geometry, unmount)
    } finally {
      try {
        await unmount()
      } finally {
        geometry.restore()
      }
    }
  }

  describe(`HilosPageHead centering on ${sdk}`, () => {
    it('C01 centers Gaps on a connected mount', async () => {
      await withHead(
        sectionHeadInput({ page: 'demo_section_gaps' }),
        async (view) => {
          const row = rowOf(view)
          expect(row.isConnected).toBe(true)
          expect(row.scrollLeft).toBe(100)
        },
      )
    })

    it('C02 centers when the answer brings a row on the same mount', async () => {
      await withHead(
        sectionHeadInput({ identity: undefined, page: 'demo_section_gaps' }),
        async (view) => {
          expect(view.root.querySelector(PAGE_HEAD_ROW)).toBeNull()
          await view.update({ identity: SECTION })
          expect(rowOf(view).scrollLeft).toBe(100)
        },
      )
    })

    it('C03 clamps both ends independently of the previous scroll', async () => {
      await withHead(
        sectionHeadInput({ page: 'demo_section_gaps' }),
        async (view) => {
          const row = rowOf(view)
          expect(row.scrollLeft).toBe(100)
          row.scrollLeft = 40
          await view.update({ page: 'demo_section' })
          expect(row.scrollLeft).toBe(0)
          row.scrollLeft = 30
          await view.update({ page: 'demo_section_gaps_machine' })
          expect(row.scrollLeft).toBe(160)
        },
      )
    })

    it('C04 follows keys and resize, preserving manual scroll across renames and identical inputs', async () => {
      await withHead(sectionHeadInput(), async (view, geometry) => {
        const row = rowOf(view)
        expect(row.scrollLeft).toBe(0)
        await view.update({ page: 'demo_section_gaps' })
        expect(row.scrollLeft).toBe(100)

        row.scrollLeft = 30
        await view.update({
          identity: {
            ...SECTION,
            pageHeadOwner: { ...SECTION.pageHeadOwner!, label: 'Renamed' },
            pageTabs: SECTION.pageTabs!.map((entry) => ({
              ...entry,
              label: `${entry.label} (2)`,
            })),
          },
        })
        expect(view.root.querySelector('h1')?.textContent?.trim()).toBe(
          'Renamed',
        )
        expect(row.scrollLeft).toBe(30)
        await view.update({ page: 'demo_section_gaps', params: {} })
        expect(row.scrollLeft).toBe(30)

        geometry.box.width = 120
        expect(geometry.observers).toHaveLength(1)
        expect(geometry.observers[0].targets).toEqual([row])
        geometry.observers[0].resize()
        expect(row.scrollLeft).toBe(140)
      })
    })

    it('C05 re-centers when removing List moves Gaps', async () => {
      await withHead(
        sectionHeadInput({ page: 'demo_section_gaps' }),
        async (view) => {
          const row = rowOf(view)
          expect(row.scrollLeft).toBe(100)
          await view.update({
            identity: {
              ...SECTION,
              pageTabs: SECTION.pageTabs!.filter(
                (entry) => entry.page !== 'demo_section_list',
              ),
            },
          })
          expect(row.scrollLeft).toBe(40)
        },
      )
    })

    it('C06 releases a disappearing row and observes its new replacement', async () => {
      await withHead(sectionHeadInput(), async (view, { observers }) => {
        const oldRow = rowOf(view)
        expect(observers).toHaveLength(1)
        expect(observers[0].targets).toEqual([oldRow])
        await view.update({
          identity: {
            ...SECTION,
            pageTabs: [{ page: 'demo_section', label: 'Overview' }],
          },
        })
        expect(view.root.querySelector(PAGE_HEAD_ROW)).toBeNull()
        expect(observers[0].disconnect).toHaveBeenCalledTimes(1)

        await view.update({ identity: SECTION })
        const newRow = rowOf(view)
        expect(newRow).not.toBe(oldRow)
        expect(newRow.isConnected).toBe(true)
        expect(observers).toHaveLength(2)
        expect(observers[1].targets).toEqual([newRow])
        expect(observers[1].disconnect).not.toHaveBeenCalled()
      })
    })

    it('C07 disconnects exactly once on native unmount or destroy', async () => {
      await withHead(
        sectionHeadInput(),
        async (_view, { observers }, unmount) => {
          expect(observers).toHaveLength(1)
          expect(observers[0].disconnect).not.toHaveBeenCalled()
          await unmount()
          expect(observers[0].disconnect).toHaveBeenCalledTimes(1)
        },
      )
    })

    it('C08 leaves a detached row alone and creates no observer without a row', async () => {
      await withHead(
        sectionHeadInput({ page: 'demo_section_gaps' }),
        async (view, { observers }) => {
          const row = rowOf(view)
          view.root.remove()
          expect(row.isConnected).toBe(false)
          row.scrollLeft = 5
          expect(observers).toHaveLength(1)
          observers[0].resize()
          expect(row.scrollLeft).toBe(5)
          await view.update({ page: 'demo_section_gaps_machine' })
          expect(row.scrollLeft).toBe(5)
        },
      )
      await withHead(
        sectionHeadInput({
          identity: {
            ...SECTION,
            pageTabs: [{ page: 'demo_section', label: 'Overview' }],
          },
        }),
        async (view, { observers }) => {
          expect(view.root.querySelector('ul')).toBeNull()
          expect(observers).toHaveLength(0)
        },
      )
    })

    it('C09 preserves manual scroll when neither key has a current tab', async () => {
      await withHead(
        sectionHeadInput({ page: 'demo_elsewhere' }),
        async (view) => {
          const row = rowOf(view)
          row.scrollLeft = 25
          await view.update({ page: 'demo_nowhere' })
          expect(row.scrollLeft).toBe(25)
        },
      )
    })
  })
}
