// The injection token carrying the routed outlet's offer to the page shell it
// mounts: register as the shell that draws the deletion of its own resource
// (HIL-1603). HilosView provides its page-view state's registration, and only
// that function, never the state, to the page it mounts; a shell that opts in
// calls it once its page is on screen and releases it on destroy. Outside an
// outlet nothing is provided, and an opted-in shell stands alone, drawing the
// page error itself. Internal to the SDK: the outlet and the shell are its only
// two ends.
import { InjectionToken } from '@angular/core'
import type { PageViewState } from '@hilos/core/base'

/**
 * Provide/inject token for the outlet's
 * {@link PageViewState.retainForResourceDeletion}.
 */
export const HILOS_PAGE_ERROR_BOUNDARY = new InjectionToken<
  PageViewState<unknown>['retainForResourceDeletion']
>('HilosPageErrorBoundary')
