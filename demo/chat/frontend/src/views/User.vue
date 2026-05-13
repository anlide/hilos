<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-8 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">User</h5>
        </div>
        <div class="card-body">
          <template v-if="!connectionStore.isConnected">
            <div class="placeholder-glow">
              <span class="placeholder col-4 mb-2 d-block" style="height: 0.875rem"></span>
              <span class="placeholder col-3 mb-2 d-block" style="height: 0.875rem"></span>
              <span class="placeholder col-5" style="height: 0.875rem"></span>
            </div>
          </template>
          <template v-else-if="parsedUserId === null">
            <p class="text-muted mb-0">Invalid user ID.</p>
          </template>
          <template v-else-if="pageError">
            <div class="alert alert-warning mb-0" role="alert">
              {{ pageError.message }}
            </div>
          </template>
          <template v-else-if="isLoading">
            <p class="text-muted mb-0">Loading user...</p>
          </template>
          <template v-else-if="!user">
            <p class="text-muted mb-0">User not found.</p>
          </template>
          <template v-else>
            <p class="text-muted">User profile</p>
            <p>User ID: {{ user.id }}</p>
            <p>Name: {{ user.name }}</p>
            <p>Last activity: {{ formatDate(user.lastActivity) }}</p>
            <p>
              Presence:
              <span :class="user.presence === 'online' ? 'text-success' : 'text-secondary'">
                {{ user.presence }}
              </span>
            </p>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useBrowserStore, useConnectionStore } from '@hilos/sdk/stores'
import { subscriptionPageError, type PageSubscriptionError } from '@hilos/sdk/signals'
import { useSignalRouter } from '@/plugins/websocket'
import {
  BROWSER_TABLE_USER_DETAIL,
  userDetailFromBrowserRow,
} from '@/entities/browserUserDetail'

const route = useRoute()
const connectionStore = useConnectionStore()
const browserStore = useBrowserStore()
const signalRouter = useSignalRouter()
const browserPageKey = 'subscription_page_user'

const parsedUserId = computed((): number | null => {
  const id = route.params.id
  const parsed = typeof id === 'string' ? parseInt(id, 10) : Number(id)
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null
})

const tableState = computed(() => {
  return browserStore.pages[browserPageKey]?.tables[BROWSER_TABLE_USER_DETAIL] ?? null
})

const user = computed(() => {
  const id = parsedUserId.value
  if (id === null) return null
  return userDetailFromBrowserRow(tableState.value?.rowsByKey[String(id)])
})

const pageError = ref<PageSubscriptionError | null>(null)
const loadedUserId = ref<number | null>(null)

const isLoading = computed(() => {
  const id = parsedUserId.value
  return id !== null
    && pageError.value === null
    && user.value === null
    && loadedUserId.value !== id
})

const onSubscriptionError = (error: PageSubscriptionError) => {
  if (error.page !== 'user') {
    return
  }
  const match = error.message?.match(/#(\d+)/)
  const errorUserId = match?.[1] ? parseInt(match[1], 10) : null
  if (errorUserId !== null && errorUserId !== parsedUserId.value) {
    return
  }
  pageError.value = error
}

watch(parsedUserId, () => {
  loadedUserId.value = null
  pageError.value = null
})

watch(user, (current) => {
  if (current !== null) {
    loadedUserId.value = current.id
  }
}, { immediate: true })

onMounted(() => {
  signalRouter.on(subscriptionPageError, onSubscriptionError)
})

const formatDate = (dateStr: string | null | undefined): string => {
  if (!dateStr) return 'Never'
  try {
    return new Date(dateStr).toLocaleString()
  } catch {
    return dateStr
  }
}
</script>
