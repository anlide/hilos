// The categories admin table row view-model: one category as the admin table
// renders it. The backend categories table carries the whole row as an inline
// record in the `category` slot; the selector that reads it
// (adminCategoriesPage.ts) lives with the page (data-model.md — table-row
// view-models live in types/tables/).

/** One row of the categories admin table. */
export interface CategoryRow {
  /** Category id; also the table row key. */
  readonly id: number
  /** Category name. */
  readonly name: string
  /** What the category groups, in a sentence. */
  readonly description: string
  /** Place of the category in the catalog, from the first. */
  readonly position: number
  /** Address of the category thumbnail in the table. */
  readonly picture: string
  /** Registry id of the saved category picture. */
  readonly pictureFileId: number
  /** Address of the saved category picture in the edit form. */
  readonly picturePreview: string
  /** Products the category holds. */
  readonly productCount: number
  /** Whether no category comes before it in the catalog. */
  readonly first: boolean
}
