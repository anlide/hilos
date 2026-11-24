<script lang="ts">
import { defineComponent } from 'vue'
import Source from './linkedReason.vue'

export default defineComponent({ components: { ErrorLine: Source } })
</script>

<template>
  <error-line />
</template>
