<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">SIL requests</h5>
        </div>
        <div class="card-body">
          <p class="text-body-secondary small mb-3">Recent requests to the System Intelligence Layer (stub data).</p>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Id</th>
                  <th scope="col">User</th>
                  <th scope="col">Summary</th>
                  <th scope="col">When (stub)</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in rows" :key="r.id">
                  <td>{{ r.id }}</td>
                  <td>
                    <router-link :to="`/hilos/sil/users/${r.userId}`">user #{{ r.userId }}</router-link>
                  </td>
                  <td class="small">{{ r.summary }}</td>
                  <td class="small text-body-secondary">{{ r.when }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useHead } from '@unhead/vue'

const rows = [
  { id: 'sil-req-1001', userId: '12', summary: 'Explain architecture trade-offs', when: '2025-03-20 14:02' },
  { id: 'sil-req-1002', userId: '7', summary: 'Audit moderator rules', when: '2025-03-20 09:41' },
]

useHead({
  title: 'SIL requests | Hilos | Chat Demo',
})
</script>
