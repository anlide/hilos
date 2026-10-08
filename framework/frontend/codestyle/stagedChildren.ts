// Children built in a layer before their parent page — a page with content of its
// own, never a navigation section — and served there by direct URL while the parent
// answers not_served. UNBUILT-PAGE reads this file as data: a row excuses exactly the
// "hides" finding it names and is reported once it excuses nothing.
// Who adds and removes a row: docs/agents/frontend/page-module-structure.md.
export interface StagedChild {
  layer: 'vue' | 'react' | 'angular'
  parent: string
  child: string
  leaf: string
}
export const STAGED_CHILDREN: readonly StagedChild[] = [
  {
    layer: 'vue',
    parent: 'hilos_i18n_languages',
    child: 'hilos_i18n_language',
    leaf: 'HIL-1478',
  },
]
