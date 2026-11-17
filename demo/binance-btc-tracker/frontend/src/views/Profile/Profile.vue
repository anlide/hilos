<script setup lang="ts">
import {
  createHilosProfileRename,
  type HilosProfileBinding,
  type HilosProfilePageContext,
} from '@hilos/core/base'
import { HilosProfilePage } from '@hilos/vue/base'

import {
  actionErrors,
  actions,
  connection,
} from '../../bootstrap/connection.js'
import { currentUserName, scopes } from '../../bootstrap/session.js'

defineOptions({ name: 'ProfilePage' })

const context: HilosProfilePageContext = { connection, scopes, actions }
const binding: HilosProfileBinding = {
  name: currentUserName,
  rename: createHilosProfileRename(connection, actionErrors),
}
</script>

<template>
  <HilosProfilePage :context="context" :binding="binding" />
</template>
