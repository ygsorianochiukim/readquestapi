<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Book\Models\Book;
use App\Domain\Student\Models\Student;
use App\Domain\Student\Services\StudentService;
use App\Domain\SystemLog\Services\SystemLogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\BulkAssignBooksRequest;
use App\Http\Requests\CreateStudentRequest;
use App\Http\Requests\SyncBooksRequest;
use App\Http\Requests\UpdateStudentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentController extends Controller
{
    public function __construct(
        private StudentService $service,
        private SystemLogService $logs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->listForTeacher($request->user()->id),
        ]);
    }

    public function store(CreateStudentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['teacher_id'] = $request->user()->id;

        $student = $this->service->create($data);

        $this->logs->record(
            'student.created',
            "{$request->user()->full_name} created the student account \"{$student->username}\" for {$student->full_name}.",
            $student,
            $request->user(),
        );

        return response()->json(['data' => $student], 201);
    }

    public function show(Request $request, Student $student): JsonResponse
    {
        $this->authorizeOwner($request, $student);

        return response()->json(['data' => $student]);
    }

    public function update(UpdateStudentRequest $request, Student $student): JsonResponse
    {
        $this->authorizeOwner($request, $student);

        $changed = array_keys($request->validated());
        $student = $this->service->update($student, $request->validated());

        $this->logs->record(
            'student.updated',
            sprintf(
                '%s updated %s (%s).',
                $request->user()->full_name,
                $student->full_name,
                implode(', ', $changed) ?: 'no fields',
            ),
            $student,
            $request->user(),
        );

        return response()->json(['data' => $student]);
    }

    public function destroy(Request $request, Student $student): JsonResponse
    {
        $this->authorizeOwner($request, $student);

        // Log before deleting: the student row (and its FK) disappears with it.
        $this->logs->record(
            'student.deleted',
            "{$request->user()->full_name} deleted the student account \"{$student->username}\" ({$student->full_name}).",
            null,
            $request->user(),
        );

        $this->service->delete($student);

        return response()->json(['message' => 'Student deleted successfully.']);
    }

    /** Books currently assigned to a student. */
    public function books(Request $request, Student $student): JsonResponse
    {
        $this->authorizeOwner($request, $student);

        return response()->json(['data' => $student->books()->get()]);
    }

    /** Replace a student's assigned books with the given set. */
    public function syncBooks(SyncBooksRequest $request, Student $student): JsonResponse
    {
        $this->authorizeOwner($request, $student);

        $bookIds = $request->validated()['book_ids'];
        $now = now();
        $payload = collect($bookIds)->mapWithKeys(
            fn ($bookId) => [$bookId => ['assigned_at' => $now]]
        )->all();

        $student->books()->sync($payload);

        $titles = $student->books()->get()->pluck('title')->implode(', ');
        $this->logs->record(
            'student.books_assigned',
            sprintf(
                '%s assigned %s to %s.',
                $request->user()->full_name,
                $titles !== '' ? $titles : 'no books',
                $student->full_name,
            ),
            $student,
            $request->user(),
        );

        return response()->json(['data' => $student->books()->get()]);
    }

    /**
     * The books the Assign books picker offers: this teacher's published
     * uploads, plus the shared ones every teacher can use.
     */
    public function assignableBooks(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Book::query()->assignableBy($request->user()->id)->orderBy('sequence')->get(),
        ]);
    }

    /**
     * Give several books to several pupils at once. Adds to what each pupil
     * already has — nothing assigned before is taken away, and a book a pupil
     * already had keeps its original assigned date.
     */
    public function assignBooks(BulkAssignBooksRequest $request): JsonResponse
    {
        $teacher = $request->user();
        $data = $request->validated();

        $students = Student::query()->whereIn('id', $data['student_ids'])->get();

        abort_if(
            $students->contains(fn (Student $student) => $student->teacher_id !== $teacher->id),
            403,
            'One or more of these students do not belong to you.',
        );

        $books = Book::query()
            ->assignableBy($teacher->id)
            ->whereIn('id', $data['book_ids'])
            ->orderBy('sequence')
            ->get();

        abort_if(
            $books->count() !== count($data['book_ids']),
            422,
            'One or more of these books are not yours to assign, or are not published yet.',
        );

        $now = now();
        $newlyAssigned = 0;

        foreach ($students as $student) {
            $missing = $books->pluck('id')->diff($student->books()->pluck('books.id'));

            $student->books()->attach(
                $missing->mapWithKeys(fn ($bookId) => [$bookId => ['assigned_at' => $now]])->all()
            );

            $newlyAssigned += $missing->count();
        }

        $bookPart = $books->count() === 1
            ? "\"{$books->first()->title}\""
            : $books->count().' books ('.$books->pluck('title')->implode(', ').')';

        $this->logs->record(
            'books.bulk_assigned',
            sprintf(
                '%s assigned %s to %s: %s.',
                $teacher->full_name,
                $bookPart,
                $students->count() === 1 ? '1 pupil' : $students->count().' pupils',
                $students->pluck('full_name')->implode(', '),
            ),
            $students->count() === 1 ? $students->first() : null,
            $teacher,
        );

        return response()->json([
            'message' => 'Books assigned.',
            'data' => [
                'books' => $books->count(),
                'students' => $students->count(),
                'newly_assigned' => $newlyAssigned,
            ],
        ]);
    }

    private function authorizeOwner(Request $request, Student $student): void
    {
        abort_if(
            $student->teacher_id !== $request->user()->id,
            403,
            'This student does not belong to you.',
        );
    }
}
