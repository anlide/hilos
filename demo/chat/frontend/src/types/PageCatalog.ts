export type PageCatalogEntry = {
  id: string
  parentId: string | null
  label: string
  pathTemplate: string | null
  hideBreadcrumb: boolean
}

export type PageCatalogState = Record<string, PageCatalogEntry>

/** Extract ordered {paramName} keys from a path template */
export const pathTemplateParamKeys = (pathTemplate: string): string[] => {
  const keys: string[] = []
  const re = /\{([^}]+)}/g
  let m: RegExpExecArray | null
  while ((m = re.exec(pathTemplate)) !== null) {
    const key = m[1]
    if (key !== undefined) {
      keys.push(key)
    }
  }
  return keys
}

export const normalizePageCatalog = (value: Record<string, unknown>): PageCatalogState => {
  const normalized: PageCatalogState = {}

  for (const [id, rawEntry] of Object.entries(value)) {
    if (typeof rawEntry !== 'object' || rawEntry === null) {
      continue
    }

    const entry = rawEntry as Record<string, unknown>
    const parentId = typeof entry.parent_id === 'string' ? entry.parent_id : null
    const label = typeof entry.label === 'string' && entry.label !== '' ? entry.label : id
    const pathTemplate = typeof entry.path_template === 'string' && entry.path_template !== '' ? entry.path_template : null
    const hideBreadcrumb = entry.hide_breadcrumb === true

    normalized[id] = {
      id,
      parentId,
      label,
      pathTemplate,
      hideBreadcrumb,
    }
  }

  return normalized
}
