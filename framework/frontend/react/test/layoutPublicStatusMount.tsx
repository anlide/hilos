import { act } from '@testing-library/react'
import { createRoot } from 'react-dom/client'
import { HilosPages } from '@hilos/core/base'
import type {
  LayoutPublicStatusInput,
  LayoutPublicStatusView,
} from '../../test-support/layoutPublicStatusFixtures.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { HilosModal } from '../src/HilosModal.js'
import { HilosView } from '../src/HilosView.js'
import { HilosStatusPage } from '../src/public/HilosStatusPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** One provider and stable component identities through every route update. */
export async function mountLayoutPublicStatus(
  input: LayoutPublicStatusInput,
): Promise<LayoutPublicStatusView> {
  function StatusOutlet() {
    return (
      <HilosStatusPage connection={input.connection} actions={input.actions} />
    )
  }
  function OrdinaryPage() {
    return <p data-id="ordinary-page">Ordinary</p>
  }
  function AuthSurface() {
    return <form data-id="test-sign-in" />
  }
  const pages = {
    [HilosPages.PUBLIC_STATUS]: StatusOutlet,
    [HilosPages.ABOUT]: OrdinaryPage,
    [HilosPages.TERMS]: OrdinaryPage,
    [HilosPages.STATUS]: OrdinaryPage,
    main: OrdinaryPage,
    ordinary: OrdinaryPage,
  }
  const host = document.createElement('div')
  const root = createRoot(host)
  let allow = input.allow ?? false
  let modalOpen = input.modalOpen ?? false
  const draw = () => (
    <HilosRouterContext.Provider value={input.router}>
      <HilosLayout
        connection={input.connection}
        brand={input.brand}
        allowDisconnectedInteraction={allow}
        user={
          <HilosModal
            open={modalOpen}
            aria-label="Protected action"
            initialFocus="dialog"
          >
            <button
              type="button"
              data-id="shell-dialog-action"
              onClick={input.onSave}
            >
              Save
            </button>
          </HilosModal>
        }
      >
        <HilosView pages={pages} authSurface={AuthSurface} />
      </HilosLayout>
    </HilosRouterContext.Provider>
  )
  const update: LayoutPublicStatusView['update'] = (props) => {
    if (props.allow !== undefined) allow = props.allow
    if (props.modalOpen !== undefined) modalOpen = props.modalOpen
    root.render(draw())
  }
  async function flush(run?: () => void | Promise<void>): Promise<void> {
    await act(async () => {
      await run?.()
      await Promise.resolve()
    })
  }
  let disposed = false
  async function unmount(): Promise<void> {
    if (disposed) return
    disposed = true
    const errors: unknown[] = []
    try {
      await act(() => root.unmount())
    } catch (error) {
      errors.push(error)
    }
    try {
      await flush()
    } catch (error) {
      errors.push(error)
    } finally {
      host.remove()
    }
    if (errors.length)
      throw new AggregateError(errors, 'React public Status disposal failed')
  }
  const view = { root: host, update, flush, unmount }
  input.retain(view)
  document.body.appendChild(host)
  await flush(() => root.render(draw()))
  return view
}
