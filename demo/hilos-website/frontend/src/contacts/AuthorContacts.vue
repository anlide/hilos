<script setup lang="ts">
import { AUTHOR_CONTACTS, AUTHOR_NAME } from './authorContacts.js'
</script>

<template>
  <p>
    Hilos is made by {{ AUTHOR_NAME }}. Questions, ideas or work together —
    write to me.
  </p>
  <ul class="list-unstyled d-flex flex-wrap gap-3 mb-0">
    <li v-for="contact in AUTHOR_CONTACTS" :key="contact.href">
      <a :href="contact.href"
        ><i :class="['bi', contact.icon, 'me-1']" aria-hidden="true"></i
        >{{ contact.label }}</a
      >
    </li>
  </ul>
</template>
