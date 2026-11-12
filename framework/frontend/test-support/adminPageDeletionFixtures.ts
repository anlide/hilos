// Inputs and observations specific to AdminPage's deletion boundary.
import {
  FocusTrap,
  HilosPages,
  registerFocusReturnFallback,
  type HilosPageIdentity,
  type PageSubscriptionError,
  type PageViewState,
} from '@hilos/core/base'
import { PAGE_ERROR_NOT_FOUND } from '../core/src/protocol/pageError.js'
import {
  adminPageRouter,
  SECTION_IDENTITY,
  type AdminPageInput,
} from './adminPageFixtures.js'

export interface AdminPageDeletionContent {
  preserve: boolean
  modalsMounted: () => void
  retain?: PageViewState<unknown>['retainForResourceDeletion']
}

/** The section's live refusal, rather than a newly mounted error page. */
export const ADMIN_PAGE_DELETED: PageSubscriptionError = {
  page: HilosPages.I18N,
  httpCode: 404,
  errorCode: PAGE_ERROR_NOT_FOUND,
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
  transitionMessage: 'It was deleted just now.',
}

/** Supply a held body and modal probe; native children record their mounts. */
export function adminPageDeletionInput(
  identity: HilosPageIdentity = SECTION_IDENTITY,
  preserve = true,
  retain?: AdminPageDeletionContent['retain'],
) {
  const navigation = adminPageRouter(identity)
  const modals = { mounts: 0 }
  const input: AdminPageInput = {
    page: HilosPages.I18N,
    router: navigation.router,
    deletion: {
      preserve,
      modalsMounted: () => {
        modals.mounts++
      },
      retain,
    },
  }
  return { input, pageError: navigation.pageError, modals }
}

/** Record the actual DOM at each call of the controlled outlet factory. */
export function adminPageRetention() {
  const pages: string[] = []
  const shown: boolean[] = []
  const live = new Set<symbol>()
  const retain: NonNullable<AdminPageDeletionContent['retain']> = (page) => {
    const registration = Symbol(page)
    pages.push(page)
    shown.push(
      document.querySelector('[data-id="hilos-admin-page"]')?.isConnected ===
        true,
    )
    live.add(registration)
    return () => {
      live.delete(registration)
    }
  }
  return { retain, pages, shown, live }
}

/** A real focus trap with an independent outer offer and owned DOM. */
export function adminPageTrapFrom(opener: HTMLElement) {
  const outer = document.body.appendChild(document.createElement('button'))
  outer.type = 'button'
  const releaseOuter = registerFocusReturnFallback(document.body, () => outer)
  const dialog = document.body.appendChild(document.createElement('div'))
  dialog.tabIndex = -1
  const trap = new FocusTrap()
  function dispose(): void {
    try {
      trap.release()
    } finally {
      releaseOuter()
      dialog.remove()
      outer.remove()
    }
  }
  try {
    opener.focus()
    trap.activate(dialog, 'dialog')
    return { outer, close: () => trap.release(), dispose }
  } catch (error) {
    dispose()
    throw error
  }
}

/** Observe withdrawal itself, even when a leaked listener would return null. */
export function adminPageFocusRequest(opener: HTMLElement) {
  const request: { fallback?: () => HTMLElement | null } = {}
  // This DOM event name is private to FocusTrap and has no exported owner.
  opener.dispatchEvent(
    new CustomEvent('hilos-focus-return-request', {
      bubbles: true,
      detail: request,
    }),
  )
  return request
}
