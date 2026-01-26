<template>
  <div class="row">
    <div class="col-12 col-lg-8 mx-auto">
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">User</h5>
        </div>
        <div class="card-body">
          <p class="text-muted">Заготовка</p>
          <p>User ID: {{ userId }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const userId = computed(() => route.params.id as string)
</script>
