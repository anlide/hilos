<template>
  <div class="layout">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
      <div class="container-fluid">
        <router-link class="navbar-brand" to="/">WebSocket Chat Demo</router-link>
        <button
          class="navbar-toggler"
          type="button"
          data-bs-toggle="collapse"
          data-bs-target="#navbarNav"
          aria-controls="navbarNav"
          aria-expanded="false"
          aria-label="Toggle navigation"
        >
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
          <ul class="navbar-nav ms-auto">
            <li class="nav-item">
              <router-link class="nav-link" to="/" :class="{ 'fw-bold': $route.name === 'home' }">
                Home
              </router-link>
            </li>
            <li class="nav-item">
              <router-link class="nav-link" to="/profile" :class="{ 'fw-bold': $route.name === 'profile' }">
                Profile
              </router-link>
            </li>
            <li class="nav-item">
              <router-link 
                class="nav-link" 
                to="/admin" 
                :class="{ 'fw-bold': $route.name === 'admin' || $route.name === 'admin_users' || $route.name === 'admin_moderator' || $route.name === 'admin_bots' }"
              >
                Admin
              </router-link>
            </li>
          </ul>
        </div>
      </div>
    </nav>
    <main class="container-fluid py-4">
      <router-view />
    </main>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { useChatStore } from '@/stores'
import { MESSAGE_PAGE_FIELD, MESSAGE_PARAMS_FIELD, MESSAGE_TYPE_FIELD } from '@/constants'

type RouteSnapshot = {
  page: string
  params: Record<string, string>
}

const route = useRoute()
const websocket = useWebSocket()
const chatStore = useChatStore()

const lastSnapshot = ref<RouteSnapshot | null>(null)
const pendingSnapshot = ref<RouteSnapshot | null>(null)
const pendingUpdate = ref(false)

const resolvePage = (routeName: unknown): string | null => {
  switch (routeName) {
    case 'home':
      return 'main'
    case 'profile':
      return 'profile'
    case 'admin':
      return 'admin'
    case 'admin_users':
      return 'admin_users'
    case 'admin_moderator':
      return 'admin_moderator'
    case 'admin_bots':
      return 'admin_bots'
    case 'user':
      return 'user'
    case 'bot':
      return 'bot'
    default:
      return null
  }
}

const normalizeParams = (params: Record<string, unknown>): Record<string, string> => {
  return Object.fromEntries(
    Object.entries(params).map(([key, value]) => [key, String(value)])
  )
}

const queueSubscription = (snapshot: RouteSnapshot, update: boolean) => {
  if (!chatStore.isConnected) {
    pendingSnapshot.value = snapshot
    pendingUpdate.value = update
    return
  }

  const hasParams = Object.keys(snapshot.params).length > 0
  websocket.send({
    [MESSAGE_TYPE_FIELD]: update ? 'page_update_subscription' : 'page_subscribe',
    [MESSAGE_PAGE_FIELD]: snapshot.page,
    ...(hasParams ? { [MESSAGE_PARAMS_FIELD]: snapshot.params } : {}),
  })

  lastSnapshot.value = snapshot
  pendingSnapshot.value = null
}

const handleRouteChange = () => {
  const page = resolvePage(route.name)
  if (!page) {
    return
  }

  const params = normalizeParams(route.params as Record<string, unknown>)
  const snapshot = { page, params }
  const previous = lastSnapshot.value

  const samePage = previous?.page === snapshot.page
  const paramsChanged = JSON.stringify(previous?.params ?? {}) !== JSON.stringify(snapshot.params)

  if (!samePage || paramsChanged) {
    queueSubscription(snapshot, false)
    return
  }

  queueSubscription(snapshot, true)
}

watch(() => [route.name, route.params], handleRouteChange, { deep: true, immediate: true })

watch(() => chatStore.isConnected, (isConnected) => {
  if (isConnected && pendingSnapshot.value) {
    queueSubscription(pendingSnapshot.value, pendingUpdate.value)
    return
  }
  if (isConnected) {
    const page = resolvePage(route.name)
    if (!page) {
      return
    }
    const params = normalizeParams(route.params as Record<string, unknown>)
    queueSubscription({ page, params }, false)
  }
})
</script>
