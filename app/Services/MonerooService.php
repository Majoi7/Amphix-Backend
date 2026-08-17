<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper around the Moneroo API.
 *
 * IMPORTANT: MONEROO_SECRET_KEY never leaves this service / the Laravel
 * backend. React never talks to Moneroo directly.
 *
 * This service is written against Moneroo's public HTTP API. If you use the
 * official `moneroo/moneroo-laravel` package instead, swap the internals of
 * initPayment()/verifyPayment()/getPayment() to call the package's client
 * (e.g. Moneroo::payments()->initialize([...])) — the public method
 * signatures below can stay the same so the rest of the app (controllers,
 * webhook handler) does not need to change.
 */
class MonerooService
{
    protected string $baseUrl;
    protected string $secretKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.moneroo.base_url', 'https://api.moneroo.io/v1'), '/');
        $this->secretKey = (string) config('services.moneroo.secret_key');
    }

    /**
     * Initialize a payment on Moneroo and return the checkout URL + payment id.
     *
     * @param  Order  $order
     * @return array{checkout_url: string, payment_id: string, raw: array}
     */
    public function initPayment(Order $order): array
    {
        $payload = [
            'amount' => (int) round($order->amount), // Moneroo expects the smallest currency unit / integer amount
            'currency' => $order->currency,
            'description' => "Achat de la formation #{$order->course_id}",
            'customer' => [
                'email' => $order->user->email,
                'first_name' => $order->user->name,
            ],
            'return_url' => rtrim(config('app.frontend_url'), '/') . '/payment/result',
            'metadata' => [
                'order_id' => (string) $order->id,
            ],
        ];

        $response = Http::withToken($this->secretKey)
            ->acceptJson()
            ->post("{$this->baseUrl}/payments/initialize", $payload);

        if ($response->failed()) {
            Log::error('Moneroo initPayment failed', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Impossible d\'initialiser le paiement Moneroo.');
        }

        $data = $response->json('data', $response->json());

        return [
            'checkout_url' => $data['checkout_url'] ?? $data['url'] ?? null,
            'payment_id' => $data['id'] ?? $data['payment_id'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Ask Moneroo directly for the current status of a transaction.
     * Never trust the webhook payload alone — always re-verify server side.
     */
    public function verifyPayment(string $transactionId): array
    {
        $response = Http::withToken($this->secretKey)
            ->acceptJson()
            ->get("{$this->baseUrl}/payments/{$transactionId}");

        if ($response->failed()) {
            Log::error('Moneroo verifyPayment failed', [
                'transaction_id' => $transactionId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Impossible de vérifier le paiement Moneroo.');
        }

        return $response->json('data', $response->json());
    }

    /**
     * Alias kept for clarity / parity with the spec (getPayment()).
     */
    public function getPayment(string $transactionId): array
    {
        return $this->verifyPayment($transactionId);
    }

    /**
     * Verify the X-Moneroo-Signature header using HMAC-SHA256.
     */
    public function verifyWebhookSignature(string $payload, ?string $signatureHeader): bool
    {
        if (empty($signatureHeader)) {
            return false;
        }

        $secret = (string) config('services.moneroo.webhook_secret');
        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
