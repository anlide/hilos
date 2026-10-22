// React context carrying the routed outlet's offer to the page shell it mounts:
// register as the shell that draws the deletion of its own resource (HIL-1603).
// HilosView provides its page-view state's registration, and only that function,
// never the state; a shell that opts in calls it from a layout effect once its
// page is on screen and releases it in that effect's cleanup. Outside an outlet
// nothing is provided, and an opted-in shell stands alone, drawing the page error
// itself. Internal to the SDK: the outlet and the shell are its only two ends.
import { createContext } from 'react'
import type { PageViewState } from '@hilos/core/base'

/**
 * Provides the outlet's {@link PageViewState.retainForResourceDeletion};
 * undefined outside a routed outlet.
 */
export const HilosPageErrorBoundaryContext = createContext<
  PageViewState<unknown>['retainForResourceDeletion'] | undefined
>(undefined)
