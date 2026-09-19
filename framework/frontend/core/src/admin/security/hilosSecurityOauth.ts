// The framework Hilos security-center OAuth admin headless (HIL-286): the providers
// list, the one shared return address above it, and one provider's fields — their
// view-models, row resolvers, and the table / actions factories the per-framework
// HilosSecurityOauth{,Provider}Page views render from. It is framework-agnostic
// (imports no UI framework) and reads only @hilos/core primitives, so the
// Vue/React/Angular views stay thin (multiframework-core.md).
//
// The pages are built FROM THE PROJECT'S PROVIDER DIRECTORY, not a hardcoded list:
// each providers row projects a provider the project declares plus what the backend
// resolver makes of it (stored value, then env, then the recipe). All three tables
// are page-scoped SelfSnapshotTables produced on the project's backend and delivered
// over the socket. The client secret is write-only: no row, reply, or view-model
// carries its value — only whether one is in force. The round-trips dispatch as
// tracked actions and the reactive tables redraw the affected rows after the
// backend's echo; there is no new server->client signal. A project supplies a
// HilosSecurityOauthContext and the framework owns the rest.

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readBoolean,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { subscribeSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import {
  HILOS_TABLE_ACTIONS_KEY,
  type HilosTableColumnOf,
} from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/** Where an OAuth value comes from: entered in the admin, env, or the provider's recipe. */
export type OAuthValueSource = 'db' | 'env' | 'default'

const VALUE_SOURCES: readonly OAuthValueSource[] = ['db', 'env', 'default']

/** One row of the providers table — the framework provider view-model. */
export interface HilosOAuthProviderRow {
  /** Provider key (e.g. `oauth:github`); also the row key and the {providerId} route param. */
  readonly providerKey: string
  /** Human provider name; falls back to the key when the row names none. */
  readonly label: string
  /** Whether the recipe is a preset Hilos ships rather than one the project built. */
  readonly builtIn: boolean
  /** Whether the client id and the secret both resolve to a value. */
  readonly configured: boolean
  /** How many of the required fields (client id, secret) are still empty. */
  readonly missingFields: number
  /** Whether a client secret is in force on any layer. */
  readonly secretSet: boolean
  /** Where the client id comes from. */
  readonly clientIdSource: OAuthValueSource
  /** Recipe: the provider's authorization endpoint. */
  readonly authorizeUrl: string
  /** Recipe: the provider's token endpoint. */
  readonly tokenUrl: string
  /** Recipe: the provider's userinfo endpoint. */
  readonly userInfoUrl: string
  /** Recipe: the userinfo field holding the account's immutable id. */
  readonly subjectKey: string
  /** Recipe: the userinfo field holding the account's email. */
  readonly emailKey: string
  /** Recipe: the userinfo field holding the account's display name. */
  readonly nameKey: string
}

/** One row of a provider's fields table — the framework field view-model. */
export interface HilosOAuthFieldRow {
  /** The row key: the provider key and the field name. */
  readonly key: string
  /** Owning provider key (the fields table is global; the page narrows it by this). */
  readonly providerKey: string
  /** Field name: `client_id`, `scope`, or `client_secret`. */
  readonly field: string
  /** Human field label; falls back to the field name when the row names none. */
  readonly label: string
  /** Value type (always `string` today). */
  readonly type: string
  /** Whether the field is write-only (the client secret). */
  readonly secret: boolean
  /** Effective value, or null for the secret (a secret is never sent to the browser). */
  readonly value: string | null
  /** Where the effective value comes from. */
  readonly source: OAuthValueSource
  /** Whether the effective value is non-empty — for the secret, "set" or "not set". */
  readonly setState: boolean
}

/** The one row of the return-address table. */
export interface HilosOAuthRedirectRow {
  /** The row key: the setting key of the address. */
  readonly key: string
  /** Effective return address; empty when none is set anywhere. */
  readonly value: string
  /** Where the address comes from. */
  readonly source: OAuthValueSource
  /** Whether the address is non-empty. */
  readonly setState: boolean
}

// Wire keys: the three framework tables, their inline row slots, and the action
// names. A project binds its backend to these keys (Hilos::TABLES / PAGE_TABLES).
const PROVIDERS_TABLE = 'hilosSecurityOauthProviders'
const PROVIDERS_SLOT = 'provider'
const FIELDS_TABLE = 'hilosSecurityOauthProviderFields'
const FIELDS_SLOT = 'field'
const REDIRECT_TABLE = 'hilosSecurityOauthRedirect'
const REDIRECT_SLOT = 'redirect'
const PROVIDER_SET_ACTION = 'security_oauth_provider_set'
const PROVIDER_RESET_ACTION = 'security_oauth_provider_reset'
const REDIRECT_SET_ACTION = 'security_oauth_redirect_set'
const REDIRECT_RESET_ACTION = 'security_oauth_redirect_reset'
/**
 * Filter-map key narrowing the provider tables to one provider — the backend
 * `HilosSecurityOAuthProvidersTable::FILTER_PROVIDER`, preset from the route.
 */
const FILTER_PROVIDER = 'provider'

/**
 * The project-supplied context the OAuth admin reads from: the scope-partitioned
 * stores that own the page-scoped tables, the live connection the table windows
 * ride, and the action lifecycle the tracked actions dispatch over. Everything
 * else is the framework's; the provider directory lives on its backend.
 */
export interface HilosSecurityOauthContext {
  /** The connection the tables send their viewports over and receive windows / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scopes the table windows normalize into. */
  readonly scopes: ScopeManager
  /** The action lifecycle the tracked actions dispatch over. */
  readonly actions: ActionLifecycle
}

/** The OAuth mutation surface the two views bind to. */
export interface HilosSecurityOauthActions {
  /**
   * Store one field of one provider, as a tracked action. A secret sent here is
   * replaced, never echoed back. An empty or over-long value, an unknown provider
   * or field returns on the action's `::fail` with the backend's phrase.
   *
   * @param providerKey The provider key.
   * @param field `client_id`, `scope`, or `client_secret`.
   * @param value The new value.
   */
  sendProviderSet(
    providerKey: string,
    field: string,
    value: string,
  ): ActionHandle
  /**
   * Take one field of one provider back to its env / recipe value, as a tracked action.
   *
   * @param providerKey The provider key.
   * @param field `client_id`, `scope`, or `client_secret`.
   */
  sendProviderReset(providerKey: string, field: string): ActionHandle
  /**
   * Store the shared return address, as a tracked action. Anything but an
   * absolute http(s) URL returns on the action's `::fail`.
   *
   * @param value The new address.
   */
  sendRedirectSet(value: string): ActionHandle
  /** Take the shared return address back to its env value, as a tracked action. */
  sendRedirectReset(): ActionHandle
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/** Narrow an unknown source to the typed union, defaulting to default. */
function toValueSource(value: unknown): OAuthValueSource {
  return VALUE_SOURCES.includes(value as OAuthValueSource)
    ? (value as OAuthValueSource)
    : 'default'
}

/** Payload keys of the providers row slot (mirrors the backend row shape). */
export const HilosOAuthProviderRowKey = {
  providerKey: 'providerKey',
  label: 'label',
  builtIn: 'builtIn',
  configured: 'configured',
  missingFields: 'missingFields',
  secretSet: 'secretSet',
  clientIdSource: 'clientIdSource',
  authorizeUrl: 'authorizeUrl',
  tokenUrl: 'tokenUrl',
  userInfoUrl: 'userInfoUrl',
  subjectKey: 'subjectKey',
  emailKey: 'emailKey',
  nameKey: 'nameKey',
} as const

/** Payload keys of the fields row slot (mirrors the backend row shape). */
const HilosOAuthFieldRowKey = {
  providerKey: 'providerKey',
  field: 'field',
  label: 'label',
  type: 'type',
  secret: 'secret',
  value: 'value',
  source: 'source',
  setState: 'setState',
} as const

/** Payload keys of the return-address row slot (mirrors the backend row shape). */
const HilosOAuthRedirectRowKey = {
  value: 'value',
  source: 'source',
  setState: 'setState',
} as const

/**
 * Resolve one raw providers row into its view-model. The projected provider rides
 * a single inline `provider` slot, keyed by the provider key.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosOAuthProviderRow(
  row: TableRow,
): HilosOAuthProviderRow {
  const slot = recordSlot(row.slots[PROVIDERS_SLOT]) ?? {}
  // Identity is the fragment's row key; it never rides the slot as `id`, which the
  // normalizer would treat as an entity reference and strip the row (normalizer.ts).
  const providerKey =
    readString(slot, HilosOAuthProviderRowKey.providerKey) || String(row.rowKey)

  return {
    providerKey,
    // An unlabelled provider is shown by its key rather than as a blank cell.
    label:
      readStringOrNull(slot, HilosOAuthProviderRowKey.label) ?? providerKey,
    builtIn: readBoolean(slot, HilosOAuthProviderRowKey.builtIn),
    configured: readBoolean(slot, HilosOAuthProviderRowKey.configured),
    missingFields: Number(slot[HilosOAuthProviderRowKey.missingFields] ?? 0),
    secretSet: readBoolean(slot, HilosOAuthProviderRowKey.secretSet),
    clientIdSource: toValueSource(
      slot[HilosOAuthProviderRowKey.clientIdSource],
    ),
    authorizeUrl: readString(slot, HilosOAuthProviderRowKey.authorizeUrl),
    tokenUrl: readString(slot, HilosOAuthProviderRowKey.tokenUrl),
    userInfoUrl: readString(slot, HilosOAuthProviderRowKey.userInfoUrl),
    subjectKey: readString(slot, HilosOAuthProviderRowKey.subjectKey),
    emailKey: readString(slot, HilosOAuthProviderRowKey.emailKey),
    nameKey: readString(slot, HilosOAuthProviderRowKey.nameKey),
  }
}

/**
 * Resolve one raw fields row into its view-model. The projected field rides a
 * single inline `field` slot. The value is null for the secret — whatever the
 * slot holds, a secret's value is never read out of it.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosOAuthFieldRow(row: TableRow): HilosOAuthFieldRow {
  const slot = recordSlot(row.slots[FIELDS_SLOT]) ?? {}
  const field = readString(slot, HilosOAuthFieldRowKey.field)
  const secret = readBoolean(slot, HilosOAuthFieldRowKey.secret)

  return {
    key: String(row.rowKey),
    providerKey: readString(slot, HilosOAuthFieldRowKey.providerKey),
    field,
    // Same rule as the provider row: an unlabelled field is shown by its own name.
    label: readStringOrNull(slot, HilosOAuthFieldRowKey.label) ?? field,
    type: readString(slot, HilosOAuthFieldRowKey.type),
    secret,
    value: secret ? null : readStringOrNull(slot, HilosOAuthFieldRowKey.value),
    source: toValueSource(slot[HilosOAuthFieldRowKey.source]),
    setState: readBoolean(slot, HilosOAuthFieldRowKey.setState),
  }
}

/**
 * Resolve the one raw return-address row into its view-model.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosOAuthRedirectRow(
  row: TableRow,
): HilosOAuthRedirectRow {
  const slot = recordSlot(row.slots[REDIRECT_SLOT]) ?? {}

  return {
    key: String(row.rowKey),
    value: readString(slot, HilosOAuthRedirectRowKey.value),
    source: toValueSource(slot[HilosOAuthRedirectRowKey.source]),
    setState: readBoolean(slot, HilosOAuthRedirectRowKey.setState),
  }
}

/** A page-scoped OAuth table: the controller plus its mount lifecycle. */
export interface HilosOAuthTable<R> {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<R>
  /** Bind the table (and, for a provider table, follow the route's provider) — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/** The columns of the providers table, in display order. */
const PROVIDERS_COLUMNS: HilosTableColumnOf<HilosOAuthProviderRow>[] = [
  {
    key: HilosOAuthProviderRowKey.label,
    label: 'Provider',
    sortable: true,
    reads: [HilosOAuthProviderRowKey.providerKey],
  },
  {
    key: HilosOAuthProviderRowKey.configured,
    label: 'Status',
    reads: [HilosOAuthProviderRowKey.missingFields],
  },
  {
    key: HilosOAuthProviderRowKey.clientIdSource,
    label: 'Client ID from',
  },
  {
    key: HilosOAuthProviderRowKey.secretSet,
    label: 'Secret',
  },
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    // One link, built from the row key and named after the label.
    reads: [HilosOAuthProviderRowKey.label],
  },
]

/**
 * What the providers table declares about its frame. No search: a project declares
 * a handful of providers. No title: the page heading above already names it.
 */
const PROVIDERS_FRAME: HilosTableFrame = {
  columns: PROVIDERS_COLUMNS,
  empty: { title: 'This application declares no OAuth providers.' },
}

/** The columns of a provider's fields table, in display order. */
const FIELDS_COLUMNS: HilosTableColumnOf<HilosOAuthFieldRow>[] = [
  {
    key: 'field',
    label: 'Field',
    reads: [HilosOAuthFieldRowKey.label],
  },
  {
    key: 'value',
    label: 'Value',
    // The secret is shown as set or not set, never by value.
    reads: [HilosOAuthFieldRowKey.secret, HilosOAuthFieldRowKey.setState],
  },
  { key: 'source', label: 'Source' },
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    // Edit (or Replace) opens the dialog, reset is armed by the source.
    reads: [
      HilosOAuthFieldRowKey.providerKey,
      HilosOAuthFieldRowKey.field,
      HilosOAuthFieldRowKey.label,
      HilosOAuthFieldRowKey.secret,
      HilosOAuthFieldRowKey.value,
      HilosOAuthFieldRowKey.source,
    ],
  },
]

/**
 * What a provider's fields table declares about its frame. The empty state is the
 * "not found" of the page: a route naming a provider the project does not declare
 * narrows the table to nothing.
 */
const FIELDS_FRAME: HilosTableFrame = {
  columns: FIELDS_COLUMNS,
  empty: { title: 'Provider not found.' },
}

/** The return-address table declares no columns: the view draws its one row as a card. */
const REDIRECT_FRAME: HilosTableFrame = {
  columns: [],
  empty: { title: 'No return address.' },
}

/**
 * Build one page-scoped table handle over a controller.
 *
 * @param context The project context (connection and scope stores).
 * @param page The page key the table is scoped to.
 * @param tableKey The table key registered on the backend.
 * @param controller The controller ingesting the window and deltas.
 * @param provider The route provider to narrow to, or undefined for no narrowing.
 */
function tableHandle<R>(
  context: HilosSecurityOauthContext,
  page: string,
  tableKey: string,
  controller: TableViewportController<R>,
  provider?: ReadonlySignal<string>,
): HilosOAuthTable<R> {
  let teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown = [
        bindTableViewport(
          context.connection,
          context.scopes,
          { page, tableKey },
          controller,
        ),
      ]
      if (provider !== undefined) {
        teardown.push(
          subscribeSignal(provider, (value) =>
            controller.setFilter(FILTER_PROVIDER, value),
          ),
        )
      }
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * Build a controller over one of the three tables on one page.
 *
 * @param context The project context (connection).
 * @param page The page key the table is scoped to.
 * @param tableKey The table key registered on the backend.
 * @param resolve The row resolver.
 * @param frame The table's frame declaration.
 * @param provider The route provider to preset as a filter, or undefined for none.
 */
function controllerFor<R>(
  context: HilosSecurityOauthContext,
  page: string,
  tableKey: string,
  resolve: (row: TableRow) => R,
  frame: HilosTableFrame,
  provider?: ReadonlySignal<string>,
): TableViewportController<R> {
  return new TableViewportController<R>({
    resolve,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(page, tableKey, descriptor),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(page, tableKey, rendered),
    ...(provider !== undefined
      ? { initialFilter: { [FILTER_PROVIDER]: provider.get() } }
      : {}),
    frame,
  })
}

/**
 * The providers list table: one row per provider the project declares, in its
 * directory order (the order of the sign-in icons). Rows resolve through
 * {@link resolveHilosOAuthProviderRow}.
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosOAuthProvidersTable(
  context: HilosSecurityOauthContext,
): HilosOAuthTable<HilosOAuthProviderRow> {
  const page = HilosPages.SECURITY_OAUTH

  return tableHandle(
    context,
    page,
    PROVIDERS_TABLE,
    controllerFor(
      context,
      page,
      PROVIDERS_TABLE,
      resolveHilosOAuthProviderRow,
      PROVIDERS_FRAME,
    ),
  )
}

/**
 * The shared return address above the providers list: a one-row table whose row
 * the view draws as a card. Rows resolve through {@link resolveHilosOAuthRedirectRow}.
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosOAuthRedirect(
  context: HilosSecurityOauthContext,
): HilosOAuthTable<HilosOAuthRedirectRow> {
  const page = HilosPages.SECURITY_OAUTH

  return tableHandle(
    context,
    page,
    REDIRECT_TABLE,
    controllerFor(
      context,
      page,
      REDIRECT_TABLE,
      resolveHilosOAuthRedirectRow,
      REDIRECT_FRAME,
    ),
  )
}

/**
 * The route provider's own row of the providers table — its name, status, and
 * recipe for the provider page's heading and reference block. The providers
 * table is global, so the provider travels as a preset of the filter map and the
 * server narrows the window to it; a provider the project does not declare
 * narrows it to nothing.
 *
 * @param context The project context (connection and scope stores).
 * @param provider The route provider (a signal — it changes on navigation).
 */
export function createHilosOAuthProviderSummary(
  context: HilosSecurityOauthContext,
  provider: ReadonlySignal<string>,
): HilosOAuthTable<HilosOAuthProviderRow> {
  const page = HilosPages.SECURITY_OAUTH_PROVIDER

  return tableHandle(
    context,
    page,
    PROVIDERS_TABLE,
    controllerFor(
      context,
      page,
      PROVIDERS_TABLE,
      resolveHilosOAuthProviderRow,
      PROVIDERS_FRAME,
      provider,
    ),
    provider,
  )
}

/**
 * The route provider's fields table: client id, scope, and client secret. The
 * backend fields table is global (one row per field of every provider), so the
 * provider travels as a preset of the filter map and the server narrows the
 * window — no filter is applied on the client. Rows resolve through
 * {@link resolveHilosOAuthFieldRow}.
 *
 * @param context The project context (connection and scope stores).
 * @param provider The route provider (a signal — it changes on navigation).
 */
export function createHilosOAuthProviderFields(
  context: HilosSecurityOauthContext,
  provider: ReadonlySignal<string>,
): HilosOAuthTable<HilosOAuthFieldRow> {
  const page = HilosPages.SECURITY_OAUTH_PROVIDER

  return tableHandle(
    context,
    page,
    FIELDS_TABLE,
    controllerFor(
      context,
      page,
      FIELDS_TABLE,
      resolveHilosOAuthFieldRow,
      FIELDS_FRAME,
      provider,
    ),
    provider,
  )
}

/**
 * The OAuth mutation surface: every write submits as a tracked action over the
 * lifecycle. Each returns an ActionHandle whose `done` resolves on the backend's
 * `::success` ack and rejects on `::fail` — a view surfaces the failure
 * (authoritative-backend). The committed row returns separately over the live
 * table, so this surface only dispatches.
 *
 * @param context The project context (the action lifecycle the actions dispatch over).
 */
export function createHilosSecurityOauthActions(
  context: HilosSecurityOauthContext,
): HilosSecurityOauthActions {
  return {
    sendProviderSet(providerKey, field, value) {
      return context.actions.dispatch(PROVIDER_SET_ACTION, {
        providerKey,
        field,
        value,
      })
    },
    sendProviderReset(providerKey, field) {
      return context.actions.dispatch(PROVIDER_RESET_ACTION, {
        providerKey,
        field,
      })
    },
    sendRedirectSet(value) {
      return context.actions.dispatch(REDIRECT_SET_ACTION, { value })
    },
    sendRedirectReset() {
      return context.actions.dispatch(REDIRECT_RESET_ACTION, {})
    },
  }
}
