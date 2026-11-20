<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import type { GenerationStatus } from '@/types/generation';

const props = defineProps<{
    status: GenerationStatus;
    /** Show the hint as a tooltip instead of inline text (e.g. in table rows). */
    compact?: boolean;
}>();

const presentation = computed(
    () =>
        ({
            pending: {
                label: 'Queued',
                hint: 'Waiting for a free slot at Pixelforge',
                variant: 'secondary',
                busy: true,
            },
            queued: {
                label: 'Queued',
                hint: "In Pixelforge's queue",
                variant: 'secondary',
                busy: true,
            },
            processing: {
                label: 'Processing',
                hint: 'Usually takes 5–30 seconds',
                variant: 'default',
                busy: true,
            },
            succeeded: {
                label: 'Succeeded',
                hint: null,
                variant: 'outline',
                busy: false,
            },
            failed: {
                label: 'Failed',
                hint: null,
                variant: 'destructive',
                busy: false,
            },
        }) as const,
);

const current = computed(() => presentation.value[props.status]);
</script>

<template>
    <div class="flex items-center gap-2">
        <Badge
            :title="compact ? (current.hint ?? undefined) : undefined"
            :variant="current.variant"
            :class="
                status === 'succeeded'
                    ? 'border-emerald-500/40 text-emerald-700 dark:text-emerald-400'
                    : ''
            "
        >
            <Spinner v-if="current.busy" class="size-3" />
            {{ current.label }}
        </Badge>
        <span
            v-if="current.hint && !compact"
            class="text-xs text-muted-foreground"
        >
            {{ current.hint }}
        </span>
    </div>
</template>
