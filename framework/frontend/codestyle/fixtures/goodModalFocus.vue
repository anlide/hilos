<!-- The look-alikes: a mark in mutually exclusive branches, a dialog with
     nothing to fill, a body drawn by another component, and a nested mount
     that names its own landing while only the outer one is marked.
     MODAL-FOCUS must stay silent on every one of them. -->
<template>
  <HilosModal>
    <input v-if="step === 1" data-autofocus />
    <input v-else data-autofocus />
  </HilosModal>
  <HilosModal initial-focus="dialog">
    <p>nothing to fill</p>
  </HilosModal>
  <HilosModal initial-focus="inner">
    <component :is="body" />
  </HilosModal>
  <HilosModal>
    <input data-autofocus />
    <HilosModal initial-focus="dialog">
      <p>inner layer</p>
    </HilosModal>
  </HilosModal>
</template>
