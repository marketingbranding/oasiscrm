<?php

namespace Tests\Unit;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerNup;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\ConsumerLeadLinkSubjectResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumerLeadLinkSubjectResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_sheet_subject_uses_id_konsumen_precedence_and_normalizes_identity_fields(): void
    {
        $branch = Branch::query()->create(['name' => 'Cabang Subject', 'code' => Str::upper(Str::random(8)), 'is_active' => true]);
        $project = LeadMaster::query()->create(['branch_id' => $branch->id, 'project_name' => 'Proyek Subject', 'is_active' => true]);
        $sales = User::factory()->create(['branch_id' => $branch->id, 'name' => 'Siti Sales']);
        $row = KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-subject',
            'sheet_name' => 'data_konsumen',
            'row_hash' => 'row-1',
            'row_data' => [
                '[SYS] ID Konsumen' => ' SYS-001 ',
                'ID Konsumen' => 'KONS-001',
                'ID Konsumen Awal' => 'AWAL-001',
                'Nama Konsumen' => '  Siti  Rahma ',
                'No HP' => '0812-3456-7890',
                'Proyek' => $project->project_name,
                'Sales' => $sales->name,
                'Tanggal' => '2026-11-20',
            ],
        ]);

        $subject = app(ConsumerLeadLinkSubjectResolver::class)->fromSheetRow($row);

        $this->assertSame('sheet', $subject->subjectType);
        $this->assertSame($branch->id, $subject->branchId);
        $this->assertSame($project->id, $subject->projectId);
        $this->assertSame('sys-001', $subject->legacyConsumerKey);
        $this->assertSame('Siti Rahma', $subject->name);
        $this->assertSame('6281234567890', $subject->phone);
        $this->assertSame($sales->id, $subject->salesUserId);
        $this->assertSame('2026-11-20', $subject->enteredAt?->toDateString());
        $this->assertTrue($subject->isDecidable);
        $this->assertSame($row->id, $subject->sheetRowId);
    }

    public function test_sheet_subject_without_consumer_id_is_read_only(): void
    {
        $row = KonsumenProgressSheetRow::create([
            'branch_id' => Branch::query()->create(['name' => 'Cabang Empty', 'code' => Str::upper(Str::random(8)), 'is_active' => true])->id,
            'sheet_id' => 'sheet-1',
            'sheet_name' => 'data_konsumen',
            'row_hash' => 'row-empty',
            'row_data' => ['nama_konsumen' => 'Tanpa ID'],
        ]);

        $subject = app(ConsumerLeadLinkSubjectResolver::class)->fromSheetRow($row);

        $this->assertFalse($subject->isDecidable);
        $this->assertSame('ID Konsumen kosong di sheet', $subject->note);
        $this->assertNull($subject->legacyConsumerKey);
    }

    public function test_application_and_nup_subjects_expose_shared_identity_contract(): void
    {
        $application = ConsumerApplication::factory()->create(['nama_konsumen' => 'Aplikasi Lama']);
        $application->customer()->update(['name' => 'Aplikasi Lama', 'phone' => '081234567890']);
        $nup = ConsumerNup::factory()->create(['metadata' => ['sales_user_id' => $application->sales_user_id]]);
        $nup->customer()->update(['name' => 'NUP Lama', 'phone' => '081298765432']);

        $resolver = app(ConsumerLeadLinkSubjectResolver::class);
        $applicationSubject = $resolver->fromApplication($application->fresh('customer'));
        $nupSubject = $resolver->fromNup($nup->fresh('customer'));

        $this->assertSame('application', $applicationSubject->subjectType);
        $this->assertSame($application->id, $applicationSubject->subjectId);
        $this->assertSame('6281234567890', $applicationSubject->phone);
        $this->assertSame('nup', $nupSubject->subjectType);
        $this->assertSame($nup->id, $nupSubject->subjectId);
        $this->assertSame('6281298765432', $nupSubject->phone);
        $this->assertSame($nup->registered_at?->toDateString(), $nupSubject->enteredAt?->toDateString());
        $this->assertTrue($nupSubject->isDecidable);
    }
}
