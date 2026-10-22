// The injection key carrying the routed outlet's offer to the page shell it
// mounts: register as the shell that draws the deletion of its own resource
// (HIL-1603). HilosView provides its page-view state's registration, and only
// that function, never the state; a shell that opts in calls it once its page is
// on screen and releases it on unmount. Outside an outlet nothing is provided,
// and an opted-in shell stands alone, drawing the page error itself. Internal to
// the SDK: the outlet and the shell are its only two ends.
import type { InjectionKey } from 'vue'
import type { PageViewState } from '@hilos/core/base'

/** Provide/inject key for the outlet's {@link PageViewState.retainForResourceDeletion}. */
export const hilosPageErrorBoundaryKey: InjectionKey<
  PageViewState<unknown>['retainForResourceDeletion']
> = Symbol('hilosPageErrorBoundary')
