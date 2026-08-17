<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePaymentRequest;
use App\Models\Course;
use App\Models\Order;
use App\Models\Payment;
use App\Services\MonerooService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(protected MonerooService $moneroo)
    {
    }

    /**
     * POST /api/payments/create
     *
     * Body: { "course_id": 1 }  <-- price is NEVER accepted from the client.
     */
    public function create(CreatePaymentRequest $request)
    {
        $user = $request->user();

        // 2. verify the course exists (route-model style lookup via validated id)
        $course = Course::findOrFail($request->validated()['course_id']);

        // 3. verify the course is active
        if (! $course->isActive()) {
            return response()->json([
                'message' => 'Cette formation n\'est pas disponible à l\'achat.',
            ], 422);
        }

        // 4. price comes from the DB, never from the request
        $amount = $course->price;
        $currency = $course->currency;

        try {
            return DB::transaction(function () use ($user, $course, $amount, $currency) {
                // 5. create a pending Order
                $order = Order::create([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'pending',
                ]);

                // 6. initialize the Moneroo payment
                $result = $this->moneroo->initPayment($order);

                if (empty($result['checkout_url']) || empty($result['payment_id'])) {
                    throw new \RuntimeException('Réponse Moneroo invalide.');
                }

                // 7. store the Moneroo identifier on the order
                $order->update(['moneroo_payment_id' => $result['payment_id']]);

                // 8. create the Payment row
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'provider' => 'moneroo',
                    'transaction_id' => $result['payment_id'],
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'pending',
                    'raw_response' => $result['raw'] ?? null,
                ]);

                // 9. return the checkout url
                return response()->json([
                    'checkout_url' => $result['checkout_url'],
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                ], 201);
            });
        } catch (\Throwable $e) {
            Log::error('Payment creation failed', [
                'user_id' => $user->id,
                'course_id' => $course->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Impossible de créer le paiement pour le moment.',
            ], 500);
        }
    }

    /**
     * GET /api/payments/{id}
     *
     * Used by the React /payment/result page to poll the REAL, server-side
     * verified status of the order. Never derive status from URL params.
     */
    public function show(Payment $payment)
    {
        $payment->load('order');

        return response()->json([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'order_id' => $payment->order_id,
            'order_status' => $payment->order->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ]);
    }
}
