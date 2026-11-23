<script setup>
import { RouterLink } from 'vue-router';
</script>

<template>
    <main class="not-found" aria-labelledby="not-found-title">
        <p>Ошибка 404</p>
        <h1 id="not-found-title">Страница не найдена</h1>
        <p>Проверьте адрес или перейдите на главную страницу «Доброго Жука».</p>
        <RouterLink to="/">На главную</RouterLink>
    </main>
</template>

<style scoped>
.not-found { max-width: 42rem; margin: 3rem auto; padding: 1.5rem; overflow-wrap: anywhere; }
.not-found h1 { font-size: clamp(1.5rem, 5vw, 2.5rem); }
.not-found a { display: inline-block; padding: .75rem 1rem; color: #305330; text-decoration: underline; }
.not-found a:focus-visible { outline: 3px solid #305330; outline-offset: 3px; }
</style>
