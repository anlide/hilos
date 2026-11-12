// Native React effects, controlled input events and the modal's actual portal.
import { act, fireEvent } from '@testing-library/react'
import { StrictMode, useEffect, useState } from 'react'
import type { ComponentType } from 'react'
import { createRoot } from 'react-dom/client'
import { vi } from 'vitest'

import type { WithHilosViewDeletion } from '../../test-support/hilosViewDeletionFixtures.js'
import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/**
 * A card on the admin shell with one dialog in modals, opened from a button in
 * its content. The draft is the card's own state, so it lives exactly as long as
 * the card's instance does.
 *
 * @param preserveModalDraftsOnDelete Whether the card opts in.
 */
export function shellPage(preserveModalDraftsOnDelete: boolean) {
  const mounted = vi.fn()
  function Card() {
    const [open, setOpen] = useState(false)
    const [draft, setDraft] = useState('')
    useEffect(() => {
      mounted()
    }, [])

    return (
      <HilosAdminPage
        page="lazy"
        preserveModalDraftsOnDelete={preserveModalDraftsOnDelete}
        modals={
          <HilosModal open={open} title="Rename" onClose={() => setOpen(false)}>
            <input
              data-id="draft"
              data-autofocus=""
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
            />
          </HilosModal>
        }
      >
        <p data-id="page-body">The card</p>
        <button
          type="button"
          data-id="open-draft"
          onClick={() => setOpen(true)}
        >
          Rename
        </button>
      </HilosAdminPage>
    )
  }

  return { component: Card, mounted }
}

function MainPage() {
  return <div data-id="main">Known</div>
}
function PublicFileReader() {
  return <div data-id="public-file-reader">Public file reader</div>
}

/** StrictMode is a local lifecycle input, never an expected mount count. */
export async function withHilosViewDeletion(
  steps: Parameters<WithHilosViewDeletion<ComponentType>>[0],
  strict = false,
): Promise<void> {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const releases: (() => Promise<void>)[] = []
  try {
    await steps({
      main: MainPage,
      status: PublicFileReader,
      card: shellPage,
      async mount({ router, pages }) {
        const container = document.body.appendChild(
          document.createElement('div'),
        )
        const root = createRoot(container)
        let disposed = false
        const flush = async (change?: () => void) => {
          await act(async () => {
            change?.()
          })
        }
        releases.push(async () => {
          if (disposed) return
          disposed = true
          try {
            await act(async () => root.unmount())
          } finally {
            container.remove()
          }
        })
        const view = (
          <HilosRouterContext.Provider value={router}>
            <HilosView pages={pages} />
          </HilosRouterContext.Provider>
        )
        await act(async () =>
          root.render(strict ? <StrictMode>{view}</StrictMode> : view),
        )
        return {
          root: container,
          flush,
          async type(input, value) {
            await flush(() => {
              fireEvent.change(input, { target: { value } })
            })
          },
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
