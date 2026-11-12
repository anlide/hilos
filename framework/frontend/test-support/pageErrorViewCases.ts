// HilosView's existing error and auth groups, with real per-SDK outlets.
import {
  AUTH_SURFACE_HEADING_ID,
  createSignal,
  type AuthGate,
  type HilosRouter,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

import {
  NOT_FOUND,
  routerWithError,
  UNAUTHORIZED,
} from './errorPageFixtures.js'

type PageErrorViewTestRunner = Pick<typeof Vitest, 'describe' | 'it' | 'expect'>

/** Controlled external inputs, never a replacement for the outlet's decisions. */
export interface PageErrorViewInput {
  router: HilosRouter
  authSurface?: { heading?: string }
  authGate?: AuthGate
}

/** The real outlet, including its auth modal wherever the SDK mounts it. */
export interface PageErrorView {
  readonly root: ParentNode
  flush(change?: () => void): Promise<void>
}

/** Keep creation and every step inside the framework's cleanup lifetime. */
export type WithPageErrorView = (
  input: PageErrorViewInput,
  steps: (view: PageErrorView) => Promise<void>,
) => Promise<void>

/** A gate's controlled signal is input to the real outlet and modal. */
function gate(open = false): AuthGate {
  return {
    modalOpen: createSignal(open),
    requireAuth: () => {},
    dismiss: () => {},
  }
}

/** Register the complete error/auth union from the ErrorPage discovery entries. */
export function registerPageErrorViewCases(
  sdk: string,
  runner: PageErrorViewTestRunner,
  withPageErrorView: WithPageErrorView,
): void {
  const { describe, it, expect } = runner
  const errorSelector = '[data-id="page-error"]'
  const pageSelector = '[data-id="user-page"]'
  const authSelector = '[data-id="auth-surface"]'
  const modalSelector = '[data-id="modal"]'

  describe(`HilosView page error on ${sdk}`, () => {
    it('renders the error surface in place of the mapped page', async () => {
      await withPageErrorView(
        { router: routerWithError(NOT_FOUND) },
        async ({ root }) => {
          expect(root.querySelector(errorSelector)).not.toBeNull()
          expect(root.querySelector(pageSelector)).toBeNull()
        },
      )
    })

    it('renders the mapped page when there is no error', async () => {
      await withPageErrorView(
        { router: routerWithError(null) },
        async ({ root }) => {
          expect(root.querySelector(pageSelector)).not.toBeNull()
          expect(root.querySelector(errorSelector)).toBeNull()
        },
      )
    })
  })

  describe(`HilosView auth gate on ${sdk}`, () => {
    it('mounts the auth surface in place of ErrorPage on an anonymous 401', async () => {
      await withPageErrorView(
        {
          router: routerWithError(UNAUTHORIZED),
          authSurface: {},
          authGate: gate(),
        },
        async ({ root }) => {
          expect(root.querySelector(authSelector)).not.toBeNull()
          expect(root.querySelector(errorSelector)).toBeNull()
          expect(root.querySelector(pageSelector)).toBeNull()
        },
      )
    })

    it('still renders ErrorPage for a non-401 error', async () => {
      await withPageErrorView(
        {
          router: routerWithError(NOT_FOUND),
          authSurface: {},
          authGate: gate(),
        },
        async ({ root }) => {
          expect(root.querySelector(errorSelector)).not.toBeNull()
          expect(root.querySelector(authSelector)).toBeNull()
        },
      )
    })

    it('falls back to ErrorPage on a 401 without a registered surface', async () => {
      await withPageErrorView(
        { router: routerWithError(UNAUTHORIZED) },
        async ({ root }) => {
          expect(root.querySelector(errorSelector)).not.toBeNull()
          expect(root.querySelector(authSelector)).toBeNull()
        },
      )
    })

    it('shows the same auth surface in a modal when the gate opens', async () => {
      const modalOpen = createSignal(false)
      await withPageErrorView(
        {
          router: routerWithError(null),
          authSurface: {},
          authGate: { ...gate(), modalOpen },
        },
        async (view) => {
          expect(view.root.querySelector(modalSelector)).toBeNull()
          expect(view.root.querySelector(authSelector)).toBeNull()
          await view.flush(() => modalOpen.set(true))
          const modal = view.root.querySelector(modalSelector)
          expect(modal).not.toBeNull()
          expect(modal?.getAttribute('role')).toBe('dialog')
          expect(modal?.getAttribute('aria-modal')).toBe('true')
          expect(modal?.querySelector(authSelector)).not.toBeNull()
          expect(view.root.querySelectorAll(authSelector)).toHaveLength(1)
          expect(view.root.querySelector(pageSelector)).not.toBeNull()
        },
      )
    })

    it('names the modal with the heading the surface draws', async () => {
      await withPageErrorView(
        {
          router: routerWithError(null),
          authSurface: { heading: 'Confirm your email' },
          authGate: gate(true),
        },
        async ({ root }) => {
          const dialog = root.querySelector(modalSelector)
          expect(dialog).not.toBeNull()
          expect(dialog?.getAttribute('aria-labelledby')).toBe(
            AUTH_SURFACE_HEADING_ID,
          )
          const heading = root.querySelector(`#${AUTH_SURFACE_HEADING_ID}`)
          expect(heading?.textContent).toBe('Confirm your email')
          expect(dialog?.contains(heading)).toBe(true)
          expect(dialog?.getAttribute('aria-label')).toBe('Sign in')
        },
      )
    })

    it('keeps the Sign in name for a surface that carries no heading', async () => {
      await withPageErrorView(
        {
          router: routerWithError(null),
          authSurface: {},
          authGate: gate(true),
        },
        async ({ root }) => {
          expect(root.querySelector(`#${AUTH_SURFACE_HEADING_ID}`)).toBeNull()
          const dialog = root.querySelector(modalSelector)
          expect(dialog).not.toBeNull()
          expect(dialog?.getAttribute('aria-label')).toBe('Sign in')
        },
      )
    })
  })
}
