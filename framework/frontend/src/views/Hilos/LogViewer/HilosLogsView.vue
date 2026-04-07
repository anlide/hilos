<template>
  <LogViewerSectionShell title="Logs — Viewer">
    <p class="text-body-secondary">
      Filter stub lines: <strong>key</strong> takes precedence, then <strong>worker</strong>, then <strong>rotation</strong>.
    </p>

    <div class="row g-2 mb-3">
      <div class="col-md-4">
        <label class="form-label small mb-1" for="filter-key">Key</label>
        <input
          id="filter-key"
          v-model="keyFilter"
          type="text"
          class="form-control form-control-sm"
          placeholder="e.g. app.info"
          autocomplete="off"
        />
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1" for="filter-worker">Worker</label>
        <input
          id="filter-worker"
          v-model="workerFilter"
          type="text"
          class="form-control form-control-sm"
          placeholder="e.g. w-1"
          autocomplete="off"
        />
      </div>
      <div class="col-md-4">
        <label class="form-label small mb-1" for="filter-rotation">Rotation</label>
        <input
          id="filter-rotation"
          v-model="rotationFilter"
          type="text"
          class="form-control form-control-sm"
          placeholder="e.g. 1284"
          inputmode="numeric"
          autocomplete="off"
        />
      </div>
    </div>

    <pre class="bg-body-secondary border rounded p-3 small mb-0 overflow-auto" style="max-height: 320px">{{ textBlock }}</pre>
  </LogViewerSectionShell>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'
import LogViewerSectionShell from './LogViewerSectionShell.vue'
import { hilosLogsViewerLinesStub } from '../../../constants/hilosLogsStubs'

const route = useRoute()
const router = useRouter()

const keyFilter = ref('')
const workerFilter = ref('')
const rotationFilter = ref('')

onMounted(() => {
  keyFilter.value = typeof route.query.key === 'string' ? route.query.key : ''
  workerFilter.value = typeof route.query.worker === 'string' ? route.query.worker : ''
  rotationFilter.value = typeof route.query.rotation === 'string' ? route.query.rotation : ''
})

watch([keyFilter, workerFilter, rotationFilter], () => {
  void router.replace({
    query: {
      key: keyFilter.value.trim() || undefined,
      worker: workerFilter.value.trim() || undefined,
      rotation: rotationFilter.value.trim() || undefined,
    },
  })
})

const textBlock = computed(() => {
  const k = keyFilter.value.trim()
  const w = workerFilter.value.trim()
  const rotRaw = rotationFilter.value.trim()
  const rot = rotRaw === '' ? null : Number.parseInt(rotRaw, 10)

  let list = [...hilosLogsViewerLinesStub]

  if (k !== '') {
    list = list.filter((x) => x.key.includes(k))
  } else if (w !== '') {
    list = list.filter((x) => x.workerId.includes(w))
  } else if (rot !== null && Number.isFinite(rot)) {
    list = list.filter((x) => x.rotationId === rot)
  }

  return list.map((x) => x.line).join('\n')
})

useHead({
  title: 'Log viewer | Hilos | Chat Demo',
})
</script>
