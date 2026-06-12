// Scope-partitioned ownership of entity data: four scope lifetimes — Page
// (dropped on navigation), Session (per connection, dies with the tab), User
// (shared across the user's tabs), Group (per active group subscription,
// survives navigation) — and most-specific-first reference resolution across
// them. Dropping a scope frees everything it owns; there is no LRU and no
// ref-counting, because active-scope data is always re-streamed fresh.

import { computedSignal, createSignal, type ReadonlySignal } from './signal.js'
import { DataStore } from './DataStore.js'
import {
  EntityStore,
  type EntityRef,
  type EntitySnapshot,
} from './EntityStore.js'
import { ListStore } from './ListStore.js'

export type ScopeKind = 'page' | 'session' | 'user' | 'group'

/**
 * One scope instance and the stores it owns: its normalized entities and its
 * own data.
 */
export class Scope {
  readonly entities = new EntityStore()

  readonly data = new DataStore()

  readonly lists = new ListStore()

  constructor(
    /** The scope's lifetime class. */
    readonly kind: ScopeKind,
    /** The pageKey or groupKey; session and user scopes carry none. */
    readonly key?: string,
  ) {}
}

export class ScopeManager {
  /** Per-connection scope; lives as long as the manager (the tab). */
  readonly session = new Scope('session')

  /** Per-user scope, fed by pushes to all the user's connections. */
  readonly user = new Scope('user')

  private readonly pageSignal = createSignal<Scope | undefined>(undefined)

  private readonly groupsSignal = createSignal<ReadonlyMap<string, Scope>>(
    new Map(),
  )

  /** The current page scope; `undefined` between page subscriptions. */
  page(): Scope | undefined {
    return this.pageSignal.get()
  }

  /**
   * Open the page scope for a page subscription, dropping the previous page
   * scope first: a tab shows 0..1 pages and navigation never reuses page data.
   *
   * @param pageKey The page being subscribed.
   */
  openPage(pageKey: string): Scope {
    const scope = new Scope('page', pageKey)
    this.pageSignal.set(scope)

    return scope
  }

  /** Drop the page scope on navigation away; a no-op when none is open. */
  dropPage(): void {
    this.pageSignal.set(undefined)
  }

  /**
   * The open group scope for `groupKey`, if any.
   *
   * @param groupKey The group subscription's key.
   */
  group(groupKey: string): Scope | undefined {
    return this.groupsSignal.get().get(groupKey)
  }

  /**
   * Open a group scope for an active group subscription (0..N may be open).
   *
   * @param groupKey The group subscription's key; opening it twice is a bug.
   */
  openGroup(groupKey: string): Scope {
    const current = this.groupsSignal.get()
    if (current.has(groupKey)) {
      throw new Error(`Group scope '${groupKey}' is already open`)
    }
    const scope = new Scope('group', groupKey)
    const next = new Map(current)
    next.set(groupKey, scope)
    this.groupsSignal.set(next)

    return scope
  }

  /**
   * Drop a group scope when its subscription ends, freeing its entities.
   *
   * @param groupKey The group subscription's key; dropping an unopened one is a bug.
   */
  dropGroup(groupKey: string): void {
    const current = this.groupsSignal.get()
    if (!current.has(groupKey)) {
      throw new Error(`Group scope '${groupKey}' is not open`)
    }
    const next = new Map(current)
    next.delete(groupKey)
    this.groupsSignal.set(next)
  }

  /**
   * Resolve an entity reference most-specific-first — Page → Session → User →
   * Group, groups in opening order — reactively: the selector re-resolves when
   * the entity changes in the winning scope, appears in a more specific one,
   * or its scope is dropped and a less specific copy takes over.
   *
   * @param ref The entity reference to resolve.
   */
  entitySignal(ref: EntityRef): ReadonlySignal<EntitySnapshot | undefined> {
    return computedSignal(() => {
      const page = this.pageSignal.get()
      const fromPage = page?.entities.signal(ref).get()
      if (fromPage) {
        return fromPage
      }
      const fromSession = this.session.entities.signal(ref).get()
      if (fromSession) {
        return fromSession
      }
      const fromUser = this.user.entities.signal(ref).get()
      if (fromUser) {
        return fromUser
      }
      for (const scope of this.groupsSignal.get().values()) {
        const fromGroup = scope.entities.signal(ref).get()
        if (fromGroup) {
          return fromGroup
        }
      }

      return undefined
    })
  }
}
