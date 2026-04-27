import { extractFrontendChangesEnvelope } from '../types/frontendState'

/**
 * Base receiver for explicit frontend state collection changes.
 */
export abstract class FrontendStateReceiver {
  apply(payload: unknown, context?: unknown): void {
    const envelope = extractFrontendChangesEnvelope(payload)
    if (envelope === null) {
      return
    }

    if (envelope.full !== undefined) {
      const replaceSet = new Set(envelope.replaceFull ?? [])
      for (const [collectionKey, rawItems] of Object.entries(envelope.full)) {
        this.applyFull(collectionKey, rawItems, context, replaceSet.has(collectionKey))
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

  protected applyFull(
    _collectionKey: string,
    _rawItems: unknown[],
    _context?: unknown,
    _replace?: boolean,
  ): void {
  }

  protected applyUpdates(_collectionKey: string, _rawItems: unknown[], _context?: unknown): void {
  }

  protected applyDeleted(_collectionKey: string, _ids: number[], _context?: unknown): void {
  }
}
