<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user (Sanctum) can attempt to buy a course.
        return true;
    }

    public function rules(): array
    {
        return [
            // The frontend only ever sends the course_id.
            // The price is ALWAYS resolved server-side from the database.
            'course_id' => ['required', 'integer', 'exists:courses,id'],
        ];
    }
}
