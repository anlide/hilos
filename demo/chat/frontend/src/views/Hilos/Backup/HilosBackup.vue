<template>
  <div class="row gx-3 gy-2 gy-lg-0 flex-grow-1 h-100 min-h-0 overflow-hidden">
    <div class="col-12 col-lg-10 mx-auto d-flex flex-column h-100 min-h-0">
      <div class="card flex-grow-1 overflow-auto">
        <div class="card-header">
          <h5 class="mb-0">Backups</h5>
        </div>
        <div class="card-body">
          <p class="text-body-secondary">Read-only stub table.</p>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead>
                <tr>
                  <th scope="col">Name</th>
                  <th scope="col">Created</th>
                  <th scope="col">Size</th>
                  <th scope="col">Status</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in rows" :key="row.id">
                  <td><code>{{ row.name }}</code></td>
                  <td>{{ row.createdAt }}</td>
                  <td>{{ row.sizeLabel }}</td>
                  <td><span class="badge text-bg-success">{{ row.status }}</span></td>
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
import { hilosBackupStubRows as rows } from '@/constants/hilosBackupStubs'

useHead({
  title: 'Backups | Hilos | Chat Demo',
})
</script>
