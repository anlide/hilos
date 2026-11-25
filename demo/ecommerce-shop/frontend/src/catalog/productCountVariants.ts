/**
 * The zero, singular, and widest plural labels. Guest and account identities
 * and their respective cart rows have unsigned 32-bit keys. Each set can
 * contribute at most 2^32 - 1 to either count.
 */
export const PRODUCT_COUNT_VARIANTS = [0, 1, 2 * (2 ** 32 - 1)] as const
