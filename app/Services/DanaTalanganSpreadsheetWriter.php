<?php

namespace App\Services;

use App\Exceptions\DanaTalanganSpreadsheetContractException;
use App\ValueObjects\DanaTalanganSpreadsheetWriteResult;
use Illuminate\Support\Str;
use Throwable;

class DanaTalanganSpreadsheetWriter
{
    public function __construct(
        private readonly GoogleSheetsApiService $googleSheets,
        private readonly DanaTalanganSpreadsheetContract $contracts,
        private readonly SyncLockService $locks,
    ) {}

    public function append(array $fields, string $syncId, bool $manageLock = true): DanaTalanganSpreadsheetWriteResult
    {
        $run = fn () => $this->appendUnlocked($fields, $syncId);

        return $manageLock ? $this->locks->runOrThrow($this->lockKey(), $run) : $run();
    }

    public function update(string $syncId, array $fields, bool $manageLock = true): DanaTalanganSpreadsheetWriteResult
    {
        $run = fn () => $this->updateUnlocked($syncId, $fields);

        return $manageLock ? $this->locks->runOrThrow($this->lockKey(), $run) : $run();
    }

    public function setSyncId(int $rowNumber, string $syncId, bool $manageLock = true): DanaTalanganSpreadsheetWriteResult
    {
        $run = fn () => $this->setSyncIdUnlocked($rowNumber, $syncId);

        return $manageLock ? $this->locks->runOrThrow($this->lockKey(), $run) : $run();
    }

    public function tombstone(string $syncId, ?int $actorId, bool $manageLock = true): DanaTalanganSpreadsheetWriteResult
    {
        $run = fn () => $this->tombstoneUnlocked($syncId, $actorId);

        return $manageLock ? $this->locks->runOrThrow($this->lockKey(), $run) : $run();
    }

    private function appendUnlocked(array $fields, string $syncId): DanaTalanganSpreadsheetWriteResult
    {
        $this->assertUuid($syncId);
        $contract = $this->contracts->resolve();
        $rows = $this->contracts->rows($contract);
        $matches = $this->matches($rows, $syncId);
        if (count($matches) > 1) {
            throw new DanaTalanganSpreadsheetContractException('UUID remote Dana Talangan duplikat.');
        }
        if (count($matches) === 1) {
            if (filled($matches[0]['oasis_deleted_at'])) {
                throw new DanaTalanganSpreadsheetContractException('Baris UUID Dana Talangan sudah dihapus.');
            }
            $fields[0] = $matches[0]['_row_number'] - 1;

            return $this->verify($contract->spreadsheetId, $syncId, $matches[0]['_row_number'], $fields);
        }

        $rowNumber = collect($rows)->first(fn (array $row) => blank($row['Nama Konsumen']))['_row_number'] ?? null;
        try {
            if ($rowNumber !== null) {
                $fields[0] = $rowNumber - 1;
                $this->googleSheets->updateRange($contract->spreadsheetId, "'Talangan'!A{$rowNumber}:Q{$rowNumber}", [$this->rowValues($fields, $syncId)]);
            } else {
                $append = $this->googleSheets->appendRows($contract->spreadsheetId, "'Talangan'!A:Q", [$this->rowValues($fields, $syncId)]);
                $rowNumber = $append->rowNumber;
                $fields[0] = $rowNumber - 1;
                $this->googleSheets->updateRange($contract->spreadsheetId, "'Talangan'!A{$rowNumber}", [[$rowNumber - 1]]);
            }
        } catch (Throwable $exception) {
            $matches = $this->matches($this->contracts->rows($contract), $syncId);
            if (count($matches) !== 1) {
                report($exception);
                throw new DanaTalanganSpreadsheetContractException('Penulisan Dana Talangan ke spreadsheet gagal.');
            }
            $rowNumber = $matches[0]['_row_number'];
        }
        $fields[0] = $rowNumber - 1;

        return $this->verify($contract->spreadsheetId, $syncId, $rowNumber, $fields);
    }

    private function updateUnlocked(string $syncId, array $fields): DanaTalanganSpreadsheetWriteResult
    {
        $this->assertUuid($syncId);
        $contract = $this->contracts->resolve();
        $matches = $this->matches($this->contracts->rows($contract), $syncId);
        if (count($matches) !== 1 || filled($matches[0]['oasis_deleted_at'])) {
            throw new DanaTalanganSpreadsheetContractException('Baris UUID Dana Talangan tidak tunggal atau sudah dihapus.');
        }
        $rowNumber = $matches[0]['_row_number'];
        $fields[0] = $rowNumber - 1;
        $this->googleSheets->updateRange(
            $contract->spreadsheetId,
            "'Talangan'!A{$rowNumber}:N{$rowNumber}",
            [array_slice($this->rowValues($fields, $syncId), 0, 14)],
        );

        return $this->verify($contract->spreadsheetId, $syncId, $rowNumber, $fields);
    }

    private function setSyncIdUnlocked(int $rowNumber, string $syncId): DanaTalanganSpreadsheetWriteResult
    {
        $this->assertUuid($syncId);
        $contract = $this->contracts->resolve();
        $rows = $this->contracts->rows($contract);
        if (collect($rows)->contains(fn (array $row) => $row['oasis_sync_id'] === $syncId && $row['_row_number'] !== $rowNumber)) {
            throw new DanaTalanganSpreadsheetContractException('UUID remote Dana Talangan sudah dipakai.');
        }
        $row = collect($rows)->firstWhere('_row_number', $rowNumber);
        if ($row === null || filled($row['oasis_sync_id']) || filled($row['oasis_deleted_at'])) {
            throw new DanaTalanganSpreadsheetContractException('Baris remote Dana Talangan tidak dapat diklaim.');
        }
        $this->googleSheets->updateRange($contract->spreadsheetId, "'Talangan'!O{$rowNumber}", [[$syncId]]);
        $verified = collect($this->contracts->rows($contract))->firstWhere('_row_number', $rowNumber);
        if (($verified['oasis_sync_id'] ?? '') !== $syncId) {
            throw new DanaTalanganSpreadsheetContractException('UUID remote Dana Talangan tidak dapat diverifikasi.');
        }

        return $this->result($contract->spreadsheetId, $verified);
    }

    private function tombstoneUnlocked(string $syncId, ?int $actorId): DanaTalanganSpreadsheetWriteResult
    {
        $this->assertUuid($syncId);
        $contract = $this->contracts->resolve();
        $matches = $this->matches($this->contracts->rows($contract), $syncId);
        if (count($matches) !== 1 || filled($matches[0]['oasis_deleted_at'])) {
            throw new DanaTalanganSpreadsheetContractException('Baris remote Dana Talangan tidak aman untuk ditandai hapus.');
        }
        $rowNumber = $matches[0]['_row_number'];
        $this->googleSheets->updateRange($contract->spreadsheetId, "'Talangan'!O{$rowNumber}:Q{$rowNumber}", [[
            $syncId,
            now()->toIso8601String(),
            (string) ($actorId ?? 'system'),
        ]]);
        $verified = collect($this->contracts->rows($contract))->firstWhere('_row_number', $rowNumber);
        if (($verified['oasis_sync_id'] ?? '') !== $syncId || blank($verified['oasis_deleted_at'] ?? null)) {
            throw new DanaTalanganSpreadsheetContractException('Tombstone remote Dana Talangan tidak dapat diverifikasi.');
        }

        return $this->result($contract->spreadsheetId, $verified);
    }

    private function verify(string $spreadsheetId, string $syncId, int $rowNumber, array $fields): DanaTalanganSpreadsheetWriteResult
    {
        $contract = $this->contracts->resolve();
        $matches = $this->matches($this->contracts->rows($contract), $syncId);
        if (count($matches) !== 1 || $matches[0]['_row_number'] !== $rowNumber) {
            throw new DanaTalanganSpreadsheetContractException('Hasil tulis Dana Talangan tidak dapat diverifikasi.');
        }
        $expected = array_combine(DanaTalanganSpreadsheetContract::BUSINESS_HEADERS, array_pad(array_values($fields), 14, ''));
        $expected = $this->contracts->normalizeBusinessPayload($expected);
        $actual = $this->contracts->normalizeBusinessPayload($matches[0]);
        if ($expected !== $actual) {
            throw new DanaTalanganSpreadsheetContractException('Payload Dana Talangan remote tidak sesuai hasil tulis.');
        }

        return $this->result($spreadsheetId, $matches[0]);
    }

    private function rowValues(array $fields, string $syncId): array
    {
        $values = [];
        foreach (array_pad(array_values($fields), 14, '') as $value) {
            $values[] = $this->contracts->valueForWrite($value);
        }

        return [...array_slice($values, 0, 14), $syncId, '', ''];
    }

    private function matches(array $rows, string $syncId): array
    {
        return array_values(array_filter($rows, fn (array $row) => $row['oasis_sync_id'] === $syncId));
    }

    private function result(string $spreadsheetId, array $row): DanaTalanganSpreadsheetWriteResult
    {
        return new DanaTalanganSpreadsheetWriteResult($spreadsheetId, DanaTalanganSpreadsheetContract::SHEET, $row['_row_number'], $row['oasis_sync_id'], $row);
    }

    private function assertUuid(string $syncId): void
    {
        if (! Str::isUuid($syncId)) {
            throw new DanaTalanganSpreadsheetContractException('UUID Dana Talangan tidak valid.');
        }
    }

    private function lockKey(): string
    {
        return 'dana-talangan-bridge:spreadsheet:'.config('services.google_sheets.dana_talangan_spreadsheet_id').':Talangan';
    }
}
