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
