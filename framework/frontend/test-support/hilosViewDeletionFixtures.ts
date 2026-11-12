// External inputs and native component lifetimes for deletion in the outlet.
import {
  createHilosRouter,
  createPageRouter,
  createSignal,
  HilosPages,
  resolveHilosPath,
  type HilosPageIdentity,
  type HilosRouter,
  type PageSubscriptionError,
  type PageView,
} from '@hilos/core/base'
import type { Mock } from 'vitest'

import { PAGE_ERROR_NOT_FOUND } from '../core/src/protocol/pageError.js'

/** The ordinary refusal of the example country card. */
export const CARD_NOT_FOUND: PageSubscriptionError = {
  page: 'lazy',
  httpCode: 404,
  errorCode: PAGE_ERROR_NOT_FOUND,
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

/** Only the live deletion carries the server's transition sentence. */
export const CARD_DELETED: PageSubscriptionError = {
  ...CARD_NOT_FOUND,
  transitionMessage: 'It was deleted just now.',
}

export const CARD_IDENTITY: HilosPageIdentity = {
  label: 'Poland',
  lead: 'One country.',
  breadcrumb: [{ page: 'lazy', label: 'Poland' }],
  children: [],
}

/** An actual SDK card and its observed native mount lifecycle. */
export interface DeletionCard<T> {
  component: T
  mounted: Mock<() => void>
}

/** Only public outlet inputs; the real shell owns retention and focus return. */
export interface DeletionOutletInput<T> {
  router: HilosRouter
  pages: Record<string, PageView<T>>
}

/** Native lifecycle and input events, with real DOM nodes for shared assertions. */
export interface DeletionOutlet {
  readonly root: ParentNode
  flush(change?: () => void): Promise<void>
  type(input: HTMLInputElement, value: string): Promise<void>
}

export interface HilosViewDeletionKit<T> {
  main: T
  status: T
  card(preserve: boolean): DeletionCard<T>
  mount(input: DeletionOutletInput<T>): Promise<DeletionOutlet>
}

/** Includes cleanup on a failed mount, before the external router is stopped. */
export type WithHilosViewDeletion<T> = (
  steps: (kit: HilosViewDeletionKit<T>) => Promise<void>,
) => Promise<void>

/** Real navigation and subscription ordering, with a separately supplied answer. */
export function deletionRouter() {
  let pathname = '/lazy/1'
  const pageLoading = createSignal(false)
  const pageError = createSignal<PageSubscriptionError | null>(null)
  const pageIdentity = createSignal<HilosPageIdentity | undefined>(undefined)
  const awaitPageAnswer = () => {
    pageError.set(null)
    pageLoading.set(true)
  }
  const router = createHilosRouter(
    createPageRouter({
      main: { path: '/', admin: false },
      lazy: { path: '/lazy/{id?}', admin: true },
      [HilosPages.PUBLIC_STATUS]: {
        path: resolveHilosPath(HilosPages.PUBLIC_STATUS)!,
        admin: false,
      },
    }),
    {
      subscribe: awaitPageAnswer,
      unsubscribe: () => {
        pageLoading.set(false)
        pageError.set(null)
      },
      refuseUnbuilt: () => {},
      pageLoading,
      pageError,
      pageIdentity,
      dashboardSections: createSignal(undefined),
      clearPageError: () => pageError.set(null),
      denyCurrentPage: () => {},
      awaitPageAnswer,
    },
    {
      pathname: () => pathname,
      pushState: (next) => {
        pathname = next
      },
      replaceState: (next) => {
        pathname = next
      },
      onPopState: () => () => {},
    },
  )
  router.start()
  return {
    router,
    pageLoading,
    pageError,
    pageIdentity,
    answer() {
      pageError.set(null)
      pageLoading.set(false)
    },
  }
}
