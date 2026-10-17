<?php

namespace App\Domain\Hr;

use App\Domain\Identity\UserAccountService;
use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\EmployeeDetail;
use App\Models\EmployeeDocument;
use App\Models\EmployeeLifecycleEvent;
use App\Models\Media;
use App\Models\User;
use App\Support\Audit;
use App\Support\Sequences;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Employee records and lifecycle. Identity and bank details are encrypted
 * and only visible with the sensitive-data permission. Documents are stored
 * privately and only downloadable through short-lived, audited links.
 * Offboarding never deletes history: the linked login is deactivated and
 * every past sale, count or approval keeps its author.
 */
final class EmployeeService
{
    private const SENSITIVE_FIELDS = ['cnic', 'cnic_expiry', 'bank_name', 'bank_account', 'date_of_birth', 'father_name', 'private_address', 'eobi_no', 'social_security_no'];

    private const LIFECYCLE = [
        'confirm' => ['from' => ['probation', 'onboarding'], 'to' => 'active', 'event' => 'confirmed'],
        'start_probation' => ['from' => ['onboarding'], 'to' => 'probation', 'event' => 'probation_started'],
        'suspend' => ['from' => ['active', 'probation'], 'to' => 'suspended', 'event' => 'suspended'],
        'reinstate' => ['from' => ['suspended'], 'to' => 'active', 'event' => 'reinstated'],
        'resign' => ['from' => ['active', 'probation', 'suspended', 'onboarding'], 'to' => 'resigned', 'event' => 'resigned'],
        'terminate' => ['from' => ['active', 'probation', 'suspended', 'onboarding'], 'to' => 'terminated', 'event' => 'terminated'],
    ];

    public function __construct(private readonly UserAccountService $accounts)
    {
    }

    /** @param array<string, mixed> $data */
    public function save(User $user, array $data, ?Contact $employee = null): Contact
    {
        if (! $user->hasPermission('hr.employees.manage')) {
            throw new BusinessRuleException('You are not allowed to edit employees.', 'not_permitted');
        }
        $sensitiveTouched = collect(self::SENSITIVE_FIELDS)->contains(fn ($field) => array_key_exists($field, $data));
        if ($sensitiveTouched && ! $user->hasPermission('hr.employees.sensitive')) {
            throw new BusinessRuleException('You are not allowed to see or change identity and bank details.', 'sensitive_not_permitted');
        }

        return DB::transaction(function () use ($user, $data, $employee) {
            $isNew = $employee === null;
            $employee ??= new Contact(['type' => Contact::TYPE_EMPLOYEE, 'created_by' => $user->id]);
            $employee->fill(collect($data)->only(['name', 'name_ur', 'phone', 'email', 'address', 'city', 'store_id', 'is_active'])->all());
            $employee->save();

            $detail = $employee->employeeDetail ?? new EmployeeDetail(['contact_id' => $employee->id, 'employee_no' => $data['employee_no'] ?? Sequences::next('employee'), 'status' => 'onboarding']);
            $detail->fill(collect($data)->only([
                'store_id', 'department_id', 'job_position_id', 'job_title', 'role_type', 'manager_contact_id', 'employment_type',
                'joining_date', 'probation_end_date', 'gender', 'marital_status', 'emergency_contact_name', 'emergency_contact_phone',
                'emergency_contact_relation', 'education_level', 'note', ...self::SENSITIVE_FIELDS,
            ])->all());
            if (array_key_exists('cnic', $data)) {
                $digits = preg_replace('/\D/', '', (string) $data['cnic']);
                if ($digits !== '' && strlen($digits) !== 13) {
                    throw new BusinessRuleException('A CNIC has 13 digits (e.g. 42101-1234567-1). Format is checked, not authenticity.', 'invalid_cnic', field: 'cnic');
                }
                $detail->cnic = $digits === '' ? null : substr($digits, 0, 5).'-'.substr($digits, 5, 7).'-'.substr($digits, 12);
                $detail->cnic_hash = $digits === '' ? null : hash('sha256', $employee->company_id.'|'.$digits);
            }
            try {
                $detail->save();
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                throw new BusinessRuleException('Another employee already has this CNIC or employee number.', 'duplicate_employee', field: 'cnic');
            }

            if ($isNew) {
                $this->recordEvent($user, $employee, 'onboarded', $detail->joining_date?->toDateString() ?? now()->toDateString(), 'Employee record created');
            }
            Audit::record($isNew ? 'hr.employee_created' : 'hr.employee_updated', ($isNew ? 'Created' : 'Updated')." employee {$employee->name}", $employee);

            return $employee->load('employeeDetail');
        });
    }

    /** Applies a lifecycle step (confirm, suspend, resign ...). Exits deactivate the linked login. */
    public function transition(User $user, Contact $employee, string $action, string $effectiveDate, ?string $note = null): Contact
    {
        if (! $user->hasPermission('hr.employees.lifecycle')) {
            throw new BusinessRuleException('You are not allowed to change employment status.', 'not_permitted');
        }
        $step = self::LIFECYCLE[$action] ?? throw new BusinessRuleException('Unknown lifecycle step.', 'invalid_step');
        $detail = $employee->employeeDetail ?? throw new BusinessRuleException('This employee has no HR record yet.', 'no_detail');
        if (! in_array($detail->status, $step['from'], true)) {
            throw new BusinessRuleException("An employee who is {$detail->status} cannot be {$step['event']}.", 'invalid_transition');
        }

        return DB::transaction(function () use ($user, $employee, $detail, $step, $effectiveDate, $note) {
            $detail->status = $step['to'];
            if ($step['to'] === 'active' && $step['event'] === 'confirmed') {
                $detail->confirmation_date = $effectiveDate;
            }
            if (in_array($step['to'], ['resigned', 'terminated'], true)) {
                $detail->exit_date = $effectiveDate;
                $detail->exit_reason = $note;
                $employee->update(['is_active' => false]);
                if ($employee->user) {
                    $this->accounts->deactivateForOffboarding($user, $employee->user, "Employee {$step['event']}");
                }
            }
            if ($step['to'] === 'suspended' && $employee->user) {
                $this->accounts->suspendForHr($user, $employee->user, 'Employee suspended');
            }
            $detail->save();
            $this->recordEvent($user, $employee, $step['event'], $effectiveDate, $note);

            return $employee->fresh('employeeDetail');
        });
    }

    public function transfer(User $user, Contact $employee, ?int $storeId, ?int $departmentId, ?int $positionId, string $effectiveDate, ?string $note): Contact
    {
        if (! $user->hasPermission('hr.employees.lifecycle')) {
            throw new BusinessRuleException('You are not allowed to transfer employees.', 'not_permitted');
        }
        $detail = $employee->employeeDetail;
        $from = $detail->only(['store_id', 'department_id', 'job_position_id']);
        $detail->fill(array_filter(['store_id' => $storeId, 'department_id' => $departmentId, 'job_position_id' => $positionId]))->save();
        $this->recordEvent($user, $employee, $positionId && $positionId !== $from['job_position_id'] ? 'promoted' : 'transferred', $effectiveDate, $note, ['from' => $from, 'to' => $detail->only(array_keys($from))]);

        return $employee->fresh('employeeDetail');
    }

    public function uploadDocument(User $user, Contact $employee, UploadedFile $file, string $type, string $title, ?string $expiresOn): EmployeeDocument
    {
        if (! $user->hasPermission('hr.employees.sensitive')) {
            throw new BusinessRuleException('You are not allowed to upload employee documents.', 'not_permitted');
        }
        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if (! in_array($file->getMimeType(), $allowed, true) || $file->getSize() > 8 * 1024 * 1024) {
            throw new BusinessRuleException('Upload a PDF or photo up to 8 MB.', 'invalid_file');
        }
        $path = $file->storeAs('companies/'.$employee->company_id.'/employees/'.$employee->id, Str::uuid().'.'.$file->extension(), 'local');
        $media = Media::create(['disk' => 'local', 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(), 'size' => $file->getSize(), 'purpose' => 'employee_document', 'uploaded_by' => $user->id]);
        $document = EmployeeDocument::create(['contact_id' => $employee->id, 'type' => $type, 'title' => $title, 'media_id' => $media->id, 'expires_on' => $expiresOn, 'uploaded_by' => $user->id]);
        Audit::record('hr.document_uploaded', "Uploaded {$type} document for {$employee->name}", $document);

        return $document;
    }

    /** A five-minute signed link; each issue is audited. */
    public function documentLink(User $user, EmployeeDocument $document): string
    {
        if (! $user->hasPermission('hr.employees.sensitive')) {
            throw new BusinessRuleException('You are not allowed to view employee documents.', 'not_permitted');
        }
        Audit::record('hr.document_viewed', "Opened {$document->type} document of contact #{$document->contact_id}", $document);

        return URL::temporarySignedRoute('hr.documents.download', now()->addMinutes(5), ['document' => $document->id, 'u' => $user->id]);
    }

    public function documentResponse(EmployeeDocument $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $media = $document->media;

        return Storage::disk($media->disk)->download($media->path, $media->original_name);
    }

    /** @param array<string, mixed> $details */
    private function recordEvent(User $user, Contact $employee, string $type, string $date, ?string $note, array $details = []): void
    {
        EmployeeLifecycleEvent::create(['contact_id' => $employee->id, 'type' => $type, 'effective_date' => $date, 'details' => $details ?: null, 'note' => $note, 'recorded_by' => $user->id]);
        Audit::record('hr.lifecycle', "Employee {$employee->name}: {$type}", $employee, new: ['date' => $date, 'note' => $note]);
    }
}
