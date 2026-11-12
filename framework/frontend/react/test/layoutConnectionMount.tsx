import { act } from '@testing-library/react'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { vi } from 'vitest'
import type {
  LayoutConnectionInput,
  LayoutConnectionStandalone,
  LayoutConnectionView,
} from '../../test-support/layoutConnectionFixtures.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosModal } from '../src/HilosModal.js'
import { LoadingButton } from '../src/LoadingButton.js'
import { useConnectionInteraction } from '../src/hilosConnectionInteraction.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function ConnectionProbe({
  capture,
}: {
  capture: (value: LayoutConnectionView['interaction']) => void
}) {
  capture(useConnectionInteraction())
  return null
}

/** A per-root native lifetime, including actual StrictMode effect replay. */
export async function mountLayoutConnection(
  input: LayoutConnectionInput,
): Promise<LayoutConnectionView> {
  let current = input
  const consumers = {} as Record<
    'page' | 'shell' | 'modal',
    LayoutConnectionView['interaction']
  >
  const capture =
    (place: keyof typeof consumers) =>
    (value: LayoutConnectionView['interaction']) => {
      consumers[place] = value
    }
  const clicks = vi.fn()
  const draw = () => (
    <StrictMode>
      <HilosRouterContext.Provider value={current.router ?? null}>
        <HilosLayout
          connection={current.connection}
          allowDisconnectedInteraction={current.allow ?? false}
          nav={<a href="/next">Next</a>}
          user={<ConnectionProbe capture={capture('shell')} />}
        >
          <ConnectionProbe capture={capture('page')} />
          <HilosAdminViewModeContext.Provider value={true}>
            <LoadingButton data-id="page-view" aria-describedby="validation">
              View
            </LoadingButton>
          </HilosAdminViewModeContext.Provider>
          <input
            data-id="draft"
            aria-describedby={current.description ?? 'validation'}
            disabled={current.disabled ?? false}
          />
          <input type="file" data-id="file" />
          <select data-id="choice" defaultValue="a">
            <option value="a">A</option>
            <option value="b">B</option>
          </select>
          <LoadingButton opensWindow onClick={clicks} data-id="page-open">
            Open
          </LoadingButton>
          <a href="/action" onClick={clicks} data-id="page-link">
            Action
          </a>
          <HilosModal
            open={current.modalOpen ?? false}
            title="Edit draft"
            confirmOnClose
          >
            <div data-id="connection-modal-body">
              <ConnectionProbe capture={capture('modal')} />
              <input data-id="modal-initial" data-autofocus="" />
              <input data-id="modal-draft" />
            </div>
          </HilosModal>
        </HilosLayout>
      </HilosRouterContext.Provider>
    </StrictMode>
  )
  const host = document.body.appendChild(document.createElement('div'))
  const view = createRoot(host)
  let destroyed = false
  async function flush(run?: () => void): Promise<void> {
    await act(async () => {
      run?.()
      await Promise.resolve()
    })
  }
  async function destroy(): Promise<void> {
    if (destroyed) return
    destroyed = true
    try {
      await act(async () => view.unmount())
    } finally {
      host.remove()
    }
  }
  try {
    await flush(() => view.render(draw()))
    return {
      root: host,
      clicks,
      get interaction() {
        return consumers.page
      },
      consumers,
      async update(patch) {
        await flush(() => {
          current = { ...current, ...patch }
          view.render(draw())
        })
      },
      flush,
      unmount: destroy,
    }
  } catch (error) {
    try {
      await destroy()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React connection mount and cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}

/** A standalone hook consumer still runs through real StrictMode rendering. */
export async function mountStandaloneConnection(): Promise<LayoutConnectionStandalone> {
  let interaction: LayoutConnectionStandalone['interaction'] | undefined
  const host = document.body.appendChild(document.createElement('div'))
  const root = createRoot(host)
  let destroyed = false
  async function unmount(): Promise<void> {
    if (destroyed) return
    destroyed = true
    try {
      await act(async () => root.unmount())
    } finally {
      host.remove()
    }
  }
  try {
    await act(async () =>
      root.render(
        <StrictMode>
          <ConnectionProbe
            capture={(value) => {
              interaction = value
            }}
          />
        </StrictMode>,
      ),
    )
    return { interaction: interaction!, unmount }
  } catch (error) {
    try {
      await unmount()
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'React standalone cleanup failed',
        { cause: cleanupError },
      )
    }
    throw error
  }
}
