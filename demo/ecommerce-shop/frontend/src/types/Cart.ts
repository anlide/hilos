import { z } from '@hilos/core/base'

import { KOPECK_AMOUNT_PATTERN } from '../money/money.js'

const totalSchema = z.string().regex(KOPECK_AMOUNT_PATTERN)
const voucherIdSchema = z
  .number()
  .refine((id) => Number.isSafeInteger(id) && id > 0)

/** The complete `data.cart` snapshot sent by Cart and Checkout. */
export const cartSnapshotSchema = z
  .object({
    userId: z.number().int().positive().nullable(),
    guestCartKey: z.string().min(1).nullable(),
    lines: z.array(
      z.object({
        lineId: z.number().int().positive(),
        productId: z.number().int().positive(),
        slug: z.string().min(1),
        name: z.string(),
        kind: z.enum(['unique', 'stock']),
        pictureUrl: z.string(),
        price: z.number().int().nonnegative(),
        quantity: z.number().int().positive(),
        availableQuantity: z.number().int().nonnegative(),
        lineTotal: totalSchema,
      }),
    ),
    itemsTotal: totalSchema,
    // A switched-off voucher stays selected but takes nothing off.
    voucher: z
      .discriminatedUnion('active', [
        z.object({
          id: voucherIdSchema,
          code: z.string().min(1),
          active: z.literal(true),
          discount: totalSchema,
        }),
        z.object({
          id: voucherIdSchema,
          code: z.string().min(1),
          active: z.literal(false),
          discount: z.literal('0'),
        }),
      ])
      .nullable(),
    total: totalSchema,
  })
  .refine(
    ({ userId, guestCartKey }) => (userId === null) === (guestCartKey !== null),
    {
      path: ['guestCartKey'],
    },
  )

export type CartSnapshot = z.infer<typeof cartSnapshotSchema> & {
  readonly pageSubscriptionProof?: string
}
export type CartLine = CartSnapshot['lines'][number]
export type CartVoucher = NonNullable<CartSnapshot['voucher']>

/**
 * Address one confirmed cart within a view.
 *
 * @param cart The snapshot carrying the current cart identity.
 */
export function cartIdentity(cart: CartSnapshot): string {
  return cart.userId === null
    ? `guest:${cart.guestCartKey}`
    : `account:${cart.userId}`
}
