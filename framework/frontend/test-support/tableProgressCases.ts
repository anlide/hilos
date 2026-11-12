import type { HilosTableProgress } from '@hilos/core/base'
import type * as Vitest from 'vitest'

/** Static inputs handed to the real progress component. */
export interface TableProgressInput {
  progress: HilosTableProgress
  label: string
}

/** The owned host and the native component's teardown. */
export interface TableProgressView {
  readonly root: HTMLElement
  unmount(): Promise<void> | void
}

type TableProgressRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>
type MountTableProgress = (
  input: TableProgressInput,
) => Promise<TableProgressView>

/** The core has already computed the fraction, independently of these counts. */
function input(
  fraction: number | null,
  label = 'Work on this table',
): TableProgressInput {
  return {
    progress: {
      progressKey: 'nightly',
      current: 34,
      total: fraction === null ? null : 120,
      fraction,
      detail: {},
    },
    label,
  }
}

/** A missing real progressbar must fail even when checking an absent attribute. */
function progressbar(root: HTMLElement): HTMLElement {
  const track = root.querySelector<HTMLElement>('[role="progressbar"]')
  if (track === null) throw new Error('Missing table progressbar')
  return track
}

/** Preserve assertion and teardown failures and check the released owned host. */
async function withView(
  runner: TableProgressRunner,
  mount: MountTableProgress,
  props: TableProgressInput,
  steps: (root: HTMLElement) => void,
): Promise<void> {
  const view = await mount(props)
  const errors: unknown[] = []
  try {
    steps(view.root)
  } catch (error) {
    errors.push(error)
  } finally {
    try {
      await view.unmount()
      runner.expect(view.root.childElementCount).toBe(0)
      runner.expect(view.root.isConnected).toBe(false)
    } catch (error) {
      errors.push(error)
    }
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(errors, 'Table progress case and cleanup failed')
}

/** Register the shared percentage, unknown-total and accessible-name contract. */
export function registerTableProgressCases(
  sdk: string,
  runner: TableProgressRunner,
  mount: MountTableProgress,
): void {
  const { describe, it, expect } = runner
  describe(`HilosTableProgress on ${sdk}`, () => {
    it('G01 reports the supplied fraction as a percentage with its bounds', async () => {
      await withView(runner, mount, input(0.284), (root) => {
        const track = progressbar(root)
        expect(track.getAttribute('aria-valuenow')).toBe('28')
        expect(track.getAttribute('aria-valuemin')).toBe('0')
        expect(track.getAttribute('aria-valuemax')).toBe('100')
      })
    })

    it('G02 reports no number when the total is unknown', async () => {
      await withView(runner, mount, input(null), (root) => {
        expect(progressbar(root).hasAttribute('aria-valuenow')).toBe(false)
      })
    })

    it('G03 takes its accessible name from the place that draws it', async () => {
      await withView(runner, mount, input(0.5, 'Work on this row'), (root) => {
        expect(progressbar(root).getAttribute('aria-label')).toBe(
          'Work on this row',
        )
      })
    })
  })
}
