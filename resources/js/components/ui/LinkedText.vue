<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * A translated sentence with one phrase (such as "Settings → Lists") turned into
 * a link to that exact screen. `phrases` lists the phrase in each language,
 * because the path is translated too. Without a link (`href` empty, e.g. the
 * person cannot open that screen) or without a match it is plain text.
 */
const props = defineProps<{ text: string; phrases: string[]; href?: string | null }>();
const parts = computed(() => {
    if (!props.href) return null;
    for (const phrase of [...props.phrases].sort((a, b) => b.length - a.length)) {
        const i = props.text.indexOf(phrase);
        if (i >= 0) return { before: props.text.slice(0, i), link: phrase, after: props.text.slice(i + phrase.length) };
    }
    return null;
});
</script>

<template>
    <template v-if="parts">{{ parts.before }}<Link :href="href!" class="link">{{ parts.link }}</Link>{{ parts.after }}</template>
    <template v-else>{{ text }}</template>
</template>
