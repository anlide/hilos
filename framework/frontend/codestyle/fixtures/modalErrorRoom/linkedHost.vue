<script setup lang="ts">
import {
  HilosModal as Dialog,
  HilosViewportTable as Grid,
} from '@hilos/vue/base'
import Panel from './linkedPanel.vue'
import PageOnly from './pageOnly.vue'
import { useTrackedAction } from '@hilos/vue/base'

const action = useTrackedAction()
</script>

<template>
  <Panel />
  <PageOnly />
  <Dialog :model-value="true">
    <template #actions>
      <Panel />
    </template>
    <template #body>
      <Panel />
      <Grid>
        <template #cell-name>
          <div class="invisible">{{ action.error }}</div>
          <Dialog :model-value="true">
            <div class="invisible">{{ action.error }}</div>
          </Dialog>
        </template>
      </Grid>
      <table>
        <tbody>
          <tr>
            <td>
              <p class="invisible">{{ action.error }}</p>
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </Dialog>
</template>
