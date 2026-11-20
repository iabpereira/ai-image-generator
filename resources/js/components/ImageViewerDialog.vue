<script setup lang="ts">
import { Download } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatUsdString } from '@/lib/money';
import type { Generation } from '@/types/generation';

defineProps<{ generation: Generation | null; open: boolean }>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent v-if="generation" class="sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle
                    class="line-clamp-2 pr-6"
                    :title="generation.prompt"
                >
                    {{ generation.prompt }}
                </DialogTitle>
                <DialogDescription>
                    {{ generation.size }} · {{ generation.quality }} quality
                    <template v-if="generation.charged_amount !== null">
                        · {{ formatUsdString(generation.charged_amount) }}
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="flex justify-center">
                <img
                    v-if="generation.image_src"
                    :src="generation.image_src"
                    :alt="generation.prompt"
                    class="max-h-[75vh] w-auto max-w-full object-contain"
                />
            </div>

            <DialogFooter class="items-center">
                <Button v-if="generation.image_download_url" as-child>
                    <a :href="generation.image_download_url" download>
                        <Download /> Download
                    </a>
                </Button>
                <span v-else class="text-sm text-muted-foreground">
                    Download available in a moment.
                </span>
                <Button variant="outline" @click="emit('update:open', false)">
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
