<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\MonerooService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(protected MonerooService $moneroo)
    {
    }

    /**
     * POST /api/webhooks/moneroo
     */
    public function moneroo(Request $request)
    {
        $rawPayload = $request->getContent();
        $signature = $request->header('X-Moneroo-Signature');

        // Security: verify HMAC-SHA256 signature before trusting anything.
        if (! $this->moneroo->verifyWebhookSignature($rawPayload, $signature)) {
            Log::warning('Invalid Moneroo webhook signature.');

            return response()->json(['message' => 'Invalid signature'], 403);
        }

        $event = $request->input('event');
        $data = $request->input('data', []);
        $transactionId = $data['id'] ?? $data['transaction_id'] ?? null;

        if (! $transactionId) {
            return response()->json(['message' => 'Missing transaction id'], 200);
        }

        switch ($event) {
            case 'payment.success':
                $this->handleSuccess($transactionId);
                break;

            case 'payment.failed':
                $this->updateStatus($transactionId, 'failed', 'failed');
                break;

            case 'payment.cancelled':
                $this->updateStatus($transactionId, 'cancelled', 'cancelled');
                break;

            case 'payment.initiated':
                // Nothing to do — order/payment are already 'pending'.
                break;

            default:
                Log::info('Unhandled Moneroo webhook event', ['event' => $event]);
        }

        // Always return HTTP 200 once the signature is valid and the event
        // has been processed (or safely ignored).
        return response()->json(['message' => 'ok'], 200);
    }

    protected function handleSuccess(string $transactionId): void
    {
        DB::transaction(function () use ($transactionId) {
            $payment = Payment::where('transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                Log::warning('Moneroo webhook: unknown transaction', ['transaction_id' => $transactionId]);
                return;
            }

            // Idempotence: if already marked success, do nothing more.
            if ($payment->status === 'success') {
                return;
            }

            // Never trust the webhook body alone — re-verify with Moneroo directly.
            $verified = $this->moneroo->verifyPayment($transactionId);
            $verifiedStatus = $verified['status'] ?? null;
            $verifiedAmount = isset($verified['amount']) ? (float) $verified['amount'] : null;

            if ($verifiedStatus !== 'success') {
                Log::warning('Moneroo webhook success event but verification did not confirm success', [
                    'transaction_id' => $transactionId,
                    'verified_status' => $verifiedStatus,
                ]);
                return;
            }

            $order = $payment->order()->lockForUpdate()->first();

            // Verify the amount matches the order to prevent tampering.
            if ($verifiedAmount !== null && round($verifiedAmount) !== round((float) $order->amount)) {
                Log::error('Moneroo webhook amount mismatch', [
                    'transaction_id' => $transactionId,
                    'expected' => $order->amount,
                    'received' => $verifiedAmount,
                ]);
                return;
            }

            $payment->update([
                'status' => 'success',
                'raw_response' => $verified,
            ]);

            $order->update(['status' => 'paid']);
        });
    }

    protected function updateStatus(string $transactionId, string $paymentStatus, string $orderStatus): void
    {
        DB::transaction(function () use ($transactionId, $paymentStatus, $orderStatus) {
            $payment = Payment::where('transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();

            if (! $payment || $payment->status === 'success') {
                // Unknown transaction, or already confirmed paid: ignore.
                return;
            }

            $payment->update(['status' => $paymentStatus]);
            $payment->order()->update(['status' => $orderStatus]);
        });
    }
}
