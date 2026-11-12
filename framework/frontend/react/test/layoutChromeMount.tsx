import { act } from '@testing-library/react'
import { createRoot, type Root } from 'react-dom/client'
import type {
  LayoutChromeInput,
  LayoutChromeView,
} from '../../test-support/layoutChromeFixtures.js'
import { HilosLayout } from '../src/HilosLayout.js'
import { LoadingButton } from '../src/LoadingButton.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Create caller ReactNodes during native rendering; Layout draws its own chrome. */
function LayoutChromeHost({
  connection,
  banner,
  user,
  projectUserControls,
  nav,
  pageAction,
  router,
}: LayoutChromeInput) {
  return (
    <HilosRouterContext.Provider value={router ?? null}>
      <HilosLayout
        connection={connection}
        nav={
          nav ? (
            <>
              <a href="/hidden" hidden data-id="project-nav-hidden">
                Hidden
              </a>
              <a href="/first" data-id="project-nav-first">
                First
              </a>
              <a href="/second" data-id="project-nav-second">
                Second
              </a>
            </>
          ) : null
        }
        banner={
          banner === undefined ? undefined : (
            <p data-id={banner.dataId}>{banner.text}</p>
          )
        }
        user={
          projectUserControls ? (
            <>
              <span data-id="user-bell">Bell</span>
              <span data-id="user-avatar">Avatar</span>
              <a href="/signin" data-id="user-signin">
                Sign in
              </a>
            </>
          ) : user === undefined ? undefined : (
            <span data-id={user.dataId}>{user.text}</span>
          )
        }
      >
        {pageAction ? (
          <LoadingButton data-id="page-action">Send</LoadingButton>
        ) : (
          <p data-id="page-body">Page</p>
        )}
      </HilosLayout>
    </HilosRouterContext.Provider>
  )
}

/** Own one native React root; render and release each await their own act. */
export async function mountLayoutChrome(
  input: LayoutChromeInput,
  retain?: (view: LayoutChromeView) => void,
): Promise<LayoutChromeView> {
  const host = document.body.appendChild(document.createElement('div'))
  let nativeRoot: Root | undefined
  let current = input
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
    const errors: unknown[] = []
    try {
      await flush(() => nativeRoot?.unmount())
    } catch (error) {
      errors.push(error)
    }
    try {
      host.remove()
    } catch (error) {
      errors.push(error)
    }
    if (errors.length === 1) throw errors[0]
    if (errors.length > 1)
      throw new AggregateError(errors, 'Layout unmount failed')
  }
  const view: LayoutChromeView = {
    root: host,
    async update(patch) {
      await flush(() => {
        current = { ...current, ...patch }
        nativeRoot!.render(<LayoutChromeHost {...current} />)
      })
    },
    flush,
    unmount: destroy,
  }
  try {
    retain?.(view)
    nativeRoot = createRoot(host)
    await flush(() => nativeRoot!.render(<LayoutChromeHost {...current} />))
    return view
  } catch (error) {
    try {
      await destroy()
    } catch (cleanupError) {
      throw new AggregateError([error, cleanupError], 'Layout mount failed', {
        cause: cleanupError,
      })
    }
    throw error
  }
}
