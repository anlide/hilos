// Note: Vue Router types are resolved by demo projects via tsconfig.app.json
// IDE may show errors here, but TypeScript compiler in demo projects will resolve them correctly
// @ts-expect-error - Vue Router types are provided by demo project's node_modules
import { createRouter, createWebHistory, type RouteRecordRaw, type Router } from 'vue-router'

/**
 * Base router factory
 * Creates a router with common configuration
 * Can be extended in demo projects
 */
export function createBaseRouter(
  routes: RouteRecordRaw[] = [],
  base: string = '/'
): Router {
  return createRouter({
    history: createWebHistory(base),
    routes,
  })
}

/**
 * Helper to merge routes from framework and demo
 */
export function mergeRoutes(
  baseRoutes: RouteRecordRaw[],
  demoRoutes: RouteRecordRaw[]
): RouteRecordRaw[] {
  return [...baseRoutes, ...demoRoutes]
}

