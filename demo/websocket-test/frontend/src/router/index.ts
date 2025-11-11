import { createBaseRouter } from '@hilos/sdk/router'
import Home from '@/views/Home.vue'
import type { RouteRecordRaw } from 'vue-router'

/**
 * Demo-specific routes
 */
const demoRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    name: 'home',
    component: Home
  }
]

/**
 * Create router using base router factory from framework
 * Extends base router with demo-specific routes
 */
const router = createBaseRouter(demoRoutes)

export default router
