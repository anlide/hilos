// The default admin views: a page-key → component map covering every framework
// admin page (the HILOS_ADMIN_PAGES catalog) with the stub shell bound to its own
// key. A project mounts the whole admin section by spreading this map into its app
// page map, then overriding only the keys it has actually implemented (e.g. users,
// settings) — so the 50-odd not-yet-built admin pages live in the framework, never
// recopied into every project.
//
// This is the sanctioned form, not a God-map (page-module-structure.md): the
// catalog of identity (labels/leads/parents) stays in @hilos/core, each entry
// renders through the page-agnostic HilosAdminPage shell parametrized by its key,
// and a project's real page is still its own explicit module overriding the key.
// HilosView (not these stubs) is the one component that reads the navigator.

import { defineComponent, h, type Component } from 'vue'
import { HILOS_ADMIN_PAGES } from '@hilos/core'

import HilosAdminPage from '../HilosAdminPage.vue'

/**
 * The default admin view map: every framework admin page key bound to the stub
 * shell for that key. Spread it into the app page map ahead of the project's own
 * overrides, so an un-implemented admin page renders the framework stub and an
 * implemented one (mapped after the spread) wins.
 */
export function hilosAdminViews(): Record<string, Component> {
  const views: Record<string, Component> = {}

  for (const page of Object.keys(HILOS_ADMIN_PAGES)) {
    views[page] = defineComponent({
      name: `HilosAdminStub_${page}`,
      render: () => h(HilosAdminPage, { page }),
    })
  }

  return views
}
