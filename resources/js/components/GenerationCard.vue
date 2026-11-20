<script setup lang="ts">
import { CircleAlert, ImageOff } from '@lucide/vue';
import { computed } from 'vue';
import GenerationStatusBadge from '@/components/GenerationStatusBadge.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatUsdString } from '@/lib/money';
import type { Generation } from '@/types/generation';

const props = defineProps<{ generation: Generation }>();

const aspectRatio = computed(() => {
    const [width, height] = props.generation.size.split('x');

    return `${width} / ${height}`;
});

const createdAt = computed(() =>
    new Date(props.generation.created_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);
</script>

<template>
    <article
        class="flex flex-col overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
    >
        <div
            class="relative w-full overflow-hidden bg-muted"
            :style="{ aspectRatio }"
        >
            <img
                v-if="generation.image_src"
                :src="generation.image_src"
                :alt="generation.prompt"
                loading="lazy"
                class="size-full object-cover"
            />
            <div
                v-else-if="generation.status === 'failed'"
                class="flex size-full flex-col items-center justify-center gap-2 p-6 text-center text-sm text-muted-foreground"
            >
                <CircleAlert class="size-6 text-destructive" />
                <p>{{ generation.error_message }}</p>
            </div>
            <div
                v-else-if="generation.image_expired"
                class="flex size-full flex-col items-center justify-center gap-2 text-sm text-muted-foreground"
            >
                <ImageOff class="size-6" />
                <p>Image link expired</p>
            </div>
            <Skeleton v-else class="size-full animate-pulse rounded-none" />
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <GenerationStatusBadge :status="generation.status" />

            <p class="line-clamp-2 text-sm" :title="generation.prompt">
                {{ generation.prompt }}
            </p>

            <p class="text-xs text-muted-foreground">
                {{ generation.size }} · {{ generation.quality }} quality
                <template v-if="generation.reference_images.length">
                    · {{ generation.reference_images.length }} reference image{{
                        generation.reference_images.length > 1 ? 's' : ''
                    }}
                </template>
            </p>

            <div class="mt-auto flex items-end justify-between gap-2 text-sm">
                <div v-if="generation.status === 'failed'">
                    <span class="font-medium">Not charged</span>
                </div>
                <div v-else-if="generation.charged_amount !== null">
                    <span class="text-muted-foreground">Charged</span>
                    <span
                        class="ml-1 font-semibold tabular-nums"
                        :title="`$${generation.charged_amount}`"
                    >
                        {{ formatUsdString(generation.charged_amount) }}
                    </span>
                    <span class="ml-1 text-xs text-muted-foreground">
                        (est.
                        {{ formatUsdString(generation.estimated_charge) }})
                    </span>
                </div>
                <div v-else>
                    <span class="text-muted-foreground">Estimated</span>
                    <span class="ml-1 tabular-nums">
                        {{ formatUsdString(generation.estimated_charge) }}
                    </span>
                    <span class="ml-1 text-xs text-muted-foreground">
                        · max {{ formatUsdString(generation.maximum_charge) }}
                    </span>
                </div>
                <time
                    class="shrink-0 text-xs text-muted-foreground"
                    :datetime="generation.created_at"
                >
                    {{ createdAt }}
                </time>
            </div>
        </div>
    </article>
</template>
