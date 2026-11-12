// Native React effects and portals, with one owned container per outlet.
import { act } from '@testing-library/react'
import { useEffect, type ComponentType } from 'react'
import { createRoot } from 'react-dom/client'
import { vi } from 'vitest'
import { hilosAdminViews } from '@hilos/react/admin-views'

import type { WithHilosViewLazy } from '../../test-support/hilosViewLazyFixtures.js'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function MainPage() {
  return <div data-id="main">Leaving page</div>
}
function KnownPage() {
  return <div data-id="known-page">Known</div>
}
function MainSkeleton() {
  return <div data-id="main-skeleton" />
}

export const withHilosViewLazy: WithHilosViewLazy<ComponentType> = async (
  steps,
) => {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const releases: (() => Promise<void>)[] = []
  try {
    await steps({
      main: MainPage,
      known: KnownPage,
      skeleton: MainSkeleton,
      tracked(id) {
        const mounted = vi.fn()
        function TrackedPage() {
          useEffect(() => {
            mounted()
          }, [])
          return <div data-id={id}>{id}</div>
        }
        return { component: TrackedPage, mounted }
      },
      adminViews: hilosAdminViews,
      async mount(input) {
        const container = document.body.appendChild(
          document.createElement('div'),
        )
        const root = createRoot(container)
        let pages = input.pages
        const flush = async (change?: () => void) => {
          await act(async () => {
            change?.()
          })
        }
        let disposed = false
        const unmount = async () => {
          if (disposed) return
          disposed = true
          try {
            await act(async () => root.unmount())
          } finally {
            container.remove()
          }
        }
        releases.push(unmount)
        const content = () => (
          <HilosRouterContext.Provider value={input.router}>
            <HilosView {...input} pages={pages} />
          </HilosRouterContext.Provider>
        )
        await act(async () => root.render(content()))
        return {
          root: container,
          flush,
          async setPages(next) {
            pages = next
            await act(async () => root.render(content()))
          },
          unmount,
        }
      },
    })
  } finally {
    try {
      for (const release of releases.reverse()) await release()
    } finally {
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
