import { hilosToasts } from '@hilos/core/base'
import type * as Vitest from 'vitest'
import {
  FROZEN,
  layoutChromeConnection,
  type LayoutChromeInput,
  type LayoutChromeView,
  type MountLayoutChrome,
} from './layoutChromeFixtures.js'
import {
  layoutReconsentHandshake,
  layoutReconsentSession,
  withLayoutReconsentClock,
} from './layoutReconsentFixtures.js'

type ReconsentReminderRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect' | 'vi'
> & {
  releaseBindings?: (release: () => Promise<void>) => Promise<void>
}

function part(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector(`[data-id="${id}"]`)
}

/** First-frame legal reminders on each real SDK shell, before any content read. */
export function registerLayoutReconsentReminderCases(
  sdk: string,
  runner: ReconsentReminderRunner,
  mount: MountLayoutChrome,
): void {
  const { describe, it, expect, vi } = runner

  async function withLayout(
    facts: Parameters<typeof layoutReconsentHandshake>[0],
    connection: LayoutChromeInput['connection'],
    steps: (view: LayoutChromeView) => void | Promise<void>,
  ): Promise<void> {
    let session: Awaited<ReturnType<typeof layoutReconsentSession>> | undefined
    let view: LayoutChromeView | undefined
    try {
      session = await layoutReconsentSession()
      session.handshake(layoutReconsentHandshake(facts))
      view = await mount({ connection })
      await steps(view)
    } finally {
      try {
        await view?.unmount()
      } finally {
        try {
          if (session) {
            if (runner.releaseBindings)
              await runner.releaseBindings(session.release)
            else await session.release()
          }
        } finally {
          hilosToasts.clear()
        }
      }
    }
  }

  describe(`HilosLayout legal reminders on ${sdk}`, () => {
    it('UR01 names the days left without opening a window on the first frame', async () => {
      await withLayoutReconsentClock(vi, async () => {
        await withLayout(
          { window: [{ document: 'terms', deadline: '2026-11-10' }] },
          layoutChromeConnection(),
          ({ root }) => {
            expect(
              part(root, 'legal-reconsent-icon')?.getAttribute('title'),
            ).toBe('The terms have changed — 9 days left to decide')
            expect(part(root.ownerDocument, 'legal-reconsent-modal')).toBeNull()
          },
        )
      })
    })

    it('UR02 asks to review lapsed terms without freezing the content', async () => {
      await withLayout(
        { lapsed: [{ document: 'terms', deadline: '2026-09-01' }] },
        layoutChromeConnection(),
        ({ root }) => {
          expect(
            part(root, 'legal-reconsent-icon')?.getAttribute('title'),
          ).toBe('The terms have changed — please review them')
          expect(part(root, 'legal-frozen-screen')).toBeNull()
        },
      )
    })

    it('UR13 draws no legal reminder during node maintenance', async () => {
      await withLayout(
        { window: [{ document: 'terms', deadline: '2026-11-10' }] },
        layoutChromeConnection(FROZEN),
        ({ root }) => {
          expect(part(root, 'legal-reconsent-icon')).toBeNull()
        },
      )
    })
  })
}
