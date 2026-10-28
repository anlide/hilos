// Types a neighbor imports. Only the program built with this folder's tsconfig
// resolves them; a file judged alone sees none of them.

/** A moment under a name of its own. */
export type Stamp = Date

/** A number under a name of its own. */
export type Count = number

/** A reading that carries both. */
export interface Reading {
  at: Stamp
  count: Count
  total: bigint
}

/** The epoch, printed here as well, so the report spans two files. */
export const EPOCH_LABEL = new Date(0).toUTCString()
