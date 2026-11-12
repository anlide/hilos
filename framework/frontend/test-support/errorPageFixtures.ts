// Controlled page errors and router input for the real SDK error surfaces.
import {
  createSignal,
  type HilosRouter,
  type PageRouteMatch,
  type PageSubscriptionError,
} from '@hilos/core/base'

import { PAGE_ERROR_NOT_FOUND } from '../core/src/protocol/pageError.js'

export const NOT_FOUND: PageSubscriptionError = {
  page: 'user',
  httpCode: 404,
  errorCode: PAGE_ERROR_NOT_FOUND,
  title: 'Page Not Found',
  message: 'The page you are looking for does not exist.',
}

export const FORBIDDEN: PageSubscriptionError = {
  page: 'user',
  httpCode: 403,
  errorCode: 'forbidden',
  title: 'Forbidden',
  message: 'You do not have access to this page.',
}

/** A live refusal whose resource disappeared while the page stood open. */
export const DELETED: PageSubscriptionError = {
  ...NOT_FOUND,
  transitionMessage: 'It was deleted just now.',
}

export const UNAUTHORIZED: PageSubscriptionError = {
  page: 'user',
  httpCode: 401,
  errorCode: 'unauthorized',
  title: 'Unauthorized',
  message: 'Authentication required',
}

/** External navigator input; rendering and auth decisions belong to HilosView. */
export function routerWithError(
  pageError: PageSubscriptionError | null,
): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: 'user',
      params: {},
      admin: false,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal<PageSubscriptionError | null>(pageError),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => '/user',
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}
