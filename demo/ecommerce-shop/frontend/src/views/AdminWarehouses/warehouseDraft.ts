// The warehouse draft: what the Add and the edit dialogs hold between opening
// and the backend's answer — a name and an address, as typed — and the verdicts
// read from it. Pure — no connection, so the unit test reads it in node. The
// verdicts go by the raw value: trimming and the length limits are the server's
// (rules-and-violations.md, section E).

/** The fields a warehouse dialog collects, Add or edit. */
export interface WarehouseDraft {
  /** The warehouse name, as typed. */
  readonly name: string
  /** The warehouse address, as typed. */
  readonly address: string
}

/** The draft every Add opening starts from: both fields empty. */
export const EMPTY_WAREHOUSE_DRAFT: WarehouseDraft = {
  name: '',
  address: '',
}

/**
 * Whether the draft holds both fields: the verdict of the Add button and
 * Enter, and the edit's own check of its input.
 *
 * @param draft The draft the dialog holds.
 */
export function isWarehouseDraftComplete(draft: WarehouseDraft): boolean {
  return draft.name !== '' && draft.address !== ''
}

/**
 * Whether closing the Add dialog would lose typing: either field holds
 * something. The edit asks its session instead.
 *
 * @param draft The draft the dialog holds.
 */
export function isWarehouseDraftDirty(draft: WarehouseDraft): boolean {
  return draft.name !== '' || draft.address !== ''
}
