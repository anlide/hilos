// The framework Hilos communications admin headless: the channels-hub and
// channel-fields view-models, their row resolvers, the table / filtered-fields /
// actions factories the per-framework HilosCommunications{,Channel}Page views
// render from. It is framework-agnostic (imports no UI framework) and reads only
// @hilos/core primitives, so the Vue/React/Angular communications views stay thin
// (multiframework-core.md).
//
// The pages are built FROM THE CHANNEL REGISTRY, not a hardcoded list: each row
// projects a delivery-channel descriptor plus its resolved config, so a new
// channel (sms, push) appears with no view edit. Both tables are page-scoped
// SelfSnapshotTables produced on the project's backend and delivered over the
// socket. The channel-config store is the settings collection (the same override
// store the settings page writes), so the three round-trips — set a field
// override, reset it to env/default, send a test notification — dispatch as
// tracked actions and the reactive tables redraw the affected rows; there is no
// new server->client signal. A project supplies a HilosCommunicationsContext and
// the framework owns the rest.

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { readBoolean, readString } from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { TableViewportController } from '../../table/TableViewportController.js'

/** One row of the channels hub table — the framework channels view-model. */
export interface HilosChannelRow {
  /** Channel name; also the table row key and the {channelId} route param. */
  readonly channel: string
  /** Human-readable channel label. */
  readonly label: string
  /** Whether the channel is globally enabled (a persisted settings override). */
  readonly enabled: boolean
  /** Whether every config field resolved to a value (no field on its bare default). */
  readonly configured: boolean
  /** Transport / driver name (e.g. `smtp`). */
  readonly driver: string
  /** Count of config fields still on their bare default (unresolved). */
  readonly missingFields: number
}

/** Where a channel field's effective value comes from. */
export type ChannelValueSource = 'settings' | 'env' | 'default'

const VALUE_SOURCES: readonly ChannelValueSource[] = [
  'settings',
  'env',
  'default',
]

/** One row of a channel's config-fields table — the framework fields view-model. */
export interface HilosChannelFieldRow {
  /** The field's globally-unique settings key; also the table row key. */
  readonly key: string
  /** Owning channel name (the fields table is global; the page filters by this). */
  readonly channel: string
  /** Field key within its channel (e.g. `smtp_host`). */
  readonly field: string
  /** Human-readable field label. */
  readonly label: string
  /** Value type: `string` | `integer` | `float` | `boolean`. */
  readonly type: string
  /** Effective value, or null for a secret (a secret is never sent to the browser). */
  readonly value: boolean | number | string | null
  /** Where the effective value comes from. */
  readonly valueSource: ChannelValueSource
  /** Whether the field is an env-only secret (shown as set/not-set, never edited). */
  readonly secret: boolean
  /** Whether the field can be edited in the admin (false for a secret). */
  readonly editable: boolean
}

// Wire keys: the framework channels hub table and channel-fields table, their
// inline row slots, and the set / reset / test action names. A project binds its
// backend to these keys (Hilos::TABLES / PAGE_TABLES).
const CHANNELS_TABLE = 'hilosCommunicationsChannels'
const CHANNELS_SLOT = 'channel'
const CHANNELS_PAGE_SIZE = 50
const FIELDS_TABLE = 'hilosCommunicationsChannelFields'
const FIELDS_SLOT = 'field'
// The fields table is global (one row per field of every channel); the channel
// page filters it to its route channel client-side, so the window must hold every
// field of every channel at once — a channel has a handful, and there are few.
const FIELDS_PAGE_SIZE = 500
const CHANNEL_SET_ACTION = 'communications_channel_set'
const CHANNEL_RESET_ACTION = 'communications_channel_reset'
const CHANNEL_TEST_ACTION = 'communications_channel_test'
/** The pseudo-field the hub enablement toggle writes (DeliveryChannelSettings::ENABLED_FIELD). */
export const CHANNEL_ENABLED_FIELD = 'enabled'

/**
 * The project-supplied context the communications admin reads from: the
 * scope-partitioned stores that own the page-scoped tables, the live connection
 * the table windows ride, and the action lifecycle the set / reset / test tracked
 * actions dispatch over. Everything else (the table keys, slot names, view-models,
 * and behavior) is the framework's; the channel registry lives on its backend.
 */
export interface HilosCommunicationsContext {
  /** The connection the tables send their viewports over and receive windows / deltas from. */
  readonly connection: HilosConnection
  /** The scope manager owning the page scopes the table windows normalize into. */
  readonly scopes: ScopeManager
  /** The action lifecycle the set / reset / test tracked actions dispatch over. */
  readonly actions: ActionLifecycle
}

/** The channel-config mutation surface a communications view binds to. */
export interface HilosCommunicationsActions {
  /**
   * Write one channel field's settings override, as a tracked action. The hub's
   * enablement toggle rides this same action with the {@link CHANNEL_ENABLED_FIELD}
   * pseudo-field. The backend coerces and validates the value against the field
   * descriptor before persisting; a rejection returns on the action's `::fail`.
   *
   * @param channel The target channel name.
   * @param field The field key (or {@link CHANNEL_ENABLED_FIELD} for the global toggle).
   * @param value The new value.
   */
  sendChannelSet(
    channel: string,
    field: string,
    value: boolean | number | string,
  ): ActionHandle
  /**
   * Reset one channel field to its env/default value, as a tracked action. The
   * settings override is cleared so the resolver falls back to env, then the
   * descriptor default.
   *
   * @param channel The target channel name.
   * @param field The field key.
   */
  sendChannelReset(channel: string, field: string): ActionHandle
  /**
   * Send a test notification narrowed to one channel, addressed to the acting
   * admin, as a tracked action. A missing address for the channel returns on the
   * action's `::fail`; the delivery outcome is visible in the deliveries log
   * (HIL-201).
   *
   * @param channel The target channel name.
   */
  sendChannelTest(channel: string): ActionHandle
}

/** Read a row slot as an inline record, or undefined when it is not one. */
function recordSlot(slot: unknown): Record<string, unknown> | undefined {
  return typeof slot === 'object' && slot !== null && !Array.isArray(slot)
    ? (slot as Record<string, unknown>)
    : undefined
}

/** Narrow an unknown value_source to the typed union, defaulting to default. */
function toValueSource(value: unknown): ChannelValueSource {
  return VALUE_SOURCES.includes(value as ChannelValueSource)
    ? (value as ChannelValueSource)
    : 'default'
}

/**
 * Payload keys of the channels-hub row slot (mirrors the backend row shape). A map
 * rather than flat constants because this module owns two row shapes whose field
 * names overlap, and the map says which row a key belongs to at every use site.
 * Exported because the hub view declares its columns from the same keys.
 */
export const HilosChannelRowKey = {
  channel: 'channel',
  label: 'label',
  enabled: CHANNEL_ENABLED_FIELD,
  configured: 'configured',
  driver: 'driver',
  missingFields: 'missingFields',
} as const

/** Payload keys of the channel-fields row slot (mirrors the backend row shape). */
const HilosChannelFieldRowKey = {
  channel: 'channel',
  field: 'field',
  label: 'label',
  type: 'type',
  value: 'value',
  valueSource: 'valueSource',
  secret: 'secret',
  editable: 'editable',
} as const

/** Read a field's effective value, keeping its scalar type (or null for a secret). */
function readFieldValue(
  slot: Record<string, unknown>,
): boolean | number | string | null {
  const value = slot[HilosChannelFieldRowKey.value]

  return typeof value === 'boolean' ||
    typeof value === 'number' ||
    typeof value === 'string'
    ? value
    : null
}

/**
 * Resolve one raw channels-hub row into its view-model. The projected channel
 * fields ride a single inline `channel` slot (no entity reference — a channel row
 * is page-scoped, keyed by its name), so this reads the slot as a plain record.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosChannelRow(row: TableRow): HilosChannelRow {
  const slot = recordSlot(row.slots[CHANNELS_SLOT]) ?? {}

  return {
    // Identity is the fragment's row key; it never rides the slot as `id`, which the
    // normalizer would treat as an entity reference and strip the row (normalizer.ts).
    channel: readString(slot, HilosChannelRowKey.channel) || String(row.rowKey),
    label: readString(slot, HilosChannelRowKey.label),
    enabled: readBoolean(slot, HilosChannelRowKey.enabled),
    configured: readBoolean(slot, HilosChannelRowKey.configured),
    driver: readString(slot, HilosChannelRowKey.driver),
    missingFields: Number(slot[HilosChannelRowKey.missingFields] ?? 0),
  }
}

/**
 * Resolve one raw channel-fields row into its view-model. The projected field
 * rides a single inline `field` slot, keyed by the field's settings key.
 *
 * @param row The raw table row from the page-scoped table store.
 */
export function resolveHilosChannelFieldRow(
  row: TableRow,
): HilosChannelFieldRow {
  const slot = recordSlot(row.slots[FIELDS_SLOT]) ?? {}

  return {
    key: String(row.rowKey),
    channel: readString(slot, HilosChannelFieldRowKey.channel),
    field: readString(slot, HilosChannelFieldRowKey.field),
    label: readString(slot, HilosChannelFieldRowKey.label),
    type: readString(slot, HilosChannelFieldRowKey.type),
    value: readFieldValue(slot),
    valueSource: toValueSource(slot[HilosChannelFieldRowKey.valueSource]),
    secret: readBoolean(slot, HilosChannelFieldRowKey.secret),
    editable: readBoolean(slot, HilosChannelFieldRowKey.editable),
  }
}

/** The channels table handle a hub view drives: the controller plus its mount lifecycle. */
export interface HilosChannelsTable {
  /** The server-windowed controller the view renders rows, descriptor, and pending from. */
  readonly controller: TableViewportController<HilosChannelRow>
  /** Bind the table to the connection and request the first window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * Bind a page-scoped viewport table and re-request its window on every
 * (re)connect. Shared by both communications tables: the initial request can run
 * before the socket opens, and a reconnect is a fresh exchange that no longer
 * remembers this connection's window.
 *
 * @param context The project context (connection and scope stores).
 * @param page The page key the table is scoped to.
 * @param tableKey The table key registered on the backend.
 * @param controller The controller ingesting the window and deltas.
 * @returns The unbind functions to run on dispose.
 */
function bindReconnectingTable(
  context: HilosCommunicationsContext,
  page: string,
  tableKey: string,
  controller: TableViewportController<unknown>,
): Array<() => void> {
  const teardown = [
    bindTableViewport(
      context.connection,
      context.scopes,
      { page, tableKey },
      controller,
    ),
    context.connection.on('state', (state) => {
      if (state === 'connected') {
        controller.start()
      }
    }),
  ]
  controller.start()

  return teardown
}

/**
 * The server-windowed controller for the channels hub table: one row per
 * registered channel, ordered by channel name. Rows resolve through
 * {@link resolveHilosChannelRow}. The returned handle's `start` binds the table to
 * its address and requests the first window; `dispose` unbinds it.
 *
 * @param context The project context (connection and scope stores).
 */
export function createHilosChannelsTable(
  context: HilosCommunicationsContext,
): HilosChannelsTable {
  const controller = new TableViewportController<HilosChannelRow>({
    resolve: resolveHilosChannelRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.COMMUNICATIONS,
        CHANNELS_TABLE,
        descriptor,
      ),
    pageSize: CHANNELS_PAGE_SIZE,
    initialSort: { field: HilosChannelRowKey.channel, direction: 'asc' },
  })
  let teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown = bindReconnectingTable(
        context,
        HilosPages.COMMUNICATIONS,
        CHANNELS_TABLE,
        controller,
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/** A channel's config-fields view: the rows filtered to one channel, plus lifecycle. */
export interface HilosChannelFields {
  /** The channel's field rows (the global fields table filtered to `channel`). */
  readonly rows: ReadonlySignal<readonly HilosChannelFieldRow[]>
  /** Bind the (global) fields table and request its window — call on mount. */
  start(): void
  /** Unbind from the connection — call on unmount. */
  dispose(): void
}

/**
 * The single channel's config-fields view. The backend fields table is global
 * (one row per field of every channel — a route param does not reach the table
 * query), so this pulls the whole table and filters it to `channel` client-side.
 * The filter is a reactive signal, so navigating between channels re-filters the
 * same window with no re-fetch. Rows resolve through
 * {@link resolveHilosChannelFieldRow} and skip any pending / removed placeholder.
 *
 * @param context The project context (connection and scope stores).
 * @param channel The route channel to show fields for (a signal — it changes on navigation).
 */
export function createHilosChannelFields(
  context: HilosCommunicationsContext,
  channel: ReadonlySignal<string>,
): HilosChannelFields {
  const controller = new TableViewportController<HilosChannelFieldRow>({
    resolve: resolveHilosChannelFieldRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.COMMUNICATIONS_CHANNEL,
        FIELDS_TABLE,
        descriptor,
      ),
    pageSize: FIELDS_PAGE_SIZE,
    initialSort: { field: 'field', direction: 'asc' },
  })
  let teardown: Array<() => void> = []
  const rows = computedSignal<readonly HilosChannelFieldRow[]>(() => {
    const wanted = channel.get()

    return controller.rows
      .get()
      .filter((view) => !view.placeholder && view.row !== null)
      .map((view) => view.row as HilosChannelFieldRow)
      .filter((row) => row.channel === wanted)
  })

  return {
    rows,
    start() {
      teardown = bindReconnectingTable(
        context,
        HilosPages.COMMUNICATIONS_CHANNEL,
        FIELDS_TABLE,
        controller,
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * The communications mutation surface: set / reset / test submit as tracked
 * actions over the lifecycle. Each returns an ActionHandle whose `done` resolves
 * on the backend's `::success` ack and rejects on `::fail` — a view surfaces the
 * failure (authoritative-backend). The committed row returns separately over the
 * live table (own-change is decided server-side), so this surface only dispatches.
 *
 * @param context The project context (the action lifecycle the actions dispatch over).
 */
export function createHilosCommunicationsActions(
  context: HilosCommunicationsContext,
): HilosCommunicationsActions {
  return {
    sendChannelSet(channel, field, value) {
      return context.actions.dispatch(CHANNEL_SET_ACTION, {
        channel,
        field,
        value,
      })
    },
    sendChannelReset(channel, field) {
      return context.actions.dispatch(CHANNEL_RESET_ACTION, { channel, field })
    },
    sendChannelTest(channel) {
      return context.actions.dispatch(CHANNEL_TEST_ACTION, { channel })
    },
  }
}
