// The types the neighbor imports: a number, a bigint, and a moment.

/** A count of rows. */
export type Count = number

/** A reading with its count, total and moment. */
export interface Reading {
  count: Count
  total: bigint
  at: Date
}
