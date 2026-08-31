<?php

namespace App\Services;

use App\Exceptions\SalesLeadSpreadsheetContractException;
use App\Models\SalesLead;
use App\ValueObjects\ResolvedSalesLeadSpreadsheetContract;
use App\ValueObjects\SalesLeadSpreadsheetWriteResult;
use Illuminate\Support\Str;
use Throwable;

class SalesLeadSpreadsheetWriter
{
    public function __construct(
        private GoogleSheetsApiService $googleSheets,
        private SalesLeadSpreadsheetContract $contracts,
        private ?SyncLockService $locks = null,
    ) {}

    public function append(
        SalesLead $lead,
        string $sheetName,
        array $fields,
        string $operationUuid,
        bool $manageLock = true,
    ): SalesLeadSpreadsheetWriteResult {
        $run = fn (): SalesLeadSpreadsheetWriteResult => $this->appendUnlocked($lead, $sheetName, $fields, $operationUuid);

        return $manageLock && $this->locks !== null ? $this->locks->runOrThrow($this->lockKey($lead, $sheetName), $run) : $run();
    }

    private function appendUnlocked(
        SalesLead $lead,
        string $sheetName,
        array $fields,
        string $operationUuid,
    ): SalesLeadSpreadsheetWriteResult {
        if (! Str::isUuid($operationUuid)) {
            throw SalesLeadSpreadsheetContractException::invalidOperationId();
        }

        $contract = $this->contracts->resolve($lead, $sheetName);
        $this->contracts->assertStrictValues($contract, $fields);
        try {
            $headers = $this->googleSheets->ensureTrailingMetadataColumns(
                $contract->spreadsheetId,
                $sheetName,
                $contract->sheetId,
                $contract->headers,
                SalesLeadSpreadsheetContract::META_HEADERS,
            );
            $headerMap = array_flip($headers);
            foreach ($contract->headerMap as $canonicalHeader => $index) {
                $headerMap[$canonicalHeader] = $index;
            }

            $existingRow = $this->googleSheets->findRowByHeaderValue(
                $contract->spreadsheetId,
                $sheetName,
                $headers,
                'oasis_sync_id',
                $operationUuid,
            );
        } catch (SalesLeadSpreadsheetContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw SalesLeadSpreadsheetContractException::writeFailed();
        }
        if ($existingRow !== null) {
            $existing = $this->result($contract, $headers, $existingRow, $operationUuid);
            if (filled($existing->rowValues['oasis_deleted_at'] ?? null)) {
                throw SalesLeadSpreadsheetContractException::writeFailed();
            }
            $this->copyTemplateWhenRequired($contract, $existingRow);

            return $existing;
        }

        $formulaHeaders = array_flip($contract->formulaOwnedHeaders);
        $metadataHeaders = array_flip(SalesLeadSpreadsheetContract::META_HEADERS);
        $values = array_fill(0, count($headers), null);
        foreach ($fields as $header => $value) {
            if (! isset($headerMap[$header]) || isset($formulaHeaders[$header]) || isset($metadataHeaders[$header])) {
                continue;
            }
            $values[$headerMap[$header]] = $this->contracts->valueForWrite($contract, $header, $value);
        }
        $values[$headerMap['oasis_sync_id']] = $operationUuid;

        try {
            $append = $this->googleSheets->appendRows(
                $contract->spreadsheetId,
                $this->googleSheets->quoteSheetName($sheetName).'!A:'.$this->columnLetter(count($headers)),
                [$values],
            );
            $this->copyTemplateWhenRequired($contract, $append->rowNumber);

            return $this->result($contract, $headers, $append->rowNumber, $operationUuid);
        } catch (Throwable $writeException) {
            try {
                $rowNumber = $this->googleSheets->findRowByHeaderValue(
                    $contract->spreadsheetId,
                    $sheetName,
                    $headers,
                    'oasis_sync_id',
                    $operationUuid,
                );
                if ($rowNumber !== null) {
                    $this->copyTemplateWhenRequired($contract, $rowNumber);

                    return $this->result($contract, $headers, $rowNumber, $operationUuid);
                }
            } catch (Throwable $reconciliationException) {
                report($writeException);
                report($reconciliationException);
                throw SalesLeadSpreadsheetContractException::writeFailed();
            }

            report($writeException);
            throw SalesLeadSpreadsheetContractException::writeFailed();
        }
    }

    public function setSyncIdByRow(SalesLead $lead, int $rowNumber, string $syncId, bool $manageLock = true): void
    {
        $run = function () use ($lead, $rowNumber, $syncId): void {
            if (! Str::isUuid($syncId)) {
                throw SalesLeadSpreadsheetContractException::invalidOperationId();
            }
            $contract = $this->contracts->resolve($lead, 'lead');
            $headers = $this->googleSheets->ensureTrailingMetadataColumns($contract->spreadsheetId, 'lead', $contract->sheetId, $contract->headers, SalesLeadSpreadsheetContract::META_HEADERS);
            $this->googleSheets->writeRowMetadata($contract->spreadsheetId, 'lead', $headers, $rowNumber, $syncId, null, null);
        };
        if ($manageLock && $this->locks !== null) {
            $this->locks->runOrThrow('sales-lead-bridge:branch:'.$lead->branch_id.':lead', $run);

            return;
        }
        $run();
    }

    public function tombstoneBySyncId(SalesLead $lead, string $syncId, ?int $deletedBy = null, bool $manageLock = true): SalesLeadSpreadsheetWriteResult
    {
        if (! Str::isUuid($syncId)) {
            throw SalesLeadSpreadsheetContractException::invalidOperationId();
        }

        $run = fn (): SalesLeadSpreadsheetWriteResult => $this->tombstone($lead, $syncId, $deletedBy);

        return $manageLock && $this->locks !== null ? $this->locks->runOrThrow('sales-lead-bridge:branch:'.$lead->branch_id.':lead', $run) : $run();
    }

    private function tombstone(SalesLead $lead, string $syncId, ?int $deletedBy): SalesLeadSpreadsheetWriteResult
    {
        try {
            $contract = $this->contracts->resolve($lead, 'lead');
            $headers = $this->googleSheets->ensureTrailingMetadataColumns($contract->spreadsheetId, 'lead', $contract->sheetId, $contract->headers, SalesLeadSpreadsheetContract::META_HEADERS);
            $rowNumber = $this->googleSheets->findRowByHeaderValue($contract->spreadsheetId, 'lead', $headers, 'oasis_sync_id', $syncId);
            if ($rowNumber === null) {
                throw SalesLeadSpreadsheetContractException::writeFailed();
            }
            $this->googleSheets->writeRowMetadata($contract->spreadsheetId, 'lead', $headers, $rowNumber, $syncId, now()->toIso8601String(), $deletedBy);

            return new SalesLeadSpreadsheetWriteResult($contract->spreadsheetId, 'lead', $rowNumber, $syncId);
        } catch (SalesLeadSpreadsheetContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw SalesLeadSpreadsheetContractException::writeFailed();
        }
    }

    public function updateBySyncId(
        SalesLead $lead,
        string $sheetName,
        string $syncId,
        array $fields,
        bool $manageLock = true,
    ): SalesLeadSpreadsheetWriteResult {
        $run = fn (): SalesLeadSpreadsheetWriteResult => $this->updateBySyncIdUnlocked($lead, $sheetName, $syncId, $fields);

        return $manageLock && $this->locks !== null ? $this->locks->runOrThrow($this->lockKey($lead, $sheetName), $run) : $run();
    }

    private function updateBySyncIdUnlocked(
        SalesLead $lead,
        string $sheetName,
        string $syncId,
        array $fields,
    ): SalesLeadSpreadsheetWriteResult {
        if (! Str::isUuid($syncId)) {
            throw SalesLeadSpreadsheetContractException::invalidOperationId();
        }

        $contract = $this->contracts->resolve($lead, $sheetName);
        $this->contracts->assertStrictValues($contract, $fields);
        try {
            $headers = $this->googleSheets->ensureTrailingMetadataColumns(
                $contract->spreadsheetId,
                $sheetName,
                $contract->sheetId,
                $contract->headers,
                SalesLeadSpreadsheetContract::META_HEADERS,
            );
            $rowNumber = $this->googleSheets->findRowByHeaderValue(
                $contract->spreadsheetId,
                $sheetName,
                $headers,
                'oasis_sync_id',
                $syncId,
            );
            if ($rowNumber === null) {
                throw SalesLeadSpreadsheetContractException::writeFailed();
            }

            $headerMap = array_flip($headers);
            foreach ($contract->headerMap as $canonicalHeader => $index) {
                $headerMap[$canonicalHeader] = $index;
            }
            $formulaHeaders = array_flip($contract->formulaOwnedHeaders);
            $metadataHeaders = array_flip(SalesLeadSpreadsheetContract::META_HEADERS);
            $ranges = [];
            foreach ($fields as $header => $value) {
                if (! isset($headerMap[$header]) || isset($formulaHeaders[$header]) || isset($metadataHeaders[$header])) {
                    continue;
                }
                $column = $this->columnLetter($headerMap[$header] + 1);
                $ranges[] = [
                    'range' => $this->googleSheets->quoteSheetName($sheetName)."!{$column}{$rowNumber}",
                    'values' => [[$this->contracts->valueForWrite($contract, $header, $value)]],
                ];
            }
            $this->googleSheets->batchUpdateRanges($contract->spreadsheetId, $ranges);

            return new SalesLeadSpreadsheetWriteResult($contract->spreadsheetId, $sheetName, $rowNumber, $syncId);
        } catch (SalesLeadSpreadsheetContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            throw SalesLeadSpreadsheetContractException::writeFailed();
        }
    }

    private function copyTemplateWhenRequired(ResolvedSalesLeadSpreadsheetContract $contract, int $rowNumber): void
    {
        if ($contract->formulaOwnedHeaders === []) {
            return;
        }

        try {
            $this->googleSheets->copyRowFormat($contract->spreadsheetId, $contract->sheetId, $contract->templateRowNumber, $rowNumber);
            $this->googleSheets->copyRowFormulas($contract->spreadsheetId, $contract->sheetId, $contract->templateRowNumber, $rowNumber);
        } catch (Throwable $exception) {
            report($exception);
            throw SalesLeadSpreadsheetContractException::writeFailed();
        }
    }

    private function result(ResolvedSalesLeadSpreadsheetContract $contract, array $headers, int $rowNumber, string $syncId): SalesLeadSpreadsheetWriteResult
    {
        $rowValues = [];
        if ($contract->definition->sheetName === 'lead') {
            $range = $this->googleSheets->quoteSheetName('lead')."!{$rowNumber}:{$rowNumber}";
            $cells = $this->googleSheets->batchGetRaw(
                $contract->spreadsheetId,
                [$range],
                'FORMATTED_VALUE',
            )['lead'][0] ?? [];
            foreach ($headers as $index => $header) {
                $rowValues[$header] = trim((string) ($cells[$index] ?? ''));
            }
        }

        return new SalesLeadSpreadsheetWriteResult(
            $contract->spreadsheetId,
            $contract->definition->sheetName,
            $rowNumber,
            $syncId,
            $rowValues,
        );
    }

    private function lockKey(SalesLead $lead, string $sheetName): string
    {
        return $sheetName === 'lead'
            ? 'sales-lead-bridge:branch:'.$lead->branch_id.':lead'
            : 'sales-lead-spreadsheet:branch:'.$lead->branch_id.':sheet:'.$sheetName;
    }

    private function columnLetter(int $column): string
    {
        $letter = '';
        while ($column > 0) {
            $column--;
            $letter = chr(65 + ($column % 26)).$letter;
            $column = intdiv($column, 26);
        }

        return $letter;
    }
}
