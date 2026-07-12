<script setup lang="ts">
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { Plus, Trash2, FileText } from 'lucide-vue-next';

interface LookupOption {
    id: number;
    name: string;
}

interface ResumeItem {
    id: number;
    type: 'experience' | 'education';
    title: string;
    subtitle: string | null;
    start_date: string | null;
    end_date: string | null;
    is_current: boolean;
    description: string | null;
}

interface Skill {
    id: number;
    name: string;
    pivot?: { level: string | null };
}

const props = defineProps<{
    contact: {
        id: number;
        employee_detail: Record<string, any> | null;
        resume_items: ResumeItem[];
        skills: Skill[];
    };
    allSkills: LookupOption[];
}>();

const experienceItems = props.contact.resume_items.filter((i) => i.type === 'experience');
const educationItems = props.contact.resume_items.filter((i) => i.type === 'education');

// --- Resume file upload ---
// Reuses your existing Media library POST /media endpoint (field name
// `file`, per the recent MediaController update) rather than a
// dedicated upload route, so this file also shows up in the shared
// media picker like any other upload.
const uploadingResume = ref(false);
const resumeFileInput = ref<HTMLInputElement | null>(null);

async function handleResumeFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    uploadingResume.value = true;
    try {
        const formData = new FormData();
        formData.append('file', file);

        const response = await fetch(route('media.store'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' },
            body: formData,
        });

        if (!response.ok) throw new Error('Upload failed');
        const media = await response.json();

        router.put(
            route('contacts.employee.resume-file.update', { contact: props.contact.id }),
            { resume_file_media_id: media.id },
            { preserveScroll: true }
        );
    } catch {
        alert('Could not upload the file. Please try again.');
    } finally {
        uploadingResume.value = false;
        if (resumeFileInput.value) resumeFileInput.value.value = '';
    }
}

// --- Add experience / education entry ---
const addingType = ref<'experience' | 'education' | null>(null);

const itemForm = useForm({
    type: 'experience' as 'experience' | 'education',
    title: '',
    subtitle: '',
    start_date: '',
    end_date: '',
    is_current: false,
    description: '',
});

function startAdding(type: 'experience' | 'education') {
    addingType.value = type;
    itemForm.reset();
    itemForm.type = type;
}

function submitItem() {
    itemForm.post(route('contacts.employee.resume-items.store', { contact: props.contact.id }), {
        preserveScroll: true,
        onSuccess: () => {
            addingType.value = null;
        },
    });
}

function destroyItem(item: ResumeItem) {
    if (!confirm(`Remove "${item.title}" from the resume?`)) return;
    router.delete(
        route('contacts.employee.resume-items.destroy', { contact: props.contact.id, resumeItem: item.id }),
        { preserveScroll: true }
    );
}

// --- Skills ---
const pickingSkill = ref(false);
const skillPickerForm = useForm({ skill_id: null as number | null });
const newSkillForm = useForm({ name: '' });
const creatingNewSkill = ref(false);

const availableSkills = () => props.allSkills.filter((s) => !props.contact.skills.some((cs) => cs.id === s.id));

function attachSkill() {
    if (!skillPickerForm.skill_id) return;
    skillPickerForm.post(route('contacts.employee.skills.attach', { contact: props.contact.id }), {
        preserveScroll: true,
        onSuccess: () => {
            pickingSkill.value = false;
            skillPickerForm.reset();
        },
    });
}

function createSkill() {
    newSkillForm.post(route('contacts.employee.skills.store', { contact: props.contact.id }), {
        preserveScroll: true,
        onSuccess: () => {
            creatingNewSkill.value = false;
            newSkillForm.reset();
        },
    });
}

function removeSkill(skill: Skill) {
    router.delete(
        route('contacts.employee.skills.detach', { contact: props.contact.id, skill: skill.id }),
        { preserveScroll: true }
    );
}

function formatRange(item: ResumeItem): string {
    const start = item.start_date ? new Date(item.start_date).toLocaleDateString() : '?';
    const end = item.is_current ? 'Current' : (item.end_date ? new Date(item.end_date).toLocaleDateString() : '?');
    return `${start} - ${end}`;
}
</script>

<template>
    <div class="grid grid-cols-3 gap-6">
        <div class="col-span-2 space-y-6">
            <!-- Resume file -->
            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between">
                    <h2 class="font-display text-base font-semibold text-ink-900">Resume File</h2>
                    <label class="cursor-pointer rounded-lg border border-slate-200 px-3 py-1.5 font-sans text-sm text-slate-500 hover:bg-slate-50">
                        {{ uploadingResume ? 'Uploading...' : 'Upload' }}
                        <input ref="resumeFileInput" type="file" accept=".pdf,.doc,.docx" class="hidden" @change="handleResumeFileChange" :disabled="uploadingResume" />
                    </label>
                </div>

                <div v-if="contact.employee_detail?.resume_file" class="mt-3 flex items-center gap-2 font-sans text-sm text-ink-900">
                    <FileText :size="16" class="text-slate-400" />
                    <a :href="contact.employee_detail.resume_file.url" target="_blank" class="hover:underline">
                        {{ contact.employee_detail.resume_file.original_name ?? contact.employee_detail.resume_file.name }}
                    </a>
                </div>
                <p v-else class="mt-3 font-sans text-sm text-slate-400">No resume file uploaded yet.</p>
            </div>

            <!-- Experience -->
            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="font-display text-base font-semibold text-ink-900">Experience</h2>
                    <button
                        type="button"
                        @click="startAdding('experience')"
                        class="flex items-center gap-1 rounded-lg bg-slate-100 px-3 py-1.5 font-sans text-sm font-medium text-ink-800 hover:bg-slate-200"
                    >
                        <Plus :size="14" /> Add
                    </button>
                </div>

                <div v-for="item in experienceItems" :key="item.id" class="mt-3 rounded-lg bg-slate-50 p-3">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-sans text-xs font-medium text-slate-500">{{ formatRange(item) }}</p>
                            <p class="mt-0.5 font-sans text-sm font-semibold text-ink-900">{{ item.title }}</p>
                            <p v-if="item.subtitle" class="font-sans text-sm text-slate-500">{{ item.subtitle }}</p>
                        </div>
                        <button type="button" @click="destroyItem(item)" class="text-slate-400 hover:text-danger-500">
                            <Trash2 :size="16" />
                        </button>
                    </div>
                </div>
                <p v-if="experienceItems.length === 0" class="mt-3 font-sans text-sm text-slate-400">No experience added yet.</p>
            </div>

            <!-- Education -->
            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="font-display text-base font-semibold text-ink-900">Education</h2>
                    <button
                        type="button"
                        @click="startAdding('education')"
                        class="flex items-center gap-1 rounded-lg bg-slate-100 px-3 py-1.5 font-sans text-sm font-medium text-ink-800 hover:bg-slate-200"
                    >
                        <Plus :size="14" /> Add
                    </button>
                </div>

                <div v-for="item in educationItems" :key="item.id" class="mt-3 rounded-lg bg-slate-50 p-3">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-sans text-xs font-medium text-slate-500">{{ formatRange(item) }}</p>
                            <p class="mt-0.5 font-sans text-sm font-semibold text-ink-900">{{ item.title }}</p>
                            <p v-if="item.subtitle" class="font-sans text-sm text-slate-500">{{ item.subtitle }}</p>
                        </div>
                        <button type="button" @click="destroyItem(item)" class="text-slate-400 hover:text-danger-500">
                            <Trash2 :size="16" />
                        </button>
                    </div>
                </div>
                <p v-if="educationItems.length === 0" class="mt-3 font-sans text-sm text-slate-400">No education added yet.</p>
            </div>

            <!-- Add entry form (shared between experience/education) -->
            <form v-if="addingType" @submit.prevent="submitItem" class="rounded-lg border border-brand-500/30 bg-white p-5">
                <h3 class="font-display text-sm font-semibold text-ink-900">
                    Add {{ addingType === 'experience' ? 'Experience' : 'Education' }}
                </h3>

                <div class="mt-3 grid grid-cols-2 gap-3">
                    <input v-model="itemForm.title" type="text" placeholder="Title (e.g. company or degree)" class="col-span-2 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                    <input v-model="itemForm.subtitle" type="text" placeholder="Subtitle (e.g. role or field)" class="col-span-2 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                    <input v-model="itemForm.start_date" type="date" class="rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                    <input v-model="itemForm.end_date" type="date" :disabled="itemForm.is_current" class="rounded-lg border-slate-200 font-sans text-sm disabled:bg-slate-50 focus:border-brand-500 focus:ring-brand-500" />
                    <label class="col-span-2 flex items-center gap-2 font-sans text-sm text-slate-500">
                        <input type="checkbox" v-model="itemForm.is_current" class="rounded border-slate-300 text-brand-500 focus:ring-brand-500" />
                        Current
                    </label>
                    <textarea v-model="itemForm.description" rows="2" placeholder="Description" class="col-span-2 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>

                <div class="mt-3 flex gap-2">
                    <button type="submit" :disabled="itemForm.processing" class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400 disabled:opacity-50">
                        Save
                    </button>
                    <button type="button" @click="addingType = null" class="rounded-lg px-4 py-2 font-sans text-sm text-slate-500 hover:bg-slate-100">
                        Cancel
                    </button>
                </div>
            </form>
        </div>

        <!-- Skills & Certifications -->
        <div class="col-span-1">
            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <h2 class="font-display text-base font-semibold text-ink-900">Skills &amp; Certifications</h2>
                <p class="mt-2 font-sans text-sm italic text-slate-500">
                    You can add skills from our library to the employee profile. If skills are missing, they can be created by an HR officer.
                </p>

                <div v-if="contact.skills.length" class="mt-4 flex flex-wrap gap-2">
                    <span
                        v-for="skill in contact.skills"
                        :key="skill.id"
                        class="flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 font-sans text-xs font-medium text-ink-800"
                    >
                        {{ skill.name }}
                        <button type="button" @click="removeSkill(skill)" class="text-slate-400 hover:text-danger-500">
                            <Trash2 :size="12" />
                        </button>
                    </span>
                </div>

                <div class="mt-4 space-y-2">
                    <div v-if="pickingSkill" class="flex gap-2">
                        <select v-model="skillPickerForm.skill_id" class="flex-1 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option :value="null">Choose a skill...</option>
                            <option v-for="s in availableSkills()" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <button type="button" @click="attachSkill" class="rounded-lg bg-brand-500 px-3 py-1.5 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400">
                            Add
                        </button>
                    </div>

                    <div v-else-if="creatingNewSkill" class="flex gap-2">
                        <input v-model="newSkillForm.name" type="text" placeholder="New skill name" class="flex-1 rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <button type="button" @click="createSkill" class="rounded-lg bg-brand-500 px-3 py-1.5 font-sans text-sm font-semibold text-ink-900 hover:bg-brand-400">
                            Create
                        </button>
                    </div>

                    <button
                        v-else
                        type="button"
                        @click="pickingSkill = true"
                        class="w-full rounded-lg bg-slate-100 px-3 py-2 font-sans text-sm font-medium text-ink-800 hover:bg-slate-200"
                    >
                        Pick a skill from the list
                    </button>

                    <button
                        v-if="!creatingNewSkill && !pickingSkill"
                        type="button"
                        @click="creatingNewSkill = true"
                        class="w-full font-sans text-xs text-slate-400 hover:text-ink-800"
                    >
                        Skill missing? Create a new one
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>