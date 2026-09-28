<?php

use App\Http\Controllers\Api\V1\AchievementController;
use App\Http\Controllers\Api\V1\Auth\StudentAuthController;
use App\Http\Controllers\Api\V1\Auth\TeacherAuthController;
use App\Http\Controllers\Api\V1\BadgeController;
use App\Http\Controllers\Api\V1\BookController;
use App\Http\Controllers\Api\V1\BookPageController;
use App\Http\Controllers\Api\V1\BookPageNarrationController;
use App\Http\Controllers\Api\V1\ChapterController;
use App\Http\Controllers\Api\V1\ChapterNarrationController;
use App\Http\Controllers\Api\V1\IngestController;
use App\Http\Controllers\Api\V1\OcrController;
use App\Http\Controllers\Api\V1\PronunciationController;
use App\Http\Controllers\Api\V1\PronunciationReviewController;
use App\Http\Controllers\Api\V1\QuizQuestionController;
use App\Http\Controllers\Api\V1\ReaderController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\RewardController;
use App\Http\Controllers\Api\V1\SpeechTokenController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\StudentLearningController;
use App\Http\Controllers\Api\V1\SystemLogController;
use App\Http\Controllers\Api\V1\TeacherDashboardController;
use App\Http\Controllers\Api\V1\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // ---- Public teacher authentication ----
    Route::post('teacher/register', [TeacherAuthController::class, 'register']);
    Route::post('teacher/login', [TeacherAuthController::class, 'login']);

    // ---- Public student authentication ----
    Route::post('student/login', [StudentAuthController::class, 'login']);

    // ---- Authenticated student routes ----
    Route::middleware(['auth:sanctum', 'student'])->group(function () {
        Route::get('student/me', [StudentAuthController::class, 'me']);
        Route::post('student/logout', [StudentAuthController::class, 'logout']);
        Route::get('student/badges', [StudentAuthController::class, 'badges']);
        Route::get('student/achievements', [StudentAuthController::class, 'achievements']);

        // Read-aloud pronunciation assessment (student submits a recording)
        Route::post('pronunciation', [PronunciationController::class, 'store']);
        // Student-scoped path on purpose: the teacher review controller owns
        // `pronunciation/{attempt}`, and two routes on one URI would mean the
        // later registration quietly wins.
        Route::get('student/pronunciation/{attempt}', [PronunciationController::class, 'show']);
        Route::post('student/pronunciation/{attempt}/words/{word}/retry', [PronunciationController::class, 'retryWord']);

        // ---- Learning loop: progress, chapters, activities, quiz ----
        Route::get('student/progress', [StudentLearningController::class, 'overview']);
        Route::get('student/books/{book}/progress', [StudentLearningController::class, 'book']);
        Route::get('student/chapters/{chapter}/quiz', [StudentLearningController::class, 'quiz']);
        Route::post('student/chapters/{chapter}/quiz', [StudentLearningController::class, 'submitQuiz']);
        Route::post('student/chapters/{chapter}/quiz/check', [StudentLearningController::class, 'checkQuizAnswer']);
        Route::get('student/chapters/{chapter}/games', [StudentLearningController::class, 'games']);
        Route::get('student/chapters/{chapter}/read-aloud', [StudentLearningController::class, 'readAloud']);
        Route::post('student/chapters/{chapter}/story-read', [StudentLearningController::class, 'markStoryRead']);
        Route::post('student/chapters/{chapter}/game', [StudentLearningController::class, 'completeGame']);

        // ---- Page-based (scanned) books ----
        Route::get('student/books/{book}/pages', [StudentLearningController::class, 'bookPages']);
        Route::post('student/pages/{page}/read', [StudentLearningController::class, 'markPageRead']);
    });

    // ---- Reader + narration — available to any authenticated user (teacher or student) ----
    Route::middleware('auth:sanctum')->group(function () {
        // Short-lived Azure token for live, word-by-word reading assessment in
        // the browser. Throttled: a token is good for ten minutes, so anything
        // asking for more than a handful a minute is a loop, not a reader.
        Route::post('speech/token', SpeechTokenController::class)->middleware('throttle:20,1');

        Route::get('chapters/{chapter}/narration', ChapterNarrationController::class);
        Route::get('pages/{page}/narration', BookPageNarrationController::class);

        // Reading library
        Route::get('reader/books', [ReaderController::class, 'books']);
        Route::get('reader/books/{book}', [ReaderController::class, 'show']);
    });

    // ---- Authenticated teacher routes ----
    Route::middleware(['auth:sanctum', 'teacher'])->group(function () {
        Route::get('teacher/me', [TeacherAuthController::class, 'me']);
        Route::match(['put', 'patch'], 'teacher/me', [TeacherAuthController::class, 'updateProfile']);
        Route::post('teacher/logout', [TeacherAuthController::class, 'logout']);

        // Teacher dashboard + progress monitoring / reports
        Route::get('dashboard', [TeacherDashboardController::class, 'index']);
        Route::get('students/{student}/progress', [TeacherDashboardController::class, 'studentProgress']);

        // Downloadable reports (Reports Module)
        Route::get('reports/class.csv', [ReportController::class, 'classReport']);
        Route::get('reports/students/{student}.csv', [ReportController::class, 'studentReport']);

        // Achievement milestones
        Route::get('achievements', [AchievementController::class, 'index']);
        Route::get('students/{student}/achievements', [AchievementController::class, 'forStudent']);

        // Audit trail (System Log)
        Route::get('system-logs', [SystemLogController::class, 'index']);

        // Book assignment (reading level + book assignment module)
        Route::get('students/{student}/books', [StudentController::class, 'books']);
        Route::put('students/{student}/books', [StudentController::class, 'syncBooks']);

        // Image uploads (book covers, chapter illustrations, etc.)
        Route::post('uploads/image', [UploadController::class, 'image']);

        // Read printed text out of a scan/photo (fills in chapter story text)
        Route::post('ocr/extract', [OcrController::class, 'extract']);

        // Reading material upload: a PDF or page photos become a book, with no
        // "add book" step and nothing typed by hand.
        Route::get('ingest', [IngestController::class, 'index']);
        Route::post('ingest', [IngestController::class, 'store']);
        Route::get('ingest/{batch}', [IngestController::class, 'show']);
        Route::match(['put', 'patch'], 'ingest/{batch}/pages/{page}', [IngestController::class, 'updatePage']);
        // Re-read one page (optionally from a replacement photo) instead of typing its words.
        Route::post('ingest/{batch}/pages/{page}/rescan', [IngestController::class, 'rescanPage']);
        Route::post('ingest/{batch}/commit', [IngestController::class, 'commit']);
        Route::delete('ingest/{batch}', [IngestController::class, 'destroy']);

        // Student roster (scoped to the authenticated teacher)
        Route::apiResource('students', StudentController::class);

        // Books (shared DepEd Leveled Reader content). No `store`: a book is
        // created by uploading its material (see ingest above), never by hand.
        Route::apiResource('books', BookController::class)->except(['store']);

        // Badge catalog (gamification rewards)
        Route::apiResource('badges', BadgeController::class);

        // Awarding badges to a student (+ points)
        Route::get('students/{student}/badges', [RewardController::class, 'index']);
        Route::post('students/{student}/badges/{badge}', [RewardController::class, 'store']);
        Route::delete('students/{student}/badges/{badge}', [RewardController::class, 'destroy']);

        // Pronunciation review & validation
        Route::get('pronunciation/queue', [PronunciationReviewController::class, 'queue']);
        Route::get('students/{student}/pronunciation', [PronunciationReviewController::class, 'index']);
        Route::get('students/{student}/reading-report', [PronunciationReviewController::class, 'report']);
        Route::get('pronunciation/{attempt}', [PronunciationReviewController::class, 'show']);
        Route::post('pronunciation/{attempt}/validate', [PronunciationReviewController::class, 'validateAttempt']);
        Route::post('pronunciation/{attempt}/score', [PronunciationReviewController::class, 'override']);

        // Scanned book pages — upload (with OCR), edit text, delete
        Route::get('books/{book}/pages', [BookPageController::class, 'index']);
        Route::post('books/{book}/pages', [BookPageController::class, 'store']);
        Route::match(['put', 'patch'], 'pages/{page}', [BookPageController::class, 'update']);
        Route::post('pages/{page}/rescan', [BookPageController::class, 'rescan']);
        Route::delete('pages/{page}', [BookPageController::class, 'destroy']);

        // Chapters — added by uploading scans of their pages, never typed in
        Route::get('books/{book}/chapters', [ChapterController::class, 'index']);
        Route::post('books/{book}/chapters/upload', [ChapterController::class, 'upload']);
        Route::get('chapters/{chapter}', [ChapterController::class, 'show']);
        Route::match(['put', 'patch'], 'chapters/{chapter}', [ChapterController::class, 'update']);
        Route::delete('chapters/{chapter}', [ChapterController::class, 'destroy']);

        // Quiz questions — generated from the chapter text; teachers review,
        // correct, delete or regenerate them rather than typing them
        Route::get('chapters/{chapter}/quiz-questions', [QuizQuestionController::class, 'index']);
        Route::post('chapters/{chapter}/quiz-questions/generate', [QuizQuestionController::class, 'generate']);
        Route::get('quiz-questions/{quizQuestion}', [QuizQuestionController::class, 'show']);
        Route::match(['put', 'patch'], 'quiz-questions/{quizQuestion}', [QuizQuestionController::class, 'update']);
        Route::delete('quiz-questions/{quizQuestion}', [QuizQuestionController::class, 'destroy']);
    });
});
