<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { ImageIcon, Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import ImageViewerDialog from '@/components/ImageViewerDialog.vue';
import { Button } from '@/components/ui/button';
import { decimalToNanos, formatUsdString } from '@/lib/money';
import { dashboard } from '@/routes';
import { index as creditsIndex } from '@/routes/credits';
import { create } from '@/routes/generations';
import type { Generation, Paginated } from '@/types/generation';

const props = defineProps<{ generations: Paginated<Generation> }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const selected = ref<Generation | null>(null);
const open = ref(false);

function viewGeneration(generation: Generation): void {
    selected.value = generation;
    open.value = true;
}

const page = usePage();

const hasNoCredits = computed(
    () => decimalToNanos(page.props.auth.user.credit_balance) === 0,
);

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

function formatDate(isoDate: string): string {
    return new Date(isoDate).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function referenceImagesLabel(count: number): string {
    return `${count} reference image${count > 1 ? 's' : ''}`;
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Generations</h1>
                <p class="text-sm text-muted-foreground">
                    Every request with its live status and what it cost you, in
                    USD. Failed generations are never charged.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"><Sparkles /> New image</Link>
            </Button>
        </div>

        <div
            v-if="hasNoCredits"
            class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm"
        >
            <p>You have no credits yet.</p>
            <Button as-child size="sm">
                <Link :href="creditsIndex()">Buy credits</Link>
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

        <div
            v-else
            class="overflow-x-auto rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 font-medium">Image</th>
                        <th class="px-4 py-3 font-medium">Prompt</th>
                        <th class="px-4 py-3 font-medium">Options</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                        <th class="px-4 py-3 text-right font-medium">Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="generation in generations.data"
                        :key="generation.id"
                        class="border-t border-sidebar-border/70 align-top dark:border-sidebar-border"
                    >
                        <td class="px-4 py-3">
                            <button
                                v-if="generation.image_src"
                                type="button"
                                class="cursor-zoom-in"
                                aria-label="View image"
                                @click="viewGeneration(generation)"
                            >
                                <img
                                    :src="generation.image_src"
                                    :alt="generation.prompt"
                                    loading="lazy"
                                    class="size-12 rounded-md object-cover"
                                />
                            </button>
                            <div
                                v-else
                                class="flex size-12 items-center justify-center rounded-md bg-muted text-muted-foreground"
                            >
                                <ImageIcon class="size-5" />
                            </div>
                        </td>
                        <td class="max-w-sm px-4 py-3">
                            <p
                                class="line-clamp-2 [overflow-wrap:anywhere]"
                                :title="generation.prompt"
                            >
                                {{ generation.prompt }}
                            </p>
                            <p
                                v-if="generation.error_message"
                                class="mt-1 text-xs text-destructive"
                            >
                                {{ generation.error_message }}
                            </p>
                        </td>
                        <td
                            class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                        >
                            <p>
                                {{ generation.size }} · {{ generation.quality }}
                            </p>
                            <p v-if="generation.reference_images.length">
                                {{
                                    referenceImagesLabel(
                                        generation.reference_images.length,
                                    )
                                }}
                            </p>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            <GenerationStatusBadge
                                :status="generation.status"
                                compact
                            />
                        </td>
                        <td
                            class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                        >
                            <time :datetime="generation.created_at">
                                {{ formatDate(generation.created_at) }}
                            </time>
                        </td>
                        <td
                            class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                        >
                            <span
                                v-if="generation.status === 'failed'"
                                class="text-muted-foreground"
                            >
                                Not charged
                            </span>
                            <span
                                v-else-if="generation.charged_amount !== null"
                                class="font-medium"
                                :title="`$${generation.charged_amount}`"
                            >
                                {{ formatUsdString(generation.charged_amount) }}
                            </span>
                            <span v-else class="text-muted-foreground">
                                Pending
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
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
                <Link :href="generations.links.prev" :only="['generations']">
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
                <Link :href="generations.links.next" :only="['generations']">
                    Next
                </Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>Next</Button>
        </nav>

        <ImageViewerDialog v-model:open="open" :generation="selected" />
    </div>
</template>
