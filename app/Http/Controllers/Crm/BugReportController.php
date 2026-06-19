<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BugReportController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $user = Auth::user();
        $webhookUrl = 'https://discord.com/api/webhooks/1511976290264027218/-ZJW0LJ80MmSGRiOdmSjI5Yp6fKfaXeqI1Ci16ZzPTqLLPLdgyYn3PTmuFcHjrnjlalE';

        $payload = [
            'embeds' => [[
                'title' => "\xF0\x9F\x90\x9B Bug Report - Oasis CRM",
                'color' => 15110973,
                'fields' => [
                    ['name' => 'User', 'value' => $user->name . ' (' . $user->email . ')', 'inline' => true],
                    ['name' => 'Page', 'value' => $request->header('Referer', 'Unknown'), 'inline' => true],
                    ['name' => 'Waktu', 'value' => now()->format('d-m-Y H:i:s'), 'inline' => true],
                    ['name' => 'Pesan', 'value' => $request->input('message')],
                ],
                'footer' => ['text' => 'IP: ' . $request->ip()],
                'timestamp' => now()->toIso8601String(),
            ]],
        ];

        try {
            $response = Http::timeout(10)->post($webhookUrl, $payload);
            if ($response->successful()) {
                return response()->json(['ok' => true]);
            }
            Log::error('Discord webhook responded: ' . $response->status() . ' ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Discord webhook exception: ' . $e->getMessage());
        }

        try {
            $altPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $altPayload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                return response()->json(['ok' => true]);
            }
            Log::error('Curl fallback failed: HTTP ' . $httpCode . ' Error: ' . $curlError . ' Response: ' . ($result ?: 'empty'));
        } catch (\Exception $e) {
            Log::error('Curl fallback exception: ' . $e->getMessage());
        }

        return response()->json(['ok' => false, 'error' => 'Gagal mengirim laporan. Cek log untuk detail.'], 500);
    }
}
