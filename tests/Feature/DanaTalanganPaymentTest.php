<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganPayment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DanaTalanganPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_br43_empty_nominal_does_not_auto_mark_lunas(): void
    {
        [$record, $user] = $this->makeRecord(null);

        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '1.00', 'is_verified' => '1',
        ])->assertRedirect();

        $this->assertSame('sanggup', $record->fresh()->status);
    }

    public function test_br43_unverified_payment_does_not_auto_mark_lunas(): void
    {
        [$record, $user] = $this->makeRecord('100.00');

        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '100.00', 'is_verified' => '0',
        ])->assertRedirect();

        $this->assertSame('sanggup', $record->fresh()->status);
    }

    public function test_br43_verified_payment_auto_marks_lunas_and_calculates_overpayment(): void
    {
        [$record, $user] = $this->makeRecord('100.00');

        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '120.00', 'is_verified' => '1',
        ])->assertRedirect();

        $this->assertSame('lunas', $record->fresh()->status);
        $this->assertSame('120.00', $record->fresh()->payments->first()->amount);
    }

    public function test_br43_manual_lunas_stays_lunas_after_payment_delete(): void
    {
        [$record, $user] = $this->makeRecord('100.00', 'lunas');
        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '10.00', 'is_verified' => '1',
        ])->assertRedirect();
        $payment = $record->fresh()->payments->first();

        $this->actingAs($user)->delete(route('dana-talangan.payments.destroy', [$record, $payment]))->assertRedirect();

        $this->assertSame('lunas', $record->fresh()->status);
    }

    public function test_br43_auto_lunas_restores_manual_status_after_payment_delete(): void
    {
        [$record, $user] = $this->makeRecord('100.00', 'tidak_sanggup');
        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '100.00', 'is_verified' => '1',
        ])->assertRedirect();
        $payment = $record->fresh()->payments->first();
        $this->assertSame('lunas', $record->fresh()->status);

        $this->actingAs($user)->delete(route('dana-talangan.payments.destroy', [$record, $payment]))->assertRedirect();

        $this->assertSame('tidak_sanggup', $record->fresh()->status);
    }

    public function test_br45_edit_and_delete_payment_updates_status_and_audit(): void
    {
        [$record, $user] = $this->makeRecord('100.00');
        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '100.00', 'is_verified' => '1',
        ])->assertRedirect();
        $payment = $record->fresh()->payments->first();

        $this->actingAs($user)->put(route('dana-talangan.payments.update', [$record, $payment]), [
            'paid_at' => '2026-11-23', 'amount' => '40.00', 'is_verified' => '1',
        ])->assertRedirect();
        $this->assertSame('sanggup', $record->fresh()->status);

        $this->actingAs($user)->delete(route('dana-talangan.payments.destroy', [$record, $payment]))->assertRedirect();
        $this->assertSoftDeleted('dana_talangan_payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('activity_log', ['subject_type' => DanaTalanganPayment::class, 'subject_id' => $payment->id, 'event' => 'deleted']);
    }

    public function test_br47_other_branch_user_cannot_download_evidence(): void
    {
        Storage::fake('local');
        [$record, $user] = $this->makeRecord('100.00');
        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '10.00', 'evidence' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
        $payment = $record->fresh()->payments->first();
        $otherBranch = Branch::create(['name' => 'Cabang Lain', 'code' => 'OTH', 'is_active' => true]);
        $otherUser = User::factory()->create(['role_id' => Role::query()->where('slug', 'admin')->value('id'), 'branch_id' => $otherBranch->id, 'password_changed_at' => now()]);

        $this->actingAs($otherUser)->get(route('dana-talangan.payments.evidence', [$record, $payment]))->assertForbidden();
    }

    public function test_br46_replacing_evidence_deletes_old_file(): void
    {
        Storage::fake('local');
        [$record, $user] = $this->makeRecord('100.00');
        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '10.00', 'evidence' => UploadedFile::fake()->create('old.pdf', 10, 'application/pdf'),
        ])->assertRedirect();
        $payment = $record->fresh()->payments->first();
        $oldPath = $payment->evidence_path;
        Storage::disk('local')->assertExists($oldPath);

        $this->actingAs($user)->put(route('dana-talangan.payments.update', [$record, $payment]), [
            'paid_at' => '2026-11-23', 'amount' => '10.00', 'evidence' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        Storage::disk('local')->assertMissing($oldPath);
    }

    public function test_br44_unverified_payment_is_separate_and_private_evidence_is_scoped(): void
    {
        Storage::fake('local');
        [$record, $user] = $this->makeRecord('100.00');

        $this->actingAs($user)->post(route('dana-talangan.payments.store', $record), [
            'paid_at' => '2026-11-23', 'amount' => '50.00', 'is_verified' => '0', 'evidence' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ])->assertRedirect();

        $payment = $record->fresh()->payments->first();
        $this->assertSame('sanggup', $record->fresh()->status);
        $this->assertSame(50.0, (float) $record->fresh()->payments()->where('is_verified', false)->sum('amount'));
        $this->assertNotNull($payment->evidence_path);
        Storage::disk('local')->assertExists($payment->evidence_path);
    }

    private function makeRecord(?string $nominal, string $status = 'sanggup'): array
    {
        $branch = Branch::create(['name' => 'Cabang Payment', 'code' => 'PAY', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', 'admin')->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $record = DanaTalangan::create(['tanggal' => '2026-11-23', 'nama_konsumen' => 'Konsumen Payment', 'project_name' => 'Proyek', 'branch_id' => $branch->id, 'nominal' => $nominal, 'status' => $status, 'created_by' => $user->id]);

        return [$record, $user];
    }
}
