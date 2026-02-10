import { extractEntitiesEnvelope } from '../types/entities'

/**
 * Base class for receiving and applying entity changes from the transport layer.
 * Framework owns the process (extract envelope, dispatch by collection key);
 * project subclass overrides applyFull/applyUpdates/applyDeleted with typed
 * parsing and store updates.
 *
 * Usage: when a message with entities is received, call apply(payload, context).
 * context is typically the app store (e.g. Pinia store); subclass uses it
 * to update state.
 */
export abstract class EntitiesReceiver {
  /**
   * Apply entity changes from payload to the given context (e.g. store).
   * Extracts the entities envelope and calls applyFull/applyUpdates/applyDeleted
   * for each collection key. Override those methods in the project.
   *
   * @param payload - Raw message payload (e.g. message.data with entities)
   * @param context - Application context (e.g. store) for applying changes
   */
  apply(payload: unknown, context?: unknown): void {
    const envelope = extractEntitiesEnvelope(payload)
    if (envelope === null) {
      return
    }

    if (envelope.full !== undefined) {
      const replaceSet = new Set(envelope.replaceFull ?? [])
      for (const [collectionKey, rawItems] of Object.entries(envelope.full)) {
        const replace = replaceSet.has(collectionKey)
        this.applyFull(collectionKey, rawItems, context, replace)
      }
    }

    if (envelope.updates !== undefined) {
      for (const [collectionKey, rawItems] of Object.entries(envelope.updates)) {
        this.applyUpdates(collectionKey, rawItems, context)
      }
    }

    if (envelope.deleted !== undefined) {
      for (const [collectionKey, ids] of Object.entries(envelope.deleted)) {
        this.applyDeleted(collectionKey, ids, context)
      }
    }
  }

  /**
   * Handle full snapshot for a collection (replace or merge).
   * Override in project: parse rawItems into typed entities and write to store.
   * When replace is true, replace entire collection with rawItems; when false, merge/append.
   *
   * @param _collectionKey - Collection name (e.g. 'users', 'events')
   * @param _rawItems - Array of raw items from transport
   * @param _context - Application context (e.g. store)
   * @param _replace - If true, replace entire collection; if false, merge/append (default behavior)
   */
  protected applyFull(
    _collectionKey: string,
    _rawItems: unknown[],
    _context?: unknown,
    _replace?: boolean,
  ): void {
    // Override in subclass
  }

  /**
   * Handle incremental updates for a collection (merge/upsert).
   * Override in project: parse rawItems and merge into store.
   *
   * @param _collectionKey - Collection name
   * @param _rawItems - Array of raw items from transport
   * @param _context - Application context
   */
  protected applyUpdates(_collectionKey: string, _rawItems: unknown[], _context?: unknown): void {
    // Override in subclass
  }

  /**
   * Handle deleted ids for a collection (remove by id).
   * Override in project: remove entities with given ids from store.
   *
   * @param _collectionKey - Collection name
   * @param _ids - Array of entity ids to remove
   * @param _context - Application context
   */
  protected applyDeleted(_collectionKey: string, _ids: number[], _context?: unknown): void {
    // Override in subclass
  }
}
