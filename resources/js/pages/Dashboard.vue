<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ImagePlus } from '@lucide/vue';
import { computed, watch } from 'vue';
import GenerationCard from '@/components/GenerationCard.vue';
import GenerationForm from '@/components/GenerationForm.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import type { Generation, Paginated, Pricing } from '@/types/generation';

const props = defineProps<{
    generations: Paginated<Generation>;
    pricing: Pricing | null;
    markupPercent: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
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
    <Head title="Dashboard" />

    <div class="flex flex-col gap-8 p-4">
        <section
            class="rounded-xl border border-sidebar-border/70 p-4 sm:p-6 dark:border-sidebar-border"
        >
            <div class="mb-4">
                <h1 class="text-xl font-semibold">Create an image</h1>
                <p class="text-sm text-muted-foreground">
                    Describe it, pick a size and quality, and see the price
                    before you generate.
                </p>
            </div>

            <GenerationForm
                :pricing="pricing"
                :markup-percent="markupPercent"
            />
        </section>

        <section class="flex flex-col gap-4">
            <div>
                <h2 class="text-lg font-semibold">Your images</h2>
                <p class="text-sm text-muted-foreground">
                    Status updates live. Costs are in USD, and failed
                    generations are never charged.
                </p>
            </div>

            <div
                v-if="generations.data.length === 0"
                class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-12 text-center"
            >
                <ImagePlus class="size-8 text-muted-foreground" />
                <p class="text-muted-foreground">
                    No images yet. Your first one will show up here.
                </p>
            </div>

            <div
                v-else
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
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
                    v-if="generations.links.prev"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="generations.links.prev"
                        preserve-scroll
                        :only="['generations']"
                    >
                        Previous
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Previous
                </Button>
                <span class="text-muted-foreground">
                    Page {{ generations.meta.current_page }} of
                    {{ generations.meta.last_page }}
                </span>
                <Button
                    v-if="generations.links.next"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="generations.links.next"
                        preserve-scroll
                        :only="['generations']"
                    >
                        Next
                    </Link>
                </Button>
                <Button v-else variant="outline" size="sm" disabled>
                    Next
                </Button>
            </nav>
        </section>
    </div>
</template>
