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
    ) {}

    public function append(
        SalesLead $lead,
        string $sheetName,
        array $fields,
        string $operationUuid,
    ): SalesLeadSpreadsheetWriteResult {
        if (! Str::isUuid($operationUuid)) {
            throw SalesLeadSpreadsheetContractException::invalidOperationId();
        }

        $contract = $this->contracts->resolve($lead, $sheetName);
        try {
            $headers = $this->googleSheets->ensureTrailingMetadataColumns(
                $contract->spreadsheetId,
                $sheetName,
                $contract->sheetId,
                $contract->headers,
                SalesLeadSpreadsheetContract::META_HEADERS,
            );
            $headerMap = array_flip($headers);

            $existingRow = $this->googleSheets->findRowByHeaderValue(
                $contract->spreadsheetId,
                $sheetName,
                $headers,
                'oasis_sync_id',
                $operationUuid,
            );
        } catch (SalesLeadSpreadsheetContractException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw SalesLeadSpreadsheetContractException::writeFailed();
        }
        if ($existingRow !== null) {
            $this->copyTemplateWhenRequired($contract, $existingRow);

            return new SalesLeadSpreadsheetWriteResult($contract->spreadsheetId, $sheetName, $existingRow, $operationUuid);
        }

        $formulaHeaders = array_flip($contract->formulaOwnedHeaders);
        $metadataHeaders = array_flip(SalesLeadSpreadsheetContract::META_HEADERS);
        $values = array_fill(0, count($headers), null);
        foreach ($fields as $header => $value) {
            if (! isset($headerMap[$header]) || isset($formulaHeaders[$header]) || isset($metadataHeaders[$header])) {
                continue;
            }
            $values[$headerMap[$header]] = $value;
        }
        $values[$headerMap['oasis_sync_id']] = $operationUuid;

        try {
            $append = $this->googleSheets->appendRows(
                $contract->spreadsheetId,
                $this->googleSheets->quoteSheetName($sheetName).'!A:'.$this->columnLetter(count($headers)),
                [$values],
            );
            $this->copyTemplateWhenRequired($contract, $append->rowNumber);

            return new SalesLeadSpreadsheetWriteResult(
                $contract->spreadsheetId,
                $sheetName,
                $append->rowNumber,
                $operationUuid,
            );
        } catch (Throwable) {
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

                    return new SalesLeadSpreadsheetWriteResult($contract->spreadsheetId, $sheetName, $rowNumber, $operationUuid);
                }
            } catch (Throwable) {
                throw SalesLeadSpreadsheetContractException::writeFailed();
            }

            throw SalesLeadSpreadsheetContractException::writeFailed();
        }
    }

    public function updateBySyncId(
        SalesLead $lead,
        string $sheetName,
        string $syncId,
        array $fields,
    ): SalesLeadSpreadsheetWriteResult {
        if (! Str::isUuid($syncId)) {
            throw SalesLeadSpreadsheetContractException::invalidOperationId();
        }

        $contract = $this->contracts->resolve($lead, $sheetName);
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
                    'values' => [[$value]],
                ];
            }
            $this->googleSheets->batchUpdateRanges($contract->spreadsheetId, $ranges);

            return new SalesLeadSpreadsheetWriteResult($contract->spreadsheetId, $sheetName, $rowNumber, $syncId);
        } catch (SalesLeadSpreadsheetContractException $exception) {
            throw $exception;
        } catch (Throwable) {
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
        } catch (Throwable) {
            throw SalesLeadSpreadsheetContractException::writeFailed();
        }
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
