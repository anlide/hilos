<!-- The profile root (HilosPages.PROFILE, /profile, HIL-1169): a thin project
binding of the framework HilosProfilePage with its context. The profile page
and rename protocol belong to the framework. Chat provides the live name,
existing connection and action-error store, and the summary lists. Bootstrap
classes only (styling-rules.md). -->
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
import { scopes } from '../../bootstrap/session.js'
import {
  profileDeviceCount,
  profileSessionCount,
} from '../../profile/profileLists.js'
import { committedName } from './profilePage.js'

defineOptions({ name: 'ProfilePage' })

const context: HilosProfilePageContext = { connection, scopes, actions }
const binding: HilosProfileBinding = {
  name: committedName,
  rename: createHilosProfileRename(connection, actionErrors),
  sessionCount: profileSessionCount,
  deviceCount: profileDeviceCount,
}
</script>

<template>
  <HilosProfilePage :context="context" :binding="binding" />
</template>
