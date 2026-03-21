export type PageCatalogEntry = {
  id: string
  parentId: string | null
  label: string
  dynamicParam: string | null
  hideBreadcrumb: boolean
}

export type PageCatalogState = Record<string, PageCatalogEntry>

export const normalizePageCatalog = (value: Record<string, unknown>): PageCatalogState => {
  const normalized: PageCatalogState = {}

  for (const [id, rawEntry] of Object.entries(value)) {
    if (typeof rawEntry !== 'object' || rawEntry === null) {
      continue
    }

    const entry = rawEntry as Record<string, unknown>
    const parentId = typeof entry.parent_id === 'string' ? entry.parent_id : null
    const label = typeof entry.label === 'string' && entry.label !== '' ? entry.label : id
    const dynamicParam = typeof entry.dynamic_param === 'string' ? entry.dynamic_param : null
    const hideBreadcrumb = entry.hide_breadcrumb === true

    normalized[id] = {
      id,
      parentId,
      label,
      dynamicParam,
      hideBreadcrumb,
    }
  }

  return normalized
}
