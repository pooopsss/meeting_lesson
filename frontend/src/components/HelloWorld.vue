<script setup>
import Button from "primevue/button";

defineProps({
  msg: String,
});
</script>

<template>
  <div class="greetings">
    <h1 class="green">{{ msg }}</h1>
    <p>Frontend работает!</p>
    <Button label="Hello World" icon="pi pi-check" />
  </div>
</template>

<style scoped>
h1 {
  font-weight: 500;
  font-size: 2.6rem;
}
.greetings {
  text-align: center;
}
.greetings h1,
.greetings p {
  margin-bottom: 1rem;
}
.green {
  color: var(--p-primary-color);
}
@media (min-width: 1024px) {
  .greetings h1 {
    font-size: 3rem;
  }
}
</style>
