<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\MonerooService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_payment_for_an_active_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 1000, 'status' => 'active']);

        $this->mock(MonerooService::class, function ($mock) {
            $mock->shouldReceive('initPayment')->once()->andReturn([
                'checkout_url' => 'https://checkout.moneroo.io/abc123',
                'payment_id' => 'moneroo_txn_abc123',
                'raw' => [],
            ]);
        });

        $response = $this->actingAs($user)->postJson('/api/payments/create', [
            'course_id' => $course->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['checkout_url', 'payment_id', 'order_id']);

        $this->assertDatabaseHas('orders', [
            'course_id' => $course->id,
            'user_id' => $user->id,
            'amount' => 1000,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('payments', [
            'transaction_id' => 'moneroo_txn_abc123',
            'status' => 'pending',
        ]);
    }

    public function test_payment_creation_fails_for_inactive_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['status' => 'inactive']);

        $response = $this->actingAs($user)->postJson('/api/payments/create', [
            'course_id' => $course->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_payment_creation_fails_for_nonexistent_course(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/payments/create', [
            'course_id' => 999999,
        ]);

        $response->assertStatus(422); // fails "exists:courses,id" validation
    }

    public function test_frontend_cannot_override_the_amount(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create(['price' => 1000, 'status' => 'active']);

        $this->mock(MonerooService::class, function ($mock) {
            $mock->shouldReceive('initPayment')->once()->andReturn([
                'checkout_url' => 'https://checkout.moneroo.io/abc123',
                'payment_id' => 'moneroo_txn_abc123',
                'raw' => [],
            ]);
        });

        // Even if a malicious client sends an "amount" field, it is ignored.
        $this->actingAs($user)->postJson('/api/payments/create', [
            'course_id' => $course->id,
            'amount' => 1, // attempted tampering
        ]);

        $this->assertDatabaseHas('orders', [
            'course_id' => $course->id,
            'amount' => 1000, // price still comes from the DB
        ]);
    }
}
