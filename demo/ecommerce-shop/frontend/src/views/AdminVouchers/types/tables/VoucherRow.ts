/** One voucher as the administration table renders it. */
export interface VoucherRow {
  /** Voucher id; also the table row key. */
  readonly id: number
  /** Normalized voucher code. */
  readonly code: string
  /** Whole percent of the discount, from 1 to 100. */
  readonly discountPercent: number
  /** Whether the voucher is given in the cart. */
  readonly active: boolean
}
