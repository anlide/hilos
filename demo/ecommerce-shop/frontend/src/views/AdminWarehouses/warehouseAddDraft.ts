// The Add warehouse draft: what the modal holds between opening and the backend's
// answer, and the two verdicts read from it. Pure — no connection, so the unit
// test reads it in node. Both verdicts go by the raw value: trimming and the
// length limits are the server's (rules-and-violations.md, section E).

/** The fields an Add warehouse dialog collects. */
export interface WarehouseAddDraft {
  /** The warehouse name, as typed. */
  readonly name: string
  /** The warehouse address, as typed. */
  readonly address: string
}

/** The draft every opening starts from: both fields empty. */
export const EMPTY_WAREHOUSE_DRAFT: WarehouseAddDraft = {
  name: '',
  address: '',
}

/**
 * Whether the draft may be sent: both fields hold something. The Add button and
 * Enter share this verdict.
 *
 * @param draft The draft the dialog holds.
 */
export function canAddWarehouse(draft: WarehouseAddDraft): boolean {
  return draft.name !== '' && draft.address !== ''
}

/**
 * Whether closing the dialog would lose typing: either field holds something.
 *
 * @param draft The draft the dialog holds.
 */
export function isWarehouseDraftDirty(draft: WarehouseAddDraft): boolean {
  return draft.name !== '' || draft.address !== ''
}
