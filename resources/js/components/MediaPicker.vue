<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Image as ImageIcon, Link as LinkIcon, Upload, X } from 'lucide-vue-next';

interface MediaItem {
    id: number;
    name: string | null;
    url: string;
}

// eslint-disable-next-line @typescript-eslint/no-unused-vars
const props = defineProps<{
    modelValue: number | null; // selected media_id
    previewUrl: string | null; // currently selected image's URL, for display
}>();

const emit = defineEmits<{
    'update:modelValue': [value: number | null];
    'update:previewUrl': [value: string | null];
}>();

const isOpen = ref(false);
const activeTab = ref<'gallery' | 'upload' | 'url'>('gallery');
const gallery = ref<MediaItem[]>([]);
const isLoadingGallery = ref(false);
const isSubmitting = ref(false);
const error = ref<string | null>(null);

const urlInput = ref('');
const urlNameInput = ref('');

async function loadGallery() {
    isLoadingGallery.value = true;
    try {
        const response = await fetch(route('media.index'));
        gallery.value = await response.json();
    } finally {
        isLoadingGallery.value = false;
    }
}

function openPicker() {
    isOpen.value = true;
    activeTab.value = 'gallery';
    error.value = null;
    if (gallery.value.length === 0) {
        loadGallery();
    }
}

function closePicker() {
    isOpen.value = false;
}

function selectFromGallery(item: MediaItem) {
    emit('update:modelValue', item.id);
    emit('update:previewUrl', item.url);
    closePicker();
}

function clearSelection() {
    emit('update:modelValue', null);
    emit('update:previewUrl', null);
}

function csrfToken(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
}

async function onFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    isSubmitting.value = true;
    error.value = null;

    const formData = new FormData();
    formData.append('image', file);

    try {
        const response = await fetch(route('media.store'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json' },
            body: formData,
        });

        if (!response.ok) {
            error.value = 'Upload failed — try a different image.';
            return;
        }

        const created: MediaItem = await response.json();
        gallery.value.unshift(created);
        selectFromGallery(created);
    } finally {
        isSubmitting.value = false;
    }
}

async function submitUrl() {
    if (!urlInput.value.trim()) return;

    isSubmitting.value = true;
    error.value = null;

    try {
        const response = await fetch(route('media.store-url'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
            body: JSON.stringify({ url: urlInput.value.trim(), name: urlNameInput.value.trim() || null }),
        });

        if (!response.ok) {
            const data = await response.json().catch(() => null);
            error.value = data?.errors?.url?.[0] ?? 'Could not add that URL.';
            return;
        }

        const created: MediaItem = await response.json();
        gallery.value.unshift(created);
        selectFromGallery(created);
        urlInput.value = '';
        urlNameInput.value = '';
    } finally {
        isSubmitting.value = false;
    }
}

onMounted(() => {
    // Pre-warm the gallery so the first open feels instant.
    loadGallery();
});
</script>

<template>
    <div>
        <div class="flex items-center gap-3">
            <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50">
                <img v-if="previewUrl" :src="previewUrl" alt="" class="h-full w-full object-cover" />
                <ImageIcon v-else :size="20" class="text-slate-300" />
            </div>

            <div class="flex gap-2">
                <button
                    type="button"
                    @click="openPicker"
                    class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 font-sans text-xs font-medium text-ink-900 hover:bg-slate-50"
                >
                    {{ previewUrl ? 'Change image' : 'Choose image' }}
                </button>
                <button
                    v-if="previewUrl"
                    type="button"
                    @click="clearSelection"
                    class="font-sans text-xs text-danger-500 hover:underline"
                >
                    Remove
                </button>
            </div>
        </div>

        <!-- Picker modal -->
        <div v-if="isOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="flex max-h-[80vh] w-full max-w-2xl flex-col rounded-lg bg-white shadow-xl">
                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h3 class="font-display text-base font-semibold text-ink-900">Choose an image</h3>
                    <button type="button" @click="closePicker" class="text-slate-400 hover:text-ink-900">
                        <X :size="18" />
                    </button>
                </div>

                <div class="flex gap-1 border-b border-slate-200 px-5 pt-3">
                    <button
                        type="button"
                        @click="activeTab = 'gallery'"
                        class="rounded-t-lg px-3 py-2 font-sans text-sm font-medium"
                        :class="activeTab === 'gallery' ? 'border-b-2 border-brand-500 text-ink-900' : 'text-slate-400'"
                    >
                        Gallery
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'upload'"
                        class="flex items-center gap-1 rounded-t-lg px-3 py-2 font-sans text-sm font-medium"
                        :class="activeTab === 'upload' ? 'border-b-2 border-brand-500 text-ink-900' : 'text-slate-400'"
                    >
                        <Upload :size="14" /> Upload
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'url'"
                        class="flex items-center gap-1 rounded-t-lg px-3 py-2 font-sans text-sm font-medium"
                        :class="activeTab === 'url' ? 'border-b-2 border-brand-500 text-ink-900' : 'text-slate-400'"
                    >
                        <LinkIcon :size="14" /> Image URL
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto p-5">
                    <!-- Gallery tab -->
                    <div v-if="activeTab === 'gallery'">
                        <p v-if="isLoadingGallery" class="font-sans text-sm text-slate-400">Loading...</p>
                        <p v-else-if="gallery.length === 0" class="font-sans text-sm text-slate-400">
                            No images yet — upload one or add a URL to get started.
                        </p>
                        <div v-else class="grid grid-cols-4 gap-3">
                            <button
                                v-for="item in gallery"
                                :key="item.id"
                                type="button"
                                @click="selectFromGallery(item)"
                                class="aspect-square overflow-hidden rounded-lg border border-slate-200 hover:ring-2 hover:ring-brand-500"
                                :title="item.name ?? ''"
                            >
                                <img :src="item.url" :alt="item.name ?? ''" class="h-full w-full object-cover" />
                            </button>
                        </div>
                    </div>

                    <!-- Upload tab -->
                    <div v-else-if="activeTab === 'upload'" class="flex flex-col items-center gap-3 py-8">
                        <label
                            class="cursor-pointer rounded-lg border border-slate-200 bg-white px-4 py-2 font-sans text-sm font-medium text-ink-900 hover:bg-slate-50"
                        >
                            {{ isSubmitting ? 'Uploading...' : 'Choose a file to upload' }}
                            <input type="file" accept="image/*" class="hidden" :disabled="isSubmitting" @change="onFileChange" />
                        </label>
                        <p class="font-sans text-xs text-slate-400">JPG, PNG, up to 4MB. Added to the shared gallery for reuse.</p>
                    </div>

                    <!-- URL tab -->
                    <div v-else-if="activeTab === 'url'" class="flex flex-col gap-3 py-4">
                        <div>
                            <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Image URL</label>
                            <input
                                v-model="urlInput"
                                type="url"
                                placeholder="https://example.com/image.jpg"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-sans text-xs font-medium text-slate-500">Label (optional)</label>
                            <input
                                v-model="urlNameInput"
                                type="text"
                                placeholder="e.g. Coca-Cola 500ml"
                                class="w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500"
                            />
                        </div>
                        <button
                            type="button"
                            @click="submitUrl"
                            :disabled="isSubmitting || !urlInput.trim()"
                            class="w-fit rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400 disabled:opacity-50"
                        >
                            {{ isSubmitting ? 'Adding...' : 'Add image' }}
                        </button>
                    </div>

                    <p v-if="error" class="mt-3 font-sans text-xs text-danger-500">{{ error }}</p>
                </div>
            </div>
        </div>
    </div>
</template>