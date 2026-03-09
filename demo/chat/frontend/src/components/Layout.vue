<template>
  <div class="layout d-flex flex-column app-shell">
    <nav
      class="navbar navbar-expand-lg navbar-dark flex-shrink-0"
      :class="chatStore.isConnected ? 'bg-primary' : 'bg-danger'"
    >
      <div class="container-fluid">
        <router-link class="navbar-brand" to="/">WebSocket Chat Demo</router-link>
        <span v-if="!chatStore.isConnected" class="badge bg-dark ms-2 align-middle" data-id="nav-offline">offline</span>
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
              <router-link class="nav-link" to="/" :class="{ 'fw-bold': $route.name === 'home' }" data-id="nav-home">
                Home
              </router-link>
            </li>
            <li class="nav-item">
              <router-link class="nav-link" to="/profile" :class="{ 'fw-bold': $route.name === 'profile' }" data-id="nav-profile">
                Profile
              </router-link>
            </li>
            <li class="nav-item">
              <router-link
                class="nav-link"
                to="/admin"
                :class="{ 'fw-bold': $route.name === 'admin' || $route.name === 'admin_users' || $route.name === 'admin_moderator' || $route.name === 'admin_bots' }"
                data-id="nav-admin"
              >
                Admin
              </router-link>
            </li>
          </ul>
        </div>
      </div>
    </nav>
    <main class="container-fluid py-3 flex-grow-1 overflow-hidden d-flex flex-column">
      <router-view />
    </main>
    <footer class="footer flex-shrink-0 py-2 border-top">
      <div class="container-fluid">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-2 text-body-secondary small">
          <span>
            <router-link to="/licence" class="text-body-secondary text-decoration-none">MIT licence</router-link>
          </span>
          <div class="d-flex flex-wrap justify-content-center gap-2 gap-sm-3">
            <router-link to="/terms" class="text-body-secondary text-decoration-none">terms</router-link>
            <router-link to="/privacy" class="text-body-secondary text-decoration-none">privacy</router-link>
            <router-link to="/agents" class="text-body-secondary text-decoration-none">agents</router-link>
          </div>
        </div>
      </div>
    </footer>
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

<style scoped>
.app-shell {
  height: 100dvh;
  overflow: hidden;
}
</style>
