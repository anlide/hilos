/**
 * Route definitions for Vite SSG and Vue Router.
 * Exported for both prerendering and client-side routing.
 */
import type { RouteRecordRaw } from 'vue-router'
import Layout from '@/components/Layout.vue'
import Home from '@/views/Home.vue'
import Profile from '@/views/Profile.vue'
import Admin from '@/views/Admin.vue'
import AdminUsers from '@/views/AdminUsers.vue'
import AdminModerator from '@/views/AdminModerator.vue'
import AdminBots from '@/views/AdminBots.vue'
import User from '@/views/User.vue'
import Bot from '@/views/Bot.vue'
import Licence from '@/views/Licence.vue'
import Terms from '@/views/Terms.vue'
import Privacy from '@/views/Privacy.vue'
import Agents from '@/views/Agents.vue'
import HilosDashboard from '@/views/Hilos/HilosDashboard.vue'
import HilosSettings from '@/views/Hilos/HilosSettings.vue'
import HilosI18n from '@/views/Hilos/HilosI18n.vue'
import HilosGuardian from '@/views/Hilos/HilosGuardian.vue'
import HilosGuardianAgent from '@/views/Hilos/HilosGuardianAgent.vue'
import HilosAnalytics from '@/views/Hilos/HilosAnalytics.vue'
import ErrorPage from '@/views/ErrorPage.vue'

export const demoRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: Layout,
    children: [
      {
        path: '',
        name: 'home',
        component: Home,
        meta: { page: 'main' },
      },
      {
        path: 'profile',
        name: 'profile',
        component: Profile,
        meta: { page: 'profile' },
      },
      {
        path: 'admin',
        name: 'admin',
        component: Admin,
        meta: { page: 'admin' },
      },
      {
        path: 'admin/users',
        name: 'admin_users',
        component: AdminUsers,
        meta: { page: 'admin_users' },
      },
      {
        path: 'admin/moderator',
        name: 'admin_moderator',
        component: AdminModerator,
        meta: { page: 'admin_moderator' },
      },
      {
        path: 'admin/bots',
        name: 'admin_bots',
        component: AdminBots,
        meta: { page: 'admin_bots' },
      },
      {
        path: 'user/:id',
        name: 'user',
        component: User,
        meta: { page: 'user' },
      },
      {
        path: 'bot/:id',
        name: 'bot',
        component: Bot,
        meta: { page: 'bot' },
      },
      {
        path: 'licence',
        name: 'licence',
        component: Licence,
      },
      {
        path: 'terms',
        name: 'terms',
        component: Terms,
      },
      {
        path: 'privacy',
        name: 'privacy',
        component: Privacy,
      },
      {
        path: 'hilos',
        name: 'hilos',
        component: HilosDashboard,
        meta: { page: 'hilos' },
      },
      {
        path: 'hilos/settings',
        name: 'hilos_settings',
        component: HilosSettings,
        meta: { page: 'hilos_settings' },
      },
      {
        path: 'hilos/i18n',
        name: 'hilos_i18n',
        component: HilosI18n,
        meta: { page: 'hilos_i18n' },
      },
      {
        path: 'hilos/guardian',
        name: 'hilos_guardian',
        component: HilosGuardian,
        meta: { page: 'hilos_guardian' },
      },
      {
        path: 'hilos/guardian/:agentId',
        name: 'hilos_guardian_agent',
        component: HilosGuardianAgent,
        meta: { page: 'hilos_guardian_agent' },
      },
      {
        path: 'hilos/analytics',
        name: 'hilos_analytics',
        component: HilosAnalytics,
        meta: { page: 'hilos_analytics' },
      },
      {
        path: 'agents',
        name: 'agents',
        component: Agents,
      },
    ],
  },
  // Error pages for prerendering (4xx, 5xx)
  { path: '/400', name: 'error-400', component: ErrorPage, meta: { errorCode: 400 } },
  { path: '/401', name: 'error-401', component: ErrorPage, meta: { errorCode: 401 } },
  { path: '/402', name: 'error-402', component: ErrorPage, meta: { errorCode: 402 } },
  { path: '/403', name: 'error-403', component: ErrorPage, meta: { errorCode: 403 } },
  { path: '/404', name: 'error-404', component: ErrorPage, meta: { errorCode: 404 } },
  { path: '/405', name: 'error-405', component: ErrorPage, meta: { errorCode: 405 } },
  { path: '/408', name: 'error-408', component: ErrorPage, meta: { errorCode: 408 } },
  { path: '/409', name: 'error-409', component: ErrorPage, meta: { errorCode: 409 } },
  { path: '/410', name: 'error-410', component: ErrorPage, meta: { errorCode: 410 } },
  { path: '/429', name: 'error-429', component: ErrorPage, meta: { errorCode: 429 } },
  { path: '/500', name: 'error-500', component: ErrorPage, meta: { errorCode: 500 } },
  { path: '/502', name: 'error-502', component: ErrorPage, meta: { errorCode: 502 } },
  { path: '/503', name: 'error-503', component: ErrorPage, meta: { errorCode: 503 } },
  { path: '/504', name: 'error-504', component: ErrorPage, meta: { errorCode: 504 } },
]
