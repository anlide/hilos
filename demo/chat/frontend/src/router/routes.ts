/**
 * Route definitions for Vite SSG and Vue Router.
 * Hilos admin routes are provided by the framework via createHilosRoutes().
 */
import type { RouteRecordRaw } from 'vue-router'
import { createHilosRoutes } from '@hilos/sdk/router/hilosRoutes'
import Layout from '@/components/Layout.vue'

export const demoRoutes: RouteRecordRaw[] = [
  {
    path: '/',
    component: Layout,
    children: [
      {
        path: '',
        name: 'home',
        component: () => import('@/views/Home.vue'),
        meta: { page: 'main' },
      },
      {
        path: 'profile',
        name: 'profile',
        component: () => import('@/views/Profile.vue'),
        meta: { page: 'profile' },
      },
      {
        path: 'admin',
        name: 'admin',
        component: () => import('@/views/Admin.vue'),
        meta: { page: 'admin' },
      },
      {
        path: 'admin/users',
        name: 'admin_users',
        component: () => import('@/views/AdminUsers.vue'),
        meta: { page: 'admin_users' },
      },
      {
        path: 'admin/moderator',
        name: 'admin_moderator',
        component: () => import('@/views/AdminModerator.vue'),
        meta: { page: 'admin_moderator' },
      },
      {
        path: 'admin/bots',
        name: 'admin_bots',
        component: () => import('@/views/AdminBots.vue'),
        meta: { page: 'admin_bots' },
      },
      {
        path: 'user/:id',
        name: 'user',
        component: () => import('@/views/User.vue'),
        meta: { page: 'user' },
      },
      {
        path: 'bot/:id',
        name: 'bot',
        component: () => import('@/views/Bot.vue'),
        meta: { page: 'bot' },
      },
      {
        path: 'licence',
        name: 'licence',
        component: () => import('@/views/Licence.vue'),
      },
      {
        path: 'terms',
        name: 'terms',
        component: () => import('@/views/Terms.vue'),
      },
      {
        path: 'privacy',
        name: 'privacy',
        component: () => import('@/views/Privacy.vue'),
      },
      {
        path: 'agents',
        name: 'agents',
        component: () => import('@/views/Agents.vue'),
      },
      ...createHilosRoutes(),
    ],
  },
  { path: '/400', name: 'error-400', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 400 } },
  { path: '/401', name: 'error-401', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 401 } },
  { path: '/402', name: 'error-402', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 402 } },
  { path: '/403', name: 'error-403', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 403 } },
  { path: '/404', name: 'error-404', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 404 } },
  { path: '/405', name: 'error-405', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 405 } },
  { path: '/408', name: 'error-408', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 408 } },
  { path: '/409', name: 'error-409', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 409 } },
  { path: '/410', name: 'error-410', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 410 } },
  { path: '/429', name: 'error-429', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 429 } },
  { path: '/500', name: 'error-500', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 500 } },
  { path: '/502', name: 'error-502', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 502 } },
  { path: '/503', name: 'error-503', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 503 } },
  { path: '/504', name: 'error-504', component: () => import('@/views/ErrorPage.vue'), meta: { errorCode: 504 } },
]
