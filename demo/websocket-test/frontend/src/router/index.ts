import { createBaseRouter } from '@hilos/sdk/router'
import Layout from '@/components/Layout.vue'
import Home from '@/views/Home.vue'
import Profile from '@/views/Profile.vue'
import Admin from '@/views/Admin.vue'
import AdminUsers from '@/views/AdminUsers.vue'
import AdminModerator from '@/views/AdminModerator.vue'
import AdminBots from '@/views/AdminBots.vue'
import User from '@/views/User.vue'
import Bot from '@/views/Bot.vue'
import type { RouteRecordRaw } from 'vue-router'

/**
 * Demo-specific routes
 */
const demoRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: Layout,
    children: [
      {
        path: '',
        name: 'home',
        component: Home
      },
      {
        path: 'profile',
        name: 'profile',
        component: Profile
      },
      {
        path: 'admin',
        name: 'admin',
        component: Admin
      },
      {
        path: 'admin/users',
        name: 'admin_users',
        component: AdminUsers
      },
      {
        path: 'admin/moderator',
        name: 'admin_moderator',
        component: AdminModerator
      },
      {
        path: 'admin/bots',
        name: 'admin_bots',
        component: AdminBots
      },
      {
        path: 'user/:id',
        name: 'user',
        component: User
      },
      {
        path: 'bot/:id',
        name: 'bot',
        component: Bot
      }
    ]
  }
]

/**
 * Create router using base router factory from framework
 * Extends base router with demo-specific routes
 */
const router = createBaseRouter(demoRoutes)

export default router
