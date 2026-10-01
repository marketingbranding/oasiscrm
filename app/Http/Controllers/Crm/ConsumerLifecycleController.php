<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumerLifecycleRequest;
use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Models\Kavling;
use App\Services\ConsumerApplicationLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class ConsumerLifecycleController extends Controller
{
    public function __construct(private readonly ConsumerApplicationLifecycleService $lifecycle) {}

    public function mundur(ConsumerLifecycleRequest $request, ConsumerApplication $consumerApplication): JsonResponse|RedirectResponse
    {
        $application = $this->lifecycle->mundur($consumerApplication, $request->user());

        return $this->respond($request, 'Konsumen berhasil dimundurkan.', $application);
    }

    public function pindahKavling(ConsumerLifecycleRequest $request, ConsumerApplication $consumerApplication): JsonResponse|RedirectResponse
    {
        $target = Kavling::query()->findOrFail($request->integer('target_kavling_id'));
        $application = $this->lifecycle->pindahKavling($consumerApplication, $target, $request->user());

        return $this->respond($request, 'Kavling konsumen berhasil dipindahkan.', $application);
    }

    public function gantiBank(ConsumerLifecycleRequest $request, ConsumerApplication $consumerApplication): JsonResponse|RedirectResponse
    {
        $attempt = $this->lifecycle->gantiBank($consumerApplication, $request->validated(), $request->user());

        return $this->respond($request, 'Pengajuan bank baru berhasil dibuat.', $attempt);
    }

    public function gantiKonsumen(ConsumerLifecycleRequest $request, ConsumerApplication $consumerApplication): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $replacement = $this->lifecycle->gantiKonsumen(
            $consumerApplication,
            Customer::query()->findOrFail($data['replacement_customer_id']),
            $request->user(),
            isset($data['target_kavling_id']) ? Kavling::query()->findOrFail($data['target_kavling_id']) : null,
        );

        return $this->respond($request, 'Konsumen berhasil diganti.', $replacement);
    }

    public function ready100(ConsumerLifecycleRequest $request, ConsumerApplication $consumerApplication): JsonResponse|RedirectResponse
    {
        $event = $this->lifecycle->recordReady100($consumerApplication, $request->validated(), $request->user());

        return $this->respond($request, 'Ready100 berhasil dicatat.', $event);
    }

    public function akad(ConsumerLifecycleRequest $request, ConsumerApplication $consumerApplication): JsonResponse|RedirectResponse
    {
        $record = $this->lifecycle->recordAkad($consumerApplication, $request->validated(), $request->user());

        return $this->respond($request, 'Akad berhasil dicatat.', $record);
    }

    public function bast(ConsumerLifecycleRequest $request, ConsumerApplication $consumerApplication): JsonResponse|RedirectResponse
    {
        $record = $this->lifecycle->recordBast($consumerApplication, $request->validated(), $request->user());

        return $this->respond($request, 'BAST berhasil dicatat.', $record);
    }

    private function respond(ConsumerLifecycleRequest $request, string $message, object $result): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'data' => ['id' => $result->getKey()],
            ]);
        }

        return back()->with('success', $message);
    }
}
