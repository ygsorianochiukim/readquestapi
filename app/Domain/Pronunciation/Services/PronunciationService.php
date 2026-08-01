<?php

namespace App\Domain\Pronunciation\Services;

use App\Domain\Badge\Models\Badge;
use App\Domain\Badge\Services\RewardService;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Progress\Services\ProgressService;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Repositories\PronunciationRepository;
use App\Domain\Speech\Services\PronunciationAssessmentService;
use App\Domain\Student\Models\Student;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

class PronunciationService
{
    /** Auto-award the "Clear Speaker" badge at or above this score. */
    private const BADGE_THRESHOLD = 90;

    public function __construct(
        private PronunciationRepository $repository,
        private PronunciationAssessmentService $assessment,
        private RewardService $rewards,
        private ProgressService $progress,
    ) {}

    public function isConfigured(): bool
    {
        return $this->assessment->isConfigured();
    }

    public function assessAndStore(
        Student $student,
        string $referenceText,
        UploadedFile $audio,
        ?int $bookPageId,
        ?int $chapterId,
    ): PronunciationAttempt {
        $audioBytes = file_get_contents($audio->getRealPath());
        $path = $audio->store("pronunciation/{$student->id}", 'public');

        $scores = $this->assessment->assess($referenceText, $audioBytes);

        $attempt = $this->repository->create([
            'student_id' => $student->id,
            'book_page_id' => $bookPageId,
            'chapter_id' => $chapterId,
            'reference_text' => $referenceText,
            'recognized_text' => $scores['recognized_text'],
            'audio_path' => $path,
            'accuracy_score' => $scores['accuracy_score'],
            'fluency_score' => $scores['fluency_score'],
            'completeness_score' => $scores['completeness_score'],
            'pron_score' => $scores['pron_score'],
        ]);

        $this->maybeAwardBadge($student, $scores['pron_score']);

        // A read-aloud attempt on a standard chapter counts toward that chapter's progress.
        if ($chapterId) {
            $chapter = Chapter::find($chapterId);
            if ($chapter) {
                $this->progress->recordPronunciation($student, $chapter, $scores['pron_score']);
            }
        }

        return $attempt;
    }

    /**
     * @return Collection<int, PronunciationAttempt>
     */
    public function forStudent(int $studentId): Collection
    {
        return $this->repository->forStudent($studentId);
    }

    public function validate(PronunciationAttempt $attempt): PronunciationAttempt
    {
        return $this->repository->markValidated($attempt);
    }

    public function pendingCountForTeacher(int $teacherId): int
    {
        return $this->repository->pendingCountForTeacher($teacherId);
    }

    private function maybeAwardBadge(Student $student, ?float $pronScore): void
    {
        if ($pronScore === null || $pronScore < self::BADGE_THRESHOLD) {
            return;
        }

        $badge = Badge::where('name', 'Clear Speaker')->first();
        if ($badge) {
            $this->rewards->award($student, $badge);
        }
    }
}
