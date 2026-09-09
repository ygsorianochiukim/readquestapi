<?php

namespace App\Domain\Pronunciation\Services;

use App\Domain\Achievement\Services\AchievementService;
use App\Domain\Badge\Models\Badge;
use App\Domain\Badge\Services\RewardService;
use App\Domain\Book\Models\BookPage;
use App\Domain\Chapter\Models\Chapter;
use App\Domain\Progress\Services\PageProgressService;
use App\Domain\Progress\Services\ProgressService;
use App\Domain\Pronunciation\Models\PronunciationAttempt;
use App\Domain\Pronunciation\Repositories\PronunciationRepository;
use App\Domain\Speech\Services\PronunciationAssessmentService;
use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Services\SystemLogService;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

class PronunciationService
{
    /** Auto-award the "Clear Speaker" badge at or above this score. */
    private const BADGE_THRESHOLD = 90;

    /** Below this share of said-words-on-the-page, the pupil read something else. */
    private const OFF_SCRIPT_BELOW = 50;

    public function __construct(
        private PronunciationRepository $repository,
        private PronunciationAssessmentService $assessment,
        private ReadingMatchService $match,
        private RewardService $rewards,
        private ProgressService $progress,
        private PageProgressService $pageProgress,
        private AchievementService $achievements,
        private SystemLogService $logs,
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

        // Azure is handed the page text up front, so its score says how well the
        // audio aligned to that text — not whether the pupil read it. Match the
        // words we actually heard against the page and cap the overall score
        // with them, so reading something else can never score as a good read.
        $reading = $this->match->evaluate($referenceText, $scores['recognized_text']);
        // Off-script is about saying words that are not on the page; reading
        // only part of the page is a low match, not a wrong page.
        $offScript = $reading['on_page'] < self::OFF_SCRIPT_BELOW;
        $pronScore = $scores['pron_score'] === null
            ? null
            : min((float) $scores['pron_score'], $reading['match']);

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
            'pron_score' => $pronScore,
            'text_match_score' => $reading['match'],
            'is_off_script' => $offScript,
        ]);

        $this->maybeAwardBadge($student, $pronScore);

        // A read-aloud attempt on a standard chapter counts toward that chapter's progress.
        if ($chapterId) {
            $chapter = Chapter::find($chapterId);
            if ($chapter) {
                $this->progress->recordPronunciation($student, $chapter, $pronScore);
            }
        }

        // …and one on a scanned page counts toward that page's progress.
        if ($bookPageId) {
            $page = BookPage::with('book')->find($bookPageId);
            if ($page) {
                $this->pageProgress->recordPronunciation($student, $page, $pronScore);
            }
        }

        $this->logs->record(
            'pronunciation.assessed',
            sprintf(
                '%s recorded a read-aloud and scored %s overall.%s',
                $student->full_name,
                $pronScore !== null ? round($pronScore).'%' : 'no score',
                $offScript ? ' The words did not match the page.' : '',
            ),
            $student,
        );

        // Book-page attempts never touch chapter progress, so sync milestones here too.
        $this->achievements->sync($student->refresh());

        return $attempt;
    }

    /**
     * @return Collection<int, PronunciationAttempt>
     */
    public function forStudent(int $studentId): Collection
    {
        return $this->repository->forStudent($studentId);
    }

    public function validate(PronunciationAttempt $attempt, ?Teachers $by = null): PronunciationAttempt
    {
        $validated = $this->repository->markValidated($attempt);

        $this->logs->record(
            'pronunciation.validated',
            sprintf(
                '%s validated a read-aloud score of %s for %s.',
                $by?->full_name ?? 'A teacher',
                $attempt->pron_score !== null ? round($attempt->pron_score).'%' : 'no score',
                $attempt->student?->full_name ?? 'a student',
            ),
            $attempt->student,
            $by,
        );

        return $validated;
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
