<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        fill="currentColor"
        :class="className"
        v-bind="$attrs"
    >
        <path
            d="M22 8H9a5 5 0 0 0-5 5v16a5 5 0 0 0 5 5h18a5 5 0 0 0 5-5V18"
            fill="none"
            stroke="currentColor"
            stroke-width="3.5"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <path d="M9 30l7-9 5 6 3-3.5 4.5 6.5z" />
        <path
            d="M31 1.5c.9 4.6 2.9 6.6 7.5 7.5-4.6.9-6.6 2.9-7.5 7.5-.9-4.6-2.9-6.6-7.5-7.5 4.6-.9 6.6-2.9 7.5-7.5z"
        />
    </svg>
</template>
