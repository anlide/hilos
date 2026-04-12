<template>
  <DaemonSectionShell :title="pageTitle">
    <p class="text-body-secondary mb-3">
      Stub user profile. Edit fields locally (no save to server).
    </p>
    <form class="row g-3" @submit.prevent="onSubmit">
      <div class="col-12 col-md-6">
        <label class="form-label" for="hilos-user-name">Name</label>
        <input
          id="hilos-user-name"
          v-model="form.name"
          type="text"
          class="form-control"
          autocomplete="off"
        />
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label" for="hilos-user-email">Email</label>
        <input
          id="hilos-user-email"
          v-model="form.email"
          type="email"
          class="form-control"
          autocomplete="off"
        />
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label" for="hilos-user-role">Role</label>
        <input
          id="hilos-user-role"
          v-model="form.roleLabel"
          type="text"
          class="form-control"
          autocomplete="off"
        />
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary" disabled>
          Save (stub)
        </button>
      </div>
    </form>
  </DaemonSectionShell>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useHead } from '@unhead/vue'
import DaemonSectionShell from '@hilos/sdk/views/Hilos/Daemon/DaemonSectionShell.vue'
import { hilosUsersStub } from '@/constants/hilosAccessStubs'

const route = useRoute()

const userIdParam = computed(() => {
  const raw = route.params.userId
  return typeof raw === 'string' ? raw : ''
})

const stubRow = computed(() => hilosUsersStub.find((u) => u.userId === userIdParam.value))

const form = ref({
  name: '',
  email: '',
  roleLabel: '',
})

watch(
  [userIdParam, stubRow],
  () => {
    const s = stubRow.value
    if (s) {
      form.value = { name: s.name, email: s.email, roleLabel: s.roleLabel }
    } else {
      form.value = { name: '', email: '', roleLabel: '' }
    }
  },
  { immediate: true },
)

const pageTitle = computed(() => {
  const id = userIdParam.value
  if (id === '') return 'User'
  return stubRow.value ? `User · ${stubRow.value.name}` : `User · ${id}`
})

const onSubmit = () => {
  /* Stub — no backend */
}

const headTitle = computed(() => `${pageTitle.value} | Hilos | Chat Demo`)

useHead({
  title: headTitle,
})
</script>
