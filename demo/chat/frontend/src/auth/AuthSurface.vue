<!-- The chat's sign-in surface: the framework default (@hilos/vue
HilosAuthSurface) with this project's context closed over it. HilosView mounts
the surface without props, so the context cannot be passed from outside — this
wrapper is what closes it, the same way Settings.vue mounts HilosSettingsPage.
The framework owns the machine, the wire, the screens and the copy; the project
owns only what hilosAuthContext declares. -->
<script setup lang="ts">
import { HilosAuthSurface } from '@hilos/vue'

import { hilosAuthContext } from './hilosAuthContext'

defineOptions({ name: 'AuthSurface' })
</script>

<template>
  <HilosAuthSurface :context="hilosAuthContext" />
</template>
