// Native React modal portals and optional development effect replay for X01.
import { act } from '@testing-library/react'
import { StrictMode, useState, type ComponentType } from 'react'
import { createRoot } from 'react-dom/client'

import type { WithHilosViewAccess } from '../../test-support/hilosViewAccessFixtures.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView } from '../src/HilosView.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { shellPage } from './hilosViewDeletion.js'

function DirtyPage() {
  const [open, setOpen] = useState(false)
  return (
    <div data-id="private-page">
      <button type="button" data-id="open-draft" onClick={() => setOpen(true)}>
        Edit
      </button>
      <HilosModal
        open={open}
        title="Edit"
        confirmOnClose
        onClose={() => setOpen(false)}
      >
        <input data-id="draft" data-autofocus="" />
      </HilosModal>
    </div>
  )
}
function AuthSurface() {
  return (
    <form data-id="auth-surface">
      <input data-id="auth-identifier" data-autofocus="" />
    </form>
  )
}
function KnownPage() {
  return <div data-id="known-page">Known</div>
}
function PublicFileReader() {
  return <div data-id="public-file-reader">Public file reader</div>
}

/** StrictMode is a native lifetime input, never an expected value in the adapter. */
export async function withHilosViewAccess(
  steps: Parameters<WithHilosViewAccess<ComponentType>>[0],
  afterUnmount: () => void,
  strict = false,
): Promise<void> {
  const previousFocus = document.activeElement
  const modalOpen = document.body.classList.contains('modal-open')
  const releases: (() => Promise<void>)[] = []
  const hosts: HTMLElement[] = []
  try {
    await steps({
      dirty: DirtyPage,
      auth: AuthSurface,
      known: KnownPage,
      status: PublicFileReader,
      card: shellPage,
      async mount({ router, ...input }) {
        const container = document.body.appendChild(
          document.createElement('div'),
        )
        hosts.push(container)
        const root = createRoot(container)
        const flush = async (change?: () => void) => {
          await act(async () => {
            change?.()
          })
        }
        releases.push(async () => {
          await act(async () => root.unmount())
        })
        const view = (
          <HilosRouterContext.Provider value={router}>
            <HilosView {...input} />
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
              input.value = value
              input.dispatchEvent(new Event('input', { bubbles: true }))
            })
          },
        }
      },
    })
  } finally {
    try {
      for (const release of releases.reverse()) await release()
    } finally {
      afterUnmount()
      for (const host of hosts) host.remove()
      document.body.classList.toggle('modal-open', modalOpen)
      if (previousFocus instanceof HTMLElement && previousFocus.isConnected)
        previousFocus.focus()
    }
  }
}
