<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreDanaTalanganPaymentRequest;
use App\Models\DanaTalangan;
use App\Models\DanaTalanganPayment;
use App\Services\DanaTalanganPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DanaTalanganPaymentController extends Controller
{
    public function store(StoreDanaTalanganPaymentRequest $request, DanaTalangan $danaTalangan, DanaTalanganPaymentService $service): RedirectResponse
    {
        $service->save($danaTalangan, $request->validated(), $request->user(), $request->file('evidence'));

        return back()->with('success', 'Pembayaran Dana Talangan berhasil dicatat.');
    }

    public function update(StoreDanaTalanganPaymentRequest $request, DanaTalangan $danaTalangan, DanaTalanganPayment $payment, DanaTalanganPaymentService $service): RedirectResponse
    {
        abort_unless((int) $payment->dana_talangan_id === (int) $danaTalangan->id, 404);
        $service->save($danaTalangan, $request->validated(), $request->user(), $request->file('evidence'), $payment);

        return back()->with('success', 'Pembayaran Dana Talangan berhasil diubah.');
    }

    public function destroy(Request $request, DanaTalangan $danaTalangan, DanaTalanganPayment $payment, DanaTalanganPaymentService $service): RedirectResponse
    {
        abort_unless((int) $payment->dana_talangan_id === (int) $danaTalangan->id, 404);
        $service->delete($payment, $request->user());

        return back()->with('success', 'Pembayaran Dana Talangan berhasil dihapus.');
    }

    public function evidence(DanaTalangan $danaTalangan, DanaTalanganPayment $payment): StreamedResponse
    {
        abort_unless((int) $payment->dana_talangan_id === (int) $danaTalangan->id, 404);
        abort_unless(request()->user()->can('view', $danaTalangan), 403);
        abort_unless($payment->evidence_path !== null && Storage::disk('local')->exists($payment->evidence_path), 404);

        return Storage::disk('local')->response($payment->evidence_path, 'bukti-pembayaran-'.$payment->id, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
