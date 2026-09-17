<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Student\StoreStudentRequest;
use App\Http\Requests\Api\V1\Student\UpdateStudentRequest;
use App\Http\Resources\Api\V1\StudentResource;
use App\Models\Student;
use App\Services\Api\V1\StudentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentController extends Controller
{
    public function __construct(private readonly StudentService $students) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            max((int) $request->integer('per_page', (int) config('api.pagination.default_per_page')), 1),
            (int) config('api.pagination.max_per_page'),
        );

        $paginator = $request->user()->students()->orderBy('id')->cursorPaginate($perPage);

        return ApiResponse::success([
            'items' => StudentResource::collection($paginator->items()),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
            'per_page' => $paginator->perPage(),
        ], 'Students.');
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('view', $student);

        return ApiResponse::success(new StudentResource($student), 'Student.');
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $result = $this->students->create(
            $request->user(),
            $request->safe()->except('id', 'version'),
            $request->validated('id'),
        );

        return ApiResponse::created(new StudentResource($result['student']), 'Student created.');
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $result = $this->students->update(
            $request->user(),
            $student,
            $request->safe()->except('version'),
            (int) $request->validated('version'),
        );

        return ApiResponse::success(new StudentResource($result['student']), 'Student updated.');
    }

    public function destroy(Request $request, Student $student): JsonResponse
    {
        Gate::authorize('delete', $student);

        $this->students->delete(
            $request->user(),
            $student,
            $request->integer('version') ?: null,
        );

        return ApiResponse::success(null, 'Student deleted.');
    }
}
