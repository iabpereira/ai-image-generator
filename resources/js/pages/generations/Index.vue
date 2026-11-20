<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { create, index } from '@/routes/generations';
import type { Generation, Paginated } from '@/types/generation';

const props = defineProps<{ generations: Paginated<Generation> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'History', href: index() }],
    },
});

const POLL_INTERVAL_MS = 3000;

const hasActiveGenerations = computed(() =>
    props.generations.data.some(
        (generation) => !['succeeded', 'failed'].includes(generation.status),
    ),
);

const { start, stop } = usePoll(
    POLL_INTERVAL_MS,
    { only: ['generations'] },
    { autoStart: false },
);

watch(hasActiveGenerations, (active) => (active ? start() : stop()), {
    immediate: true,
});
</script>

<template>
    <Head title="History" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Your generations</h1>
                <p class="text-sm text-muted-foreground">
                    Status updates live. Failed generations are never charged.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"><Sparkles /> Generate</Link>
            </Button>
        </div>

        <div
            v-if="generations.data.length === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-12 text-center"
        >
            <p class="text-muted-foreground">No generations yet.</p>
            <Button as-child variant="outline">
                <Link :href="create()">Create your first image</Link>
            </Button>
        </div>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <GenerationCard
                v-for="generation in generations.data"
                :key="generation.id"
                :generation="generation"
            />
        </div>

        <nav
            v-if="generations.meta.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.prev"
                as-child
            >
                <Link :href="generations.links.prev ?? ''">Previous</Link>
            </Button>
            <span class="text-muted-foreground">
                Page {{ generations.meta.current_page }} of
                {{ generations.meta.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="!generations.links.next"
                as-child
            >
                <Link :href="generations.links.next ?? ''">Next</Link>
            </Button>
        </nav>
    </div>
</template>
