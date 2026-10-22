import { type ScopeManager } from '../../state/ScopeManager.js'
import {
  createSignal,
  type ReadonlySignal,
  type Unsubscribe,
  type WritableSignal,
} from '../../state/signal.js'

/** One setup registration; its token protects a later producer on unmount. */
interface PageInstanceNameBinding {
  readonly source: ReadonlySignal<string | undefined>
  readonly token: symbol
}

const bindings = new WeakMap<
  ScopeManager,
  WritableSignal<ReadonlyMap<string, PageInstanceNameBinding>>
>()

/** The shared reactive registrations for one application's scopes. */
function bindingsFor(
  scopes: ScopeManager,
): WritableSignal<ReadonlyMap<string, PageInstanceNameBinding>> {
  let state = bindings.get(scopes)
  if (state === undefined) {
    state = createSignal<ReadonlyMap<string, PageInstanceNameBinding>>(
      new Map(),
    )
    bindings.set(scopes, state)
  }
  return state
}

/**
 * Bind a page-owned live name to an entity's page key during view setup.
 * Undefined leaves the catalog caption; every string, including '', overrides it.
 * The latest registration wins, and disposal never restores an older source.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param page The entity's page key, anywhere in the breadcrumb chain.
 * @param name A source reading the current page's permitted data.
 */
export function bindPageInstanceName(
  scopes: ScopeManager,
  page: string,
  name: ReadonlySignal<string | undefined>,
): Unsubscribe {
  const state = bindingsFor(scopes)
  const entry: PageInstanceNameBinding = { source: name, token: Symbol() }
  const next = new Map(state.get())
  next.set(page, entry)
  state.set(next)

  return () => {
    if (state.get().get(page)?.token !== entry.token) return
    const remaining = new Map(state.get())
    remaining.delete(page)
    state.set(remaining)
  }
}

/** Read the current source inside the identity projection's computed signal. */
export function pageInstanceName(
  scopes: ScopeManager,
  page: string,
): string | undefined {
  const state = bindingsFor(scopes)
  if (scopes.page() === undefined) return undefined
  return state.get().get(page)?.source.get()
}
