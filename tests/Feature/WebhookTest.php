<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\MonerooService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function makePendingOrderWithPayment(float $amount = 1000): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => $amount, 'status' => 'active']);
        $order = Order::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'currency' => 'XOF',
            'status' => 'pending',
            'moneroo_payment_id' => 'moneroo_txn_abc123',
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'provider' => 'moneroo',
            'transaction_id' => 'moneroo_txn_abc123',
            'amount' => $amount,
            'currency' => 'XOF',
            'status' => 'pending',
        ]);

        return [$order, $payment];
    }

    protected function signedPayload(array $payload): array
    {
        $body = json_encode($payload);
        $secret = config('services.moneroo.webhook_secret', 'test-secret');
        $signature = hash_hmac('sha256', $body, $secret);

        return [$body, $signature];
    }

    public function test_valid_webhook_marks_payment_and_order_as_paid(): void
    {
        config(['services.moneroo.webhook_secret' => 'test-secret']);
        [$order, $payment] = $this->makePendingOrderWithPayment(1000);

        $this->mock(MonerooService::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->andReturn(true);
            $mock->shouldReceive('verifyPayment')->once()->andReturn([
                'status' => 'success',
                'amount' => 1000,
            ]);
        });

        $response = $this->postJson('/api/webhooks/moneroo', [
            'event' => 'payment.success',
            'data' => ['id' => 'moneroo_txn_abc123'],
        ], ['X-Moneroo-Signature' => 'irrelevant-because-mocked']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'success']);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $this->mock(MonerooService::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->andReturn(false);
        });

        $response = $this->postJson('/api/webhooks/moneroo', [
            'event' => 'payment.success',
            'data' => ['id' => 'moneroo_txn_abc123'],
        ], ['X-Moneroo-Signature' => 'bad-signature']);

        $response->assertStatus(403);
    }

    public function test_webhook_does_not_process_an_already_paid_payment_twice(): void
    {
        [$order, $payment] = $this->makePendingOrderWithPayment(1000);
        $payment->update(['status' => 'success']);
        $order->update(['status' => 'paid']);

        $this->mock(MonerooService::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->andReturn(true);
            // verifyPayment should NOT be called again since payment is already 'success'
            $mock->shouldNotReceive('verifyPayment');
        });

        $response = $this->postJson('/api/webhooks/moneroo', [
            'event' => 'payment.success',
            'data' => ['id' => 'moneroo_txn_abc123'],
        ], ['X-Moneroo-Signature' => 'irrelevant-because-mocked']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_webhook_rejects_amount_mismatch(): void
    {
        [$order, $payment] = $this->makePendingOrderWithPayment(1000);

        $this->mock(MonerooService::class, function ($mock) {
            $mock->shouldReceive('verifyWebhookSignature')->andReturn(true);
            $mock->shouldReceive('verifyPayment')->once()->andReturn([
                'status' => 'success',
                'amount' => 1, // tampered / mismatched amount
            ]);
        });

        $response = $this->postJson('/api/webhooks/moneroo', [
            'event' => 'payment.success',
            'data' => ['id' => 'moneroo_txn_abc123'],
        ], ['X-Moneroo-Signature' => 'irrelevant-because-mocked']);

        $response->assertStatus(200); // webhook still ack'd, but order stays pending
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
    }
}
