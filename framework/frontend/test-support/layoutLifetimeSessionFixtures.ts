import {
  ActionLifecycle,
  bindAccountBlocked,
  bindAccountStanding,
  bindLegalReconsent,
  bindSessionScope,
  ScopeManager,
  type HilosConnection,
} from '@hilos/core/base'

/** The anonymous session notice that replaces the ordinary page. */
export const BLOCKED = {
  entities: { currentUser: null },
  data: { accountBlocked: { identifier: 'maria@example.com' } },
}

/** External standing input; omitted fields in later handshakes stay untouched. */
export function reconsentHandshake(facts: { frozen: true } | null) {
  return {
    data: {
      accountStanding:
        facts === null
          ? null
          : {
              shown: 'frozen',
              blocked: false,
              frozen: true,
              deletionEffectiveAt: null,
              lapsed: [],
              window: [],
              mergedInto: null,
              mergedIntoName: null,
            },
    },
    entities: {
      currentUser: facts === null ? null : { id: 2, name: 'Bob' },
      impersonatedBy: null,
    },
  }
}

/** Bind only the real session readers needed by the page replacement cases. */
export function bindLayoutLifetimeSession(connection: HilosConnection) {
  const releases: (() => void)[] = []
  try {
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    const actions = new ActionLifecycle(connection)
    releases.push(bindAccountBlocked(scopes, actions, connection))
    releases.push(bindAccountStanding(scopes, actions))
    releases.push(bindLegalReconsent(scopes, actions))
    return () => {
      for (const release of releases.splice(0).reverse()) release()
    }
  } catch (error) {
    for (const release of releases.reverse()) release()
    throw error
  }
}
