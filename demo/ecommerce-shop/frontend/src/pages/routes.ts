// The ecommerce-shop page routes: the cold-load route registry the navigator
// resolves a URL against. The route engine and the framework admin catalog come
// from @hilos/core, so this file only declares the demo's own pages;
// `createAppPageRouter` mounts the `hilos_*` admin pages under them.
//
// The shop's own admin screen answers under /hilos/app/: warehouses, categories, products and one product. The `app`
// segment says "application screen, not framework screen", so a Hilos page
// added later cannot collide with it by construction. Its page key does not
// change with the address — a key is identity on the wire, a path is not.
import {
  createAppPageRouter,
  type HilosRouteDeclaration,
} from '@hilos/core/base'

import {
  PAGE_ADMIN_CATEGORIES,
  PAGE_ADMIN_PRODUCT,
  PAGE_ADMIN_PRODUCTS,
  PAGE_ADMIN_WAREHOUSES,
  PAGE_MAIN,
} from './keys.js'

/**
 * The demo's own pages keyed to the URL path template each answers and whether
 * that page is an administrative surface; the shop's own admin pages also name
 * the icon its dashboard card and section head draw. The framework admin pages
 * are merged in by `createAppPageRouter` rather than restated here.
 */
const APP_ROUTES: Record<string, HilosRouteDeclaration> = {
  [PAGE_MAIN]: { path: '/', admin: false },
  [PAGE_ADMIN_WAREHOUSES]: {
    path: '/hilos/app/warehouses',
    admin: true,
    icon: 'bi-buildings',
  },
  [PAGE_ADMIN_CATEGORIES]: {
    path: '/hilos/app/categories',
    admin: true,
    icon: 'bi-tags',
  },
  [PAGE_ADMIN_PRODUCTS]: {
    path: '/hilos/app/products',
    admin: true,
    icon: 'bi-box-seam',
  },
  [PAGE_ADMIN_PRODUCT]: {
    path: '/hilos/app/product/{productId}',
    admin: true,
    icon: 'bi-box',
  },
}

/**
 * The ecommerce-shop page router: the application pages plus the framework
 * admin catalog, resolving a cold-load URL to its page key and captured route
 * params. An address no route claims resolves to no page.
 */
export const router = createAppPageRouter(APP_ROUTES)
