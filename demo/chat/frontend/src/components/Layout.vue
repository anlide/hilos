<template>
  <div class="layout d-flex flex-column app-shell">
    <nav
      class="navbar navbar-expand-lg navbar-dark flex-shrink-0"
      :class="chatStore.isConnected ? 'bg-primary' : 'bg-danger'"
    >
      <div class="container-fluid">
        <router-link
          class="navbar-brand"
          :class="{ 'fw-bold': route.name === 'home' }"
          to="/"
          data-id="nav-brand"
        >
          Chat Hilos Demo
        </router-link>
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
              <router-link
                class="nav-link d-inline-flex align-items-center justify-content-center fs-5"
                to="/profile"
                :class="{ 'fw-bold': route.name === 'profile' }"
                aria-label="Profile"
                data-id="nav-profile"
              >
                <i class="bi bi-person-circle" aria-hidden="true"></i>
                <span class="visually-hidden">Profile</span>
              </router-link>
            </li>
            <li class="nav-item">
              <router-link
                class="nav-link d-inline-flex align-items-center justify-content-center fs-5"
                to="/admin"
                :class="{ 'fw-bold': isAdminRoute }"
                aria-label="Admin"
                data-id="nav-admin"
              >
                <i class="bi bi-gear-fill" aria-hidden="true"></i>
                <span class="visually-hidden">Admin</span>
              </router-link>
            </li>
          </ul>
        </div>
      </div>
    </nav>
    <main class="container-fluid py-3 flex-grow-1 min-h-0 d-flex flex-column overflow-hidden">
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
import { computed, ref, watch } from 'vue'
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

const isAdminRoute = computed(() => {
  return route.name === 'admin' ||
    route.name === 'admin_users' ||
    route.name === 'admin_moderator' ||
    route.name === 'admin_bots'
})

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
    case 'hilos':
      return 'hilos'
    case 'hilos_settings':
      return 'hilos_settings'
    case 'hilos_i18n':
      return 'hilos_i18n'
    case 'hilos_guardian':
      return 'hilos_guardian'
    case 'hilos_guardian_agent':
      return 'hilos_guardian_agent'
    case 'hilos_analytics':
      return 'hilos_analytics'
    default:
      return null
  }
}

const normalizeParams = (params: Record<string, unknown>): Record<string, string> => {
  const normalized: Record<string, string> = {}

  for (const key in params) {
    normalized[key] = String(params[key])
  }

  return normalized
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

.min-h-0 {
  min-height: 0;
}
</style>
