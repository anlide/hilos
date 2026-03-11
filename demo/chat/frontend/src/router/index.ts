import { createBaseRouter } from '@hilos/sdk/router'
import { demoRoutes } from './routes'

/**
 * Create router using base router factory from framework.
 * Used when not in SSG mode (e.g. ViteSSG creates its own from routes).
 */
const router = createBaseRouter(demoRoutes)

export default router
export { demoRoutes }
