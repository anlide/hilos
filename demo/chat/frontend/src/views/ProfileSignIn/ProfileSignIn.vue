<script setup lang="ts">
import { HilosProfileSignInPage } from '@hilos/vue'
import { hilosAuthContext } from '../../auth/hilosAuthContext.js'
</script>

<template>
  <HilosProfileSignInPage :context="hilosAuthContext" />
</template>
