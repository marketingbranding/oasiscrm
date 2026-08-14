<?php

namespace App\Http\Controllers\Crm;

use App\Exceptions\SalesLeadSpreadsheetContractException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\ConvertSalesLeadConsumerRequest;
use App\Http\Requests\Crm\ConvertSalesLeadFreelanceRequest;
use App\Http\Requests\Crm\RecordSalesLeadSiteVisitRequest;
use App\Http\Requests\Crm\RejectSalesLeadSlikRequest;
use App\Http\Requests\Crm\SubmitSalesLeadSlikRequest;
use App\Http\Requests\Crm\UpdateSalesLeadLifecycleStatusRequest;
use App\Http\Requests\Crm\UpdateSalesLeadSiteVisitRequest;
use App\Models\SalesLead;
use App\Models\SalesLeadSiteVisit;
use App\Models\SalesLeadSlikAttempt;
use App\Services\SalesLeadLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesLeadLifecycleController extends Controller
{
    public function __construct(private readonly SalesLeadLifecycleService $lifecycle) {}

    public function updateStatus(UpdateSalesLeadLifecycleStatusRequest $request, SalesLead $salesLead): RedirectResponse|Response
    {
        $operationUuid = $request->validated('operation_uuid') ?? (string) Str::uuid();
        $lead = $this->run(fn () => $this->lifecycle->setManualStatus(
            $salesLead, $request->validated('status'), $request->user(), operationUuid: $operationUuid,
        ));

        return $this->respond($request, 'Status lead berhasil diperbarui.', ['status' => $lead->current_status->value, 'operation_uuid' => $operationUuid]);
    }

    public function siteVisit(RecordSalesLeadSiteVisitRequest $request, SalesLead $salesLead): RedirectResponse|Response
    {
        $visit = $this->run(fn () => $this->lifecycle->recordSiteVisit($salesLead, $request->validated(), $request->user()));

        return $this->respond($request, 'Cek lokasi berhasil dicatat.', ['site_visit_id' => $visit->id, 'completed' => $visit->is_completed, 'operation_uuid' => $visit->operation_uuid]);
    }

    public function updateSiteVisit(UpdateSalesLeadSiteVisitRequest $request, SalesLead $salesLead, SalesLeadSiteVisit $siteVisit): RedirectResponse|Response
    {
        abort_unless((int) $siteVisit->sales_lead_id === (int) $salesLead->id, 404);
        $visit = $this->run(fn () => $this->lifecycle->updateSiteVisit($salesLead, $siteVisit, $request->validated(), $request->user()));

        return $this->respond($request, 'Cek lokasi berhasil diperbarui.', ['site_visit_id' => $visit->id, 'completed' => $visit->is_completed, 'operation_uuid' => $visit->operation_uuid]);
    }

    public function consumer(ConvertSalesLeadConsumerRequest $request, SalesLead $salesLead): RedirectResponse|Response
    {
        $link = $this->run(fn () => $this->lifecycle->convertToConsumer($salesLead, $request->validated(), $request->user()));

        return $this->respond($request, 'Lead berhasil dikonversi menjadi konsumen.', ['consumer_link_id' => $link->id, 'sheet_type' => $link->sheet_type, 'operation_uuid' => $link->operation_uuid]);
    }

    public function slik(SubmitSalesLeadSlikRequest $request, SalesLead $salesLead): RedirectResponse|Response
    {
        $attempt = $this->run(fn () => $this->lifecycle->submitToSlik($salesLead, $request->validated(), $request->user()));

        return $this->respond($request, 'Pengajuan SLIK berhasil dikirim.', ['slik_attempt_id' => $attempt->id, 'operation_uuid' => $attempt->operation_uuid]);
    }

    public function rejectSlik(RejectSalesLeadSlikRequest $request, SalesLead $salesLead, SalesLeadSlikAttempt $slikAttempt): RedirectResponse|Response
    {
        $data = $request->validated();
        $data['operation_uuid'] ??= (string) Str::uuid();
        $attempt = $this->run(fn () => $this->lifecycle->markSlikRejected($salesLead, $slikAttempt, $data, $request->user()));

        return $this->respond($request, 'Hasil penolakan SLIK berhasil dicatat.', ['slik_attempt_id' => $attempt->id, 'status' => $attempt->status, 'operation_uuid' => $data['operation_uuid']]);
    }

    public function freelance(ConvertSalesLeadFreelanceRequest $request, SalesLead $salesLead): RedirectResponse|Response
    {
        $link = $this->run(fn () => $this->lifecycle->convertToFreelance($salesLead, $request->validated(), $request->user()));

        return $this->respond($request, 'Lead berhasil dikonversi menjadi freelance.', ['freelance_link_id' => $link->id, 'operation_uuid' => $link->operation_uuid]);
    }

    private function respond(Request $request, string $message, array $data): RedirectResponse|Response
    {
        if ($request->expectsJson()) {
            return response(['ok' => true, 'message' => $message] + $data);
        }

        return redirect()->route('sales-pocketbook.index')->with('success', $message);
    }

    private function run(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (SalesLeadSpreadsheetContractException $exception) {
            throw ValidationException::withMessages([
                'spreadsheet' => $exception->getMessage().' Data lokal tidak diubah.',
            ]);
        }
    }
}
