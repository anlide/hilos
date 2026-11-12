import { act, cleanup, render } from '@testing-library/react'
import { useEffect, useRef, useState } from 'react'
import {
  layoutLifetimeFixture,
  type LayoutLifetimeEvent,
  type LayoutLifetimeOptions,
  type LayoutLifetimeView,
} from '../../test-support/layoutLifetimeFixtures.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Effect receipts record committed components; render calls are not mounts. */
function LayoutLifetimeProbe({ events }: { events: LayoutLifetimeEvent[] }) {
  const [draft, setDraft] = useState('')
  const instance = useRef({}).current
  useEffect(() => {
    events.push({ phase: 'start', instance })
    return () => {
      events.push({ phase: 'stop', instance })
    }
  }, [events, instance])
  return (
    <div data-id="lifetime-page">
      <input
        data-id="lifetime-draft"
        aria-label="Local draft"
        value={draft}
        onInput={(event) => setDraft(event.currentTarget.value)}
      />
    </div>
  )
}

/** Exercise ordinary children without StrictMode's separate effect replay. */
export async function mountLayoutLifetime(
  options: LayoutLifetimeOptions,
): Promise<LayoutLifetimeView> {
  const scope = layoutLifetimeFixture(options)
  const host = document.createElement('div')
  let view: { unmount(): void } | undefined
  async function flush(run?: () => void): Promise<void> {
    await act(async () => {
      run?.()
      await Promise.resolve()
    })
  }
  let disposed = false
  async function unmount(): Promise<void> {
    if (disposed) return
    disposed = true
    try {
      await act(async () => {
        view?.unmount()
        cleanup()
      })
    } finally {
      host.remove()
      await act(() => scope.dispose())
    }
  }
  try {
    document.body.appendChild(host)
    view = render(
      <HilosRouterContext.Provider value={scope.router}>
        <HilosLayout
          connection={scope.feed.connection}
          brand={<span data-id="lifetime-brand">Brand</span>}
          nav={<span data-id="lifetime-nav">Navigation</span>}
          user={<span data-id="lifetime-user">Person</span>}
          banner={<span data-id="lifetime-banner">Notice</span>}
        >
          <LayoutLifetimeProbe events={scope.events} />
        </HilosLayout>
      </HilosRouterContext.Provider>,
      { container: host },
    )
    await flush()
    return {
      root: host,
      events: scope.events,
      feed: scope.feed,
      flush,
      unmount,
    }
  } catch (error) {
    await unmount()
    throw error
  }
}
