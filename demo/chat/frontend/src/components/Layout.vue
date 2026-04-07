<template>
  <div class="layout d-flex flex-column app-shell">
    <nav
      class="navbar navbar-expand-lg navbar-dark flex-shrink-0 overflow-visible z-3"
      :class="connectionStore.isConnected ? 'bg-primary' : 'bg-danger'"
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
        <span v-if="!connectionStore.isConnected" class="badge bg-dark ms-2 align-middle" data-id="nav-offline">offline</span>
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
        <div class="collapse navbar-collapse overflow-visible" id="navbarNav">
          <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
            <li class="nav-item">
              <button
                type="button"
                class="nav-link border-0 bg-transparent p-2 d-inline-flex align-items-center justify-content-center fs-5"
                title="Language (coming soon)"
                data-id="nav-language-stub"
                @click="onLanguageStub"
              >
                <i class="bi bi-translate" aria-hidden="true"></i>
                <span class="visually-hidden">Language</span>
              </button>
            </li>
            <li class="nav-item dropdown" @click.stop>
              <button
                type="button"
                class="nav-link border-0 bg-transparent p-2 d-inline-flex align-items-center justify-content-center fs-5"
                :class="{ show: themeMenuOpen }"
                :aria-expanded="themeMenuOpen"
                aria-haspopup="true"
                title="Color theme"
                data-id="nav-theme-toggle"
                @click="themeMenuOpen = !themeMenuOpen"
              >
                <i :class="['bi', themeTriggerIcon]" aria-hidden="true"></i>
                <span class="visually-hidden">Theme</span>
              </button>
              <ul
                class="dropdown-menu dropdown-menu-end shadow"
                :class="{ show: themeMenuOpen }"
                data-id="nav-theme-menu"
              >
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center gap-2"
                    :class="{ active: themePreference === 'auto' }"
                    data-id="nav-theme-auto"
                    @click="setThemePreference('auto')"
                  >
                    <i class="bi bi-circle-half flex-shrink-0" aria-hidden="true"></i>
                    <span>Auto</span>
                  </button>
                </li>
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center gap-2"
                    :class="{ active: themePreference === 'light' }"
                    data-id="nav-theme-light"
                    @click="setThemePreference('light')"
                  >
                    <i class="bi bi-brightness-high-fill flex-shrink-0" aria-hidden="true"></i>
                    <span>Light</span>
                  </button>
                </li>
                <li>
                  <button
                    type="button"
                    class="dropdown-item d-flex align-items-center gap-2"
                    :class="{ active: themePreference === 'dark' }"
                    data-id="nav-theme-dark"
                    @click="setThemePreference('dark')"
                  >
                    <i class="bi bi-moon-stars-fill flex-shrink-0" aria-hidden="true"></i>
                    <span>Dark</span>
                  </button>
                </li>
              </ul>
            </li>
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
    <div
      v-if="shouldShowAdminBreadcrumb"
      class="container-fluid py-2 border-bottom flex-shrink-0"
    >
      <div
        v-if="showBreadcrumbPlaceholder"
        class="d-flex align-items-center gap-2 w-100 placeholder-glow"
        aria-hidden="true"
      >
        <span class="placeholder col-2 mb-0"></span>
        <span class="placeholder col-3 mb-0"></span>
        <span class="placeholder col-2 mb-0"></span>
      </div>
      <Breadcrumb v-else-if="breadcrumbItems.length > 0" :items="breadcrumbItems" />
    </div>
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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { Breadcrumb } from '@hilos/sdk/components'
import { useWebSocket } from '@hilos/sdk/plugins/websocket'
import { useConnectionStore } from '@hilos/sdk/stores'
import { useChatStore } from '@/stores'
import { MESSAGE_PAGE_FIELD, MESSAGE_PARAMS_FIELD, MESSAGE_TYPE_FIELD } from '@/constants'
import { localStorageService } from '@/services/LocalStorageService'
import { useAdminBreadcrumb } from '@/composables/useAdminBreadcrumb'
import type { ThemePreference } from '@/services/LocalStorageService'

type RouteSnapshot = {
  page: string
  params: Record<string, string>
}

const route = useRoute()
const websocket = useWebSocket()
const connectionStore = useConnectionStore()
const chatStore = useChatStore()
const {
  items: breadcrumbItems,
  showPlaceholder: showBreadcrumbPlaceholder,
  shouldShowAdminBreadcrumb,
} = useAdminBreadcrumb()

const themeMenuOpen = ref(false)
const themePreference = ref<ThemePreference>(localStorageService.getThemePreference())

const themeTriggerIcon = computed(() => {
  switch (themePreference.value) {
    case 'light':
      return 'bi-brightness-high-fill'
    case 'dark':
      return 'bi-moon-stars-fill'
    default:
      return 'bi-circle-half'
  }
})

let unsubscribeStorage: (() => void) | null = null

const syncThemePreferenceFromStorage = () => {
  themePreference.value = localStorageService.getThemePreference()
}

const setThemePreference = (preference: ThemePreference) => {
  localStorageService.setThemePreference(preference)
  themeMenuOpen.value = false
}

const onLanguageStub = () => {
  // Stub: future i18n switcher
}

const onDocumentClickCloseTheme = (event: MouseEvent) => {
  const target = event.target as Node
  const menu = document.querySelector('[data-id="nav-theme-menu"]')
  const toggle = document.querySelector('[data-id="nav-theme-toggle"]')
  if (menu?.contains(target) || toggle?.contains(target)) {
    return
  }
  themeMenuOpen.value = false
}

onMounted(() => {
  unsubscribeStorage = localStorageService.onChange(() => {
    syncThemePreferenceFromStorage()
  })
  document.addEventListener('click', onDocumentClickCloseTheme)
})

onUnmounted(() => {
  unsubscribeStorage?.()
  unsubscribeStorage = null
  document.removeEventListener('click', onDocumentClickCloseTheme)
})

const lastSnapshot = ref<RouteSnapshot | null>(null)
const pendingSnapshot = ref<RouteSnapshot | null>(null)
const pendingUpdate = ref(false)

const currentPageId = computed(() => {
  return typeof route.meta.page === 'string' ? route.meta.page : null
})

const isAdminRoute = computed(() => {
  return route.name === 'admin' ||
    route.name === 'admin_users' ||
    route.name === 'admin_moderator' ||
    route.name === 'admin_bots'
})

const normalizeParams = (params: Record<string, unknown>): Record<string, string> => {
  const normalized: Record<string, string> = {}

  for (const key in params) {
    normalized[key] = String(params[key])
  }

  return normalized
}

const queueSubscription = (snapshot: RouteSnapshot, update: boolean) => {
  if (!connectionStore.isConnected) {
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
  const page = currentPageId.value
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

watch(() => connectionStore.isConnected, (isConnected) => {
  if (isConnected && pendingSnapshot.value) {
    queueSubscription(pendingSnapshot.value, pendingUpdate.value)
    return
  }
  if (isConnected) {
    const page = currentPageId.value
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

/* Unstyled <button class="nav-link"> matches <a class="nav-link"> icon weight */
.navbar .navbar-nav button.nav-link {
  cursor: pointer;
}

.navbar .dropdown-menu .dropdown-item.active,
.navbar .dropdown-menu .dropdown-item:active {
  font-weight: 600;
}
</style>
