<script setup>
import { ref, useId } from 'vue';

const props = defineProps({ title: String, icon: String, color: { type: String, default: 'orange' },
    collapsed: { type: Boolean, default: false } });
const open = ref(!props.collapsed);
const panelId = useId();
</script>

<template>
    <div class="bg-white rounded-xl border border-slate-100 shadow-sm overflow-hidden">
        <button type="button" :aria-expanded="open" :aria-controls="panelId"
            class="w-full flex items-center justify-between px-6 py-4 text-sm font-bold text-slate-900 hover:bg-slate-50 transition-colors"
            @click="open = !open">
            <span class="flex items-center gap-2"><span aria-hidden="true">{{ icon }}</span> {{ title }}</span>
            <svg class="h-4 w-4 text-slate-400 transition-transform" :class="{ 'rotate-180': open }"
                aria-hidden="true" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
        <div v-show="open" :id="panelId" class="px-6 pb-5 pt-1 border-t border-slate-100"><slot /></div>
    </div>
</template>
