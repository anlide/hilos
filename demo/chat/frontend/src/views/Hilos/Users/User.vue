<!-- The framework user Overview with the chat participant's existing card.
The project fills the permitted detail slot; navigation needs no extra data. -->
<script setup lang="ts">
import { HilosUserPage } from '@hilos/vue/admin/users'
import { HilosLink } from '@hilos/vue/base'

import { PAGE_USER } from '../../../pages/keys.js'
import { router } from '../../../pages/routes.js'
import { hilosUsersContext } from './hilosUsersContext.js'

defineOptions({ name: 'HilosUserPage' })
</script>

<template>
  <HilosUserPage :context="hilosUsersContext">
    <template #project-overview="{ detail }">
      <section class="mb-4">
        <h2 class="h5">Chat participant</h2>
        <div class="card">
          <div class="card-body">
            <HilosLink
              :to="router.path(PAGE_USER, { id: String(detail.id) })!"
              data-id="chat-participant-open"
            >
              Open participant card
            </HilosLink>
          </div>
        </div>
      </section>
    </template>
  </HilosUserPage>
</template>
