<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationParityService;
use App\Support\OrganizationParityFilter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('organization:parity
    {--user= : ID pengguna viewer}
    {--branch= : ID cabang viewer}
    {--role= : Slug peran utama}
     {--scope= : Jenis scope organisasi}
     {--module= : Modul yang diuji}
     {--only-differences : Hanya tampilkan perbedaan}
     {--severity= : Tingkat keparahan mismatch}
     {--classification= : Klasifikasi mismatch}
     {--access-direction= : Arah akses mismatch}
     {--only-critical : Hanya mismatch security-critical}
     {--only-unknown : Hanya mismatch unknown}
     {--only-expansion : Hanya access expansion}
     {--only-loss : Hanya access loss}
     {--only-conflicts : Hanya konflik data/hierarki}
     {--json : Keluarkan JSON machine-readable}')]
#[Description('Membandingkan scope organisasi legacy dan graph tanpa mengubah data.')]
class OrganizationParity extends Command
{
    public function handle(OrganizationParityService $parity): int
    {
        try {
            $role = $this->nullableString('role');
            if ($role !== null && ! Role::query()->where('slug', $role)->exists()) {
                throw new InvalidArgumentException('Role filter tidak ditemukan.');
            }

            $filter = new OrganizationParityFilter(
                userId: $this->positiveInteger('user'),
                branchId: $this->positiveInteger('branch'),
                role: $role,
                scope: $this->nullableString('scope'),
                module: $this->nullableString('module'),
                onlyDifferences: (bool) $this->option('only-differences'),
                severity: $this->nullableString('severity'),
                classification: $this->nullableString('classification'),
                accessDirection: $this->nullableString('access-direction'),
                onlyCritical: (bool) $this->option('only-critical'),
                onlyUnknown: (bool) $this->option('only-unknown'),
                onlyExpansion: (bool) $this->option('only-expansion'),
                onlyLoss: (bool) $this->option('only-loss'),
                onlyConflicts: (bool) $this->option('only-conflicts'),
            );
            if ($filter->userId !== null && ! User::query()->whereKey($filter->userId)->exists()) {
                throw new InvalidArgumentException('User filter tidak ditemukan.');
            }
            $report = $parity->report($filter);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Metrik', 'Nilai'],
            collect($report['summary'])->map(fn (mixed $value, string $key): array => [$key, $value])->values()->all(),
        );
        $this->info('Diagnostic parity selesai tanpa perubahan data.');

        return self::SUCCESS;
    }

    private function nullableString(string $name): ?string
    {
        $value = $this->option($name);

        return filled($value) ? (string) $value : null;
    }

    private function positiveInteger(string $name): ?int
    {
        $value = $this->option($name);
        if (! filled($value)) {
            return null;
        }

        if (! is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new InvalidArgumentException("Filter {$name} harus berupa bilangan bulat positif.");
        }

        return (int) $value;
    }
}
