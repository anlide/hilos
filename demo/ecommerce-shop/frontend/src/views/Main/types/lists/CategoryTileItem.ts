// The home page tile view-model: one category as its tile on the shop's home
// renders it. The backend sends the category as an inline record in the
// `categories` slot of the home's categories list, and the number of products
// is added up in the browser from the home's products list; the selector that
// builds it (homeTiles.ts) lives with the page (data-model.md — list-row
// view-models live in types/lists/).

/** One category tile of the shop's home. */
export interface CategoryTileItem {
  /** The list item key; unique within the home's categories list. */
  readonly key: string
  /** Category name. */
  readonly name: string
  /** Category description. */
  readonly description: string
  /** The category's address segment. */
  readonly slug: string
  /** Address of the category's tile picture. */
  readonly picture: string
  /** Products of the category that stand on the shop front. */
  readonly productCount: number
}
