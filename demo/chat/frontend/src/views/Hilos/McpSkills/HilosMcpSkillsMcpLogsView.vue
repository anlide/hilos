<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">MCP log viewer (stub)</h5>
        </div>
        <div class="card-body">
          <p class="text-body-secondary small mb-3">
            Filters sync to the URL query string. MCP <strong>#{{ mcpId }}</strong>.
          </p>
          <div class="row g-2 mb-3">
            <div class="col-md-4">
              <label class="form-label small mb-1" for="filter-from">Time from</label>
              <input
                id="filter-from"
                v-model="timeFrom"
                type="datetime-local"
                class="form-control form-control-sm"
                autocomplete="off"
              />
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1" for="filter-to">Time to</label>
              <input
                id="filter-to"
                v-model="timeTo"
                type="datetime-local"
                class="form-control form-control-sm"
                autocomplete="off"
              />
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1" for="filter-agent">AI agent</label>
              <select id="filter-agent" v-model="aiAgent" class="form-select form-select-sm">
                <option value="">(any)</option>
                <option value="ChatAgent">ChatAgent</option>
                <option value="ModeratorAgent">ModeratorAgent</option>
                <option value="SIL">SIL</option>
              </select>
            </div>
          </div>
          <pre
            class="bg-body-secondary border rounded p-3 small mb-0 overflow-auto"
            style="max-height: 320px"
          >{{ logText }}</pre>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useHead } from '@unhead/vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const mcpId = computed(() => (typeof route.params.mcpId === 'string' ? route.params.mcpId : ''))

const timeFrom = ref('')
const timeTo = ref('')
const aiAgent = ref('')

onMounted(() => {
  timeFrom.value = typeof route.query.from === 'string' ? route.query.from : ''
  timeTo.value = typeof route.query.to === 'string' ? route.query.to : ''
  aiAgent.value = typeof route.query.agent === 'string' ? route.query.agent : ''
  if (typeof route.query.preset === 'string' && route.query.preset === 'sil_top5') {
    aiAgent.value = 'SIL'
  }
})

watch([timeFrom, timeTo, aiAgent], () => {
  void router.replace({
    query: {
      ...route.query,
      from: timeFrom.value.trim() || undefined,
      to: timeTo.value.trim() || undefined,
      agent: aiAgent.value.trim() || undefined,
    },
  })
})

const logText = computed(() => {
  const lines = [
    `[stub] mcpId=${mcpId.value} preset=${String(route.query.preset ?? '')}`,
    `[stub] window from=${timeFrom.value || '(none)'} to=${timeTo.value || '(none)'}`,
    `[stub] agent=${aiAgent.value || '(any)'}`,
    '---',
    '2025-03-16T10:00:01Z call tool=read_file agent=ChatAgent',
    '2025-03-16T10:02:44Z call tool=list_dir agent=SIL',
  ]
  return lines.join('\n')
})

useHead({
  title: 'MCP log viewer | Hilos | Chat Demo',
})
</script>
