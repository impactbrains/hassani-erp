<script setup lang="ts">
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { FileText } from 'lucide-vue-next';

interface MediaRef {
    id: number;
    url: string;
    name: string;
    original_name: string | null;
}

const props = defineProps<{
    contact: {
        id: number;
        employee_detail: (Record<string, any> & {
            visa_document?: MediaRef | null;
            id_card?: MediaRef | null;
            driving_license?: MediaRef | null;
        }) | null;
    };
}>();

const detail = props.contact.employee_detail ?? {};

const form = useForm({
    // Private Contact
    private_email: detail.private_email ?? '',
    private_phone: detail.private_phone ?? '',
    bank_account: detail.bank_account ?? '',

    // Personal Information
    legal_name: detail.legal_name ?? '',
    birthday: detail.birthday ?? '',
    place_of_birth: detail.place_of_birth ?? '',
    birth_country: detail.birth_country ?? '',
    gender: detail.gender ?? '',

    // Emergency Contact
    emergency_contact_name: detail.emergency_contact_name ?? '',
    emergency_contact_phone: detail.emergency_contact_phone ?? '',

    // Visa & Work Permit
    visa_no: detail.visa_no ?? '',
    work_permit_no: detail.work_permit_no ?? '',
    visa_document_media_id: detail.visa_document_media_id ?? null,

    // Citizenship
    nationality: detail.nationality ?? '',
    identification_no: detail.identification_no ?? '',
    ssn_no: detail.ssn_no ?? '',
    passport_no: detail.passport_no ?? '',

    // Location
    private_address: detail.private_address ?? '',
    home_work_distance: detail.home_work_distance ?? '',

    // Family
    marital_status: detail.marital_status ?? '',
    dependent_children: detail.dependent_children ?? null,

    // Education
    certificate_level: detail.certificate_level ?? '',
    field_of_study: detail.field_of_study ?? '',

    // Documents
    id_card_media_id: detail.id_card_media_id ?? null,
    driving_license_media_id: detail.driving_license_media_id ?? null,
});

function submit() {
    form.put(route('contacts.employee.personal.update', { contact: props.contact.id }), {
        preserveScroll: true,
    });
}

// --- Generic document upload helper, reused for visa doc / ID card /
// driving license — all three are Media records attached via a
// *_media_id field on employee_details, uploaded through the same
// shared media library endpoint used everywhere else in Hassani. ---
const uploadingField = ref<string | null>(null);

async function uploadDocument(event: Event, formField: 'visa_document_media_id' | 'id_card_media_id' | 'driving_license_media_id') {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    uploadingField.value = formField;
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

        if (formField === 'visa_document_media_id') form.visa_document_media_id = media.id;
        else if (formField === 'id_card_media_id') form.id_card_media_id = media.id;
        else if (formField === 'driving_license_media_id') form.driving_license_media_id = media.id;
        // Save immediately rather than waiting for the main "Save"
        // button — a document upload feels complete the moment the
        // file is attached, and it avoids losing the upload if the
        // user navigates away before hitting Save on unrelated fields.
        submit();
    } catch {
        alert('Could not upload the file. Please try again.');
    } finally {
        uploadingField.value = null;
        (event.target as HTMLInputElement).value = '';
    }
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <!-- Private Contact -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Private Contact</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Email</label>
                    <input v-model="form.private_email" type="email" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Phone</label>
                    <input v-model="form.private_phone" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Bank Account</label>
                    <input v-model="form.bank_account" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
            </div>
        </div>

        <!-- Personal Information -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Personal Information</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Legal Name</label>
                    <input v-model="form.legal_name" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Birthday</label>
                    <input v-model="form.birthday" type="date" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Place of Birth</label>
                    <input v-model="form.place_of_birth" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Country</label>
                    <input v-model="form.birth_country" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Gender</label>
                    <input v-model="form.gender" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
            </div>
        </div>

        <!-- Emergency Contact -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Emergency Contact</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Contact</label>
                    <input v-model="form.emergency_contact_name" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Phone</label>
                    <input v-model="form.emergency_contact_phone" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
            </div>
        </div>

        <!-- Visa & Work Permit -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Visa &amp; Work Permit</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Visa No</label>
                    <input v-model="form.visa_no" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Work Permit No</label>
                    <input v-model="form.work_permit_no" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div class="col-span-2">
                    <label class="font-sans text-sm font-medium text-slate-500">Document</label>
                    <div class="mt-1 flex items-center gap-3">
                        <a v-if="contact.employee_detail?.visa_document" :href="contact.employee_detail.visa_document.url" target="_blank" class="flex items-center gap-1.5 font-sans text-sm text-ink-900 hover:underline">
                            <FileText :size="14" class="text-slate-400" />
                            {{ contact.employee_detail.visa_document.original_name ?? contact.employee_detail.visa_document.name }}
                        </a>
                        <span v-else class="font-sans text-sm text-slate-400">No document uploaded</span>
                        <label class="cursor-pointer rounded-lg border border-slate-200 px-3 py-1.5 font-sans text-xs text-slate-500 hover:bg-slate-50">
                            {{ uploadingField === 'visa_document_media_id' ? 'Uploading...' : 'Upload' }}
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadDocument($event, 'visa_document_media_id')" :disabled="uploadingField !== null" />
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Citizenship -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Citizenship</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Nationality</label>
                    <input v-model="form.nationality" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Identification No</label>
                    <input v-model="form.identification_no" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">SSN No</label>
                    <input v-model="form.ssn_no" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Passport No</label>
                    <input v-model="form.passport_no" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
            </div>
        </div>

        <!-- Location -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Location</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Private Address</label>
                    <input v-model="form.private_address" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Home-Work Distance</label>
                    <input v-model="form.home_work_distance" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
            </div>
        </div>

        <!-- Family -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Family</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Marital Status</label>
                    <input v-model="form.marital_status" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Dependent Children</label>
                    <input v-model="form.dependent_children" type="number" min="0" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
            </div>
        </div>

        <!-- Education -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Education</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Certificate Level</label>
                    <input v-model="form.certificate_level" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Field of Study</label>
                    <input v-model="form.field_of_study" type="text" class="mt-1 w-full rounded-lg border-slate-200 font-sans text-sm focus:border-brand-500 focus:ring-brand-500" />
                </div>
            </div>
        </div>

        <!-- Documents -->
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <h2 class="font-display text-base font-semibold text-ink-900">Documents</h2>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">ID Card Copy</label>
                    <div class="mt-1 flex items-center gap-3">
                        <a v-if="contact.employee_detail?.id_card" :href="contact.employee_detail.id_card.url" target="_blank" class="flex items-center gap-1.5 font-sans text-sm text-ink-900 hover:underline">
                            <FileText :size="14" class="text-slate-400" />
                            {{ contact.employee_detail.id_card.original_name ?? contact.employee_detail.id_card.name }}
                        </a>
                        <span v-else class="font-sans text-sm text-slate-400">Not uploaded</span>
                        <label class="cursor-pointer rounded-lg border border-slate-200 px-3 py-1.5 font-sans text-xs text-slate-500 hover:bg-slate-50">
                            {{ uploadingField === 'id_card_media_id' ? 'Uploading...' : 'Upload' }}
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadDocument($event, 'id_card_media_id')" :disabled="uploadingField !== null" />
                        </label>
                    </div>
                </div>
                <div>
                    <label class="font-sans text-sm font-medium text-slate-500">Driving License</label>
                    <div class="mt-1 flex items-center gap-3">
                        <a v-if="contact.employee_detail?.driving_license" :href="contact.employee_detail.driving_license.url" target="_blank" class="flex items-center gap-1.5 font-sans text-sm text-ink-900 hover:underline">
                            <FileText :size="14" class="text-slate-400" />
                            {{ contact.employee_detail.driving_license.original_name ?? contact.employee_detail.driving_license.name }}
                        </a>
                        <span v-else class="font-sans text-sm text-slate-400">Not uploaded</span>
                        <label class="cursor-pointer rounded-lg border border-slate-200 px-3 py-1.5 font-sans text-xs text-slate-500 hover:bg-slate-50">
                            {{ uploadingField === 'driving_license_media_id' ? 'Uploading...' : 'Upload' }}
                            <input type="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden" @change="uploadDocument($event, 'driving_license_media_id')" :disabled="uploadingField !== null" />
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <button
            type="submit"
            :disabled="form.processing"
            class="rounded-lg bg-brand-500 px-4 py-2 font-sans text-sm font-semibold text-ink-900 transition-colors hover:bg-brand-400 disabled:opacity-50"
        >
            Save
        </button>
    </form>
</template>