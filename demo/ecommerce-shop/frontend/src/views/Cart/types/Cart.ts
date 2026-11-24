import { z } from '@hilos/core/base'

import { KOPECK_AMOUNT_PATTERN } from '../../../money/money.js'

const totalSchema = z.string().regex(KOPECK_AMOUNT_PATTERN)

/** The complete `data.cart` snapshot sent by the Cart page. */
export const cartSnapshotSchema = z.object({
  userId: z.number().int().positive().nullable(),
  lines: z.array(
    z.object({
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
})

export type CartSnapshot = z.infer<typeof cartSnapshotSchema>
export type CartLine = CartSnapshot['lines'][number]
