/**
 * Describe a storefront product's availability, with a count where relevant.
 *
 * @param availability The availability reported by the server.
 * @param units The total number of units across warehouses.
 */
export function availabilityText(availability: string, units: number): string {
  switch (availability) {
    case 'free':
      return 'One of a kind'
    case 'in_stock':
      return `In stock: ${units}`
    case 'low':
      return `Only ${units} left`
    case 'out_of_stock':
      return 'Out of stock'
    default:
      return ''
  }
}

/**
 * Choose the semantic tone of the availability badge.
 *
 * @param availability The availability reported by the server.
 */
export function availabilityTone(
  availability: string,
): 'success' | 'warning' | 'secondary' {
  switch (availability) {
    case 'free':
    case 'in_stock':
      return 'success'
    case 'low':
      return 'warning'
    default:
      return 'secondary'
  }
}
