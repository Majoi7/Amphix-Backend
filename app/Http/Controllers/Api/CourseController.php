<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;

class CourseController extends Controller
{
    public function index()
    {
        return response()->json(
            Course::where('status', 'active')->latest()->get()
        );
    }

    public function show(Course $course)
    {
        return response()->json($course);
    }
}
