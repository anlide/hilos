// usePageInstanceName — the React lifecycle of a page's live instance name
// (docs/agents/architecture/page-head.md). The core owns the binding: the
// registry per scope manager, the latest producer winning, a disposer that
// removes only its own registration. This hook only places that binding on the
// committed view: registered in a layout effect, released by the same effect's
// cleanup, never in render, so an interrupted or replayed render leaves no
// producer behind and a server render registers nothing.
import { useLayoutEffect, useReducer } from 'react'
import { bindPageInstanceName } from '@hilos/core/base'
import type { ReadonlySignal, ScopeManager } from '@hilos/core/base'

/**
 * Bind a page-owned live name to an entity's page key while the calling view is
 * committed: the breadcrumb entry, the owner's head and the browser title of
 * that key read it through the core page identity. Undefined leaves the catalog
 * caption; every string, including '', overrides it.
 *
 * Unmounting, or a new scopes, page or source, releases the registration.
 * After it registers, the hook re-renders its own subtree before paint, so a
 * reader below shows the name in the first paint, without waiting for its own
 * subscription. Pass a stable source, one signal for the view's lifetime:
 * each new source rebinds and re-renders, so one made anew in render never
 * settles.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param page The entity's page key, anywhere in the breadcrumb chain.
 * @param name A source reading the current page's permitted data.
 */
export function usePageInstanceName(
  scopes: ScopeManager,
  page: string,
  name: ReadonlySignal<string | undefined>,
): void {
  // A lifecycle pulse, not the name: the identity stays in the core.
  const [, refresh] = useReducer((pulse: number) => pulse + 1, 0)

  useLayoutEffect(() => {
    const dispose = bindPageInstanceName(scopes, page, name)
    refresh()
    return dispose
  }, [scopes, page, name])
}
