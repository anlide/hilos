import type { ActionHandle } from '@hilos/core/base'

import { actions } from '../../bootstrap/connection.js'

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
