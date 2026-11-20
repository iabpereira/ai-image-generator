<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatUsd } from '@/lib/money';
import { codePointLength, estimateCharge } from '@/lib/pricing';
import { store } from '@/routes/generations';
import type {
    GenerationQuality,
    GenerationSize,
    Pricing,
} from '@/types/generation';

const props = defineProps<{ pricing: Pricing; markupPercent: number }>();

const MAX_PROMPT_LENGTH = 4000;
const MAX_REFERENCE_IMAGES = 4;
// Bound rather than static: vue-tsc mis-parses "//" inside static component attributes.
const REFERENCE_IMAGE_PLACEHOLDER = 'https://example.com/reference.png';

const form = useForm({
    prompt: '',
    size: '1024x1024' as GenerationSize,
    quality: 'medium' as GenerationQuality,
    reference_images: [] as string[],
});

const promptLength = computed(() => codePointLength(form.prompt));

const filledReferenceImages = computed(() =>
    form.reference_images.map((url) => url.trim()).filter(Boolean),
);

const canAddReferenceImage = computed(
    () => form.reference_images.length < MAX_REFERENCE_IMAGES,
);

const estimate = computed(() =>
    estimateCharge(
        props.pricing,
        props.markupPercent,
        form.prompt,
        form.size,
        form.quality,
        filledReferenceImages.value.length,
    ),
);

const referenceImageErrors = computed(() =>
    (Object.entries(form.errors) as [string, string][])
        .filter(([field]) => field.startsWith('reference_images'))
        .map(([, message]) => message),
);

function addReferenceImage(): void {
    if (canAddReferenceImage.value) {
        form.reference_images.push('');
    }
}

function removeReferenceImage(position: number): void {
    form.reference_images.splice(position, 1);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        reference_images: filledReferenceImages.value,
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
        <form class="space-y-6" @submit.prevent="submit">
            <div class="space-y-2">
                <Label for="prompt">Prompt</Label>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    :maxlength="MAX_PROMPT_LENGTH"
                    rows="4"
                    required
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    placeholder="A lighthouse at dusk, oil painting"
                ></textarea>
                <div class="flex justify-between gap-4 text-xs">
                    <InputError :message="form.errors.prompt" />
                    <span class="ml-auto text-muted-foreground tabular-nums">
                        {{ promptLength }} / {{ MAX_PROMPT_LENGTH }}
                    </span>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <Label for="size">Size</Label>
                    <Select v-model="form.size">
                        <SelectTrigger id="size" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="1024x1024">
                                Square · 1024×1024
                            </SelectItem>
                            <SelectItem value="1536x1024">
                                Landscape · 1536×1024
                            </SelectItem>
                            <SelectItem value="1024x1536">
                                Portrait · 1024×1536
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.size" />
                </div>

                <div class="space-y-2">
                    <Label for="quality">Quality</Label>
                    <Select v-model="form.quality">
                        <SelectTrigger id="quality" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="low">Low</SelectItem>
                            <SelectItem value="medium">Medium</SelectItem>
                            <SelectItem value="high">High</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.quality" />
                </div>
            </div>

            <div class="space-y-2">
                <Label>
                    Reference images
                    <span class="font-normal text-muted-foreground">
                        (optional, up to {{ MAX_REFERENCE_IMAGES }})
                    </span>
                </Label>
                <div
                    v-for="(_, position) in form.reference_images"
                    :key="position"
                    class="flex gap-2"
                >
                    <Input
                        v-model="form.reference_images[position]"
                        type="url"
                        :placeholder="REFERENCE_IMAGE_PLACEHOLDER"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Remove reference image"
                        @click="removeReferenceImage(position)"
                    >
                        <X />
                    </Button>
                </div>
                <InputError
                    v-for="message in referenceImageErrors"
                    :key="message"
                    :message="message"
                />
                <Button
                    v-if="canAddReferenceImage"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="addReferenceImage"
                >
                    <Plus /> Add reference image
                </Button>
            </div>

            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                Generate
            </Button>
        </form>

        <Card class="h-fit lg:sticky lg:top-4">
            <CardHeader>
                <CardTitle>Cost</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
                <div>
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ formatUsd(estimate.typical) }}
                    </p>
                    <p class="text-muted-foreground">
                        Estimated · at most
                        <span class="tabular-nums">
                            {{ formatUsd(estimate.maximum) }}
                        </span>
                    </p>
                </div>
                <p class="text-muted-foreground">
                    The final price is Pixelforge's token cost plus
                    {{ markupPercent }}%. Part of that cost is only known once
                    the image is done.
                </p>
                <p class="font-medium">
                    You're only charged if the image succeeds.
                </p>
                <ul class="space-y-1 text-xs text-muted-foreground">
                    <li>Higher quality and larger sizes cost more.</li>
                    <li>
                        Each reference image adds
                        {{ pricing.tokens_per_reference_image }} tokens.
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
