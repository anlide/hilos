<!-- The addressed node's WebSocket count, carried by its page scope. -->
<script setup lang="ts">
import {
  DAEMON_NODE_STATE_SILENT,
  formatDaemonNumber,
  readHilosDaemonNodeHeading,
  readHilosDaemonWebsockets,
  type HilosDaemonContext,
} from '@hilos/core/admin/daemon'
import { computedSignal, HilosPages } from '@hilos/core/base'
import { computed, inject } from 'vue'

import { hilosRouterKey } from '../../hilosRouterKey.js'
import { useSignal } from '../../useSignal.js'
import HilosDaemonNodeShell from './HilosDaemonNodeShell.vue'

const props = defineProps<{ context: HilosDaemonContext }>()
const router = inject(hilosRouterKey)
if (!router)
  throw new Error('HilosDaemonWebsocketsPage requires a provided Hilos router.')

const websockets = readHilosDaemonWebsockets(props.context.scopes)
const nodeHeading = readHilosDaemonNodeHeading(props.context.scopes)
const current = useSignal(
  computedSignal(() => {
    const route = router.currentRoute.get()
    const heading = nodeHeading.get()
    return props.context.scopes.page()?.key === HilosPages.DAEMON_WEBSOCKETS &&
      route.page === HilosPages.DAEMON_WEBSOCKETS &&
      router.pageError.get() === null &&
      typeof route.params.nodeId === 'string' &&
      route.params.nodeId !== '' &&
      heading !== null
      ? { heading, websockets: websockets.get() }
      : null
  }),
)
const data = computed(() => current.value?.websockets)
const count = computed(() =>
  data.value?.dataNode ? '—' : formatDaemonNumber(data.value?.connections),
)
const dataNodeNote = 'Data nodes do not accept browsers'
const waitingNote = 'Waiting for the node report'
const silentNote = 'At the time of the last report'
const liveNote = 'Open on this node right now'
const notes = [dataNodeNote, waitingNote, silentNote, liveNote]
const note = computed(() =>
  data.value?.dataNode
    ? dataNodeNote
    : data.value?.connections == null
      ? waitingNote
      : current.value?.heading.state === DAEMON_NODE_STATE_SILENT
        ? silentNote
        : liveNote,
)
</script>

<template>
  <HilosDaemonNodeShell :context="context" :page="HilosPages.DAEMON_WEBSOCKETS">
    <div class="row row-cols-1 row-cols-md-2 g-2 mb-3">
      <div class="col">
        <div
          class="border rounded-3 p-3 h-100"
          data-id="hilos-daemon-websockets-tile"
        >
          <div class="d-flex align-items-center gap-2 text-body-secondary mb-1">
            <i class="bi bi-plug" aria-hidden="true"></i>
            <span class="small">WebSocket connections now</span>
          </div>
          <div
            class="fs-5 lh-1 mb-1 text-break"
            data-id="hilos-daemon-websockets-count"
          >
            {{ count }}
          </div>
          <!-- Every known caption holds its room in the same grid cell. -->
          <div class="hilos-stack small text-body-secondary">
            <div
              v-for="message in notes"
              :key="message"
              class="invisible"
              aria-hidden="true"
              inert
            >
              {{ message }}
            </div>
            <div data-id="hilos-daemon-websockets-note">{{ note }}</div>
          </div>
        </div>
      </div>
    </div>
  </HilosDaemonNodeShell>
</template>
