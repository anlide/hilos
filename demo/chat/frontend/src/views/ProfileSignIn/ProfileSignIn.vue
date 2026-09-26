<script setup lang="ts">
import { HilosProfileSignInPage, useSignal } from '@hilos/vue'
import { hilosAuthContext } from '../../auth/hilosAuthContext.js'
import { profileSignInMethods } from '../../profile/profileLists.js'

const methods = useSignal(profileSignInMethods)
</script>

<template>
  <HilosProfileSignInPage :context="hilosAuthContext" :methods="methods" />
</template>
