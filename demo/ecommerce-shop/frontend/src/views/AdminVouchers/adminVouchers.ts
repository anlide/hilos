// Pure selectors and table declarations for the shop's Vouchers section.
// The voucher slot carries a record bearing an `id`, so the normalizer stores
// it as an entity of the page scope and leaves a reference in the slot; the
// fields are read back through the resolver the caller hands in, keeping this
// module free of the application bootstrap.
import {
  HILOS_TABLE_ACTIONS_KEY,
  readBoolean,
  readNumber,
  readString,
  type HilosTableColumnOf,
  type HilosTableFilterOption,
  type HilosTableFrame,
  type TableRow,
} from '@hilos/core/base'

import {
  slotFields,
  type EntityFieldsResolver,
} from '../../entityFields/entityFields.js'
import { type VoucherRow } from './types/tables/VoucherRow.js'

/** Table key declared by EcommerceShopTableContext::vouchers. */
export const VOUCHERS_TABLE = 'vouchers'
/** Entity row slot declared by VouchersTable::ROW_SLOT. */
export const VOUCHER_SLOT = 'voucher'
/** Active filter key accepted by VouchersTable. */
export const FILTER_ACTIVE = 'active'

/** Row payload key of the voucher id. */
const VOUCHER_ID_FIELD = 'id'
/** Row payload key of the voucher code (also the table's sort field). */
export const VOUCHER_CODE_FIELD = 'code'
/** Row payload key of the whole discount percent. */
export const VOUCHER_DISCOUNT_PERCENT_FIELD = 'discountPercent'
/** Row payload key of whether the voucher is given in the cart. */
export const VOUCHER_ACTIVE_FIELD = 'active'

/** Active choices, in the order the filter offers them. Any is the SDK's empty choice. */
const ACTIVE_OPTIONS: readonly HilosTableFilterOption[] = [
  { value: true, label: 'Active' },
  { value: false, label: 'Off' },
]

/**
 * Resolve a voucher entity row into the table's row view-model.
 *
 * @param row The raw row from the page-scoped table store.
 * @param fieldsOf The resolver of a slot's reference to its fields.
 */
export function resolveVoucherRow(
  row: TableRow,
  fieldsOf: EntityFieldsResolver,
): VoucherRow {
  const voucher = slotFields(row.slots[VOUCHER_SLOT], fieldsOf)
  const id = voucher[VOUCHER_ID_FIELD]

  return {
    id: typeof id === 'number' ? id : Number(row.rowKey),
    code: readString(voucher, VOUCHER_CODE_FIELD),
    discountPercent: readNumber(voucher, VOUCHER_DISCOUNT_PERCENT_FIELD),
    active: readBoolean(voucher, VOUCHER_ACTIVE_FIELD),
  }
}

/** Columns and the fields each cell needs for live redraws. */
export const VOUCHERS_COLUMNS: HilosTableColumnOf<VoucherRow>[] = [
  {
    key: VOUCHER_CODE_FIELD,
    label: 'Code',
    sortable: true,
    card: 'title',
  },
  {
    key: VOUCHER_DISCOUNT_PERCENT_FIELD,
    label: 'Discount',
    card: 'field',
    headerClass: 'text-end',
    cellClass: 'text-end text-nowrap',
  },
  {
    key: VOUCHER_ACTIVE_FIELD,
    label: 'Active',
    card: 'badge',
  },
  {
    key: HILOS_TABLE_ACTIONS_KEY,
    label: '',
    headerClass: 'text-end',
    cellClass: 'text-end',
    reads: [
      VOUCHER_CODE_FIELD,
      VOUCHER_DISCOUNT_PERCENT_FIELD,
      VOUCHER_ACTIVE_FIELD,
    ],
  },
]

/**
 * Declare the standard table frame; the section head names the table.
 * The server orders the first window by code, so this frame sends no sort.
 */
export function vouchersFrame(): HilosTableFrame {
  return {
    search: { placeholder: 'Search codes' },
    filters: [
      {
        kind: 'select',
        key: FILTER_ACTIVE,
        label: 'Active',
        options: () => ACTIVE_OPTIONS,
      },
    ],
    columns: VOUCHERS_COLUMNS,
    empty: {
      title: 'No vouchers yet',
      hint: 'Add a code to give buyers a discount in the cart.',
    },
  }
}
