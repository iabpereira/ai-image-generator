<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import GenerationForm from '@/components/GenerationForm.vue';
import { create } from '@/routes/generations';
import type { Pricing } from '@/types/generation';

defineProps<{ pricing: Pricing | null; markupBasisPoints: number }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Generate', href: create() }],
    },
});
</script>

<template>
    <Head title="Generate" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Create an image</h1>
            <p class="text-sm text-muted-foreground">
                Describe it, pick a size and quality, and see the price before
                you generate. You'll follow its progress on the dashboard.
            </p>
        </div>

        <GenerationForm
            :pricing="pricing"
            :markup-basis-points="markupBasisPoints"
        />
    </div>
</template>
