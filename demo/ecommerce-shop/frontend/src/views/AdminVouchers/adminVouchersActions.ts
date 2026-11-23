import type { ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'
import { type VoucherRow } from './types/tables/VoucherRow.js'
import { type VoucherDraft } from './voucherEdit.js'

/** Action name on the server (EcommerceShopSignalConstants::VOUCHER_UPDATE). */
export const VOUCHER_UPDATE = 'voucher_update'

/**
 * Create a voucher through VoucherCreateActionDTO. The server checks the raw
 * fields and the new row arrives separately through the live table.
 *
 * @param code The code as typed.
 * @param discountPercent The discount as typed.
 * @param active Whether the code is active.
 */
export function sendVoucherCreate(
  code: string,
  discountPercent: string,
  active: boolean,
): ActionHandle {
  return actions.dispatch('voucher_create', { code, discountPercent, active })
}

/**
 * Save the raw voucher fields if the saved values still match the focused row.
 *
 * @param id The id of the voucher opened for editing.
 * @param draft The fields entered in the dialog.
 * @param saved The voucher row as seen at submit.
 */
export function sendVoucherUpdate(
  id: number,
  draft: VoucherDraft,
  saved: VoucherRow,
): ActionHandle {
  // VoucherUpdateActionDTO owns the action keys. The id and expected values
  // read the same fields VoucherTableRow publishes from the voucher object.
  return actions.dispatch(VOUCHER_UPDATE, {
    id,
    code: draft.code,
    discountPercent: draft.discountPercent,
    active: draft.active,
    expectedCode: saved.code,
    expectedDiscountPercent: saved.discountPercent,
    expectedActive: saved.active,
  })
}
