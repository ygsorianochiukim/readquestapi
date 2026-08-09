<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Achievement\Services\AchievementService;
use App\Domain\Badge\Services\RewardService;
use App\Domain\Progress\Services\ProgressService;
use App\Domain\Pronunciation\Services\PronunciationService;
use App\Domain\Student\Models\Student;
use App\Domain\SystemLog\Services\SystemLogService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloadable reports (Reports Module). CSV opens directly in Excel, so a
 * teacher can attach it to a record or print it.
 */
class ReportController extends Controller
{
    public function __construct(
        private ProgressService $progress,
        private PronunciationService $pronunciation,
        private RewardService $rewards,
        private AchievementService $achievements,
        private SystemLogService $logs,
    ) {}

    /** Whole-class summary: one row per student. */
    public function classReport(Request $request): StreamedResponse
    {
        $teacher = $request->user();
        $students = $teacher->students()->orderBy('last_name')->get();

        $rows = [[
            'Student', 'Username', 'Reading level', 'Status', 'Points',
            'Assigned books', 'Completed books', 'Chapters completed', 'Total chapters',
            'Completion %', 'Badges', 'Achievements unlocked', 'Read-aloud attempts', 'Pending validation',
        ]];

        foreach ($students as $student) {
            $detail = $this->progress->detailForStudent($student);
            $attempts = $this->pronunciation->forStudent($student->id);
            $achievements = $this->achievements->forStudent($student);
            $overview = $this->progress->overviewForStudent($student);

            $rows[] = [
                $student->full_name,
                $student->username,
                $student->reading_level ?? '',
                $student->status,
                $student->points,
                count($overview),
                collect($overview)->where('is_completed', true)->count(),
                $detail['completed_chapters'],
                $detail['total_chapters'],
                $detail['percent'],
                $this->rewards->forStudent($student)->count(),
                $achievements['unlocked'].' of '.$achievements['total'],
                $attempts->count(),
                $attempts->where('is_validated', false)->count(),
            ];
        }

        $this->logs->record(
            'report.exported',
            "{$teacher->full_name} exported the class progress report ({$students->count()} students).",
            null,
            $teacher,
        );

        return $this->csv('readquest-class-report.csv', $rows);
    }

    /** Full report for one student: chapters, read-alouds, badges, achievements. */
    public function studentReport(Request $request, Student $student): StreamedResponse
    {
        $teacher = $request->user();

        abort_if(
            $student->teacher_id !== $teacher->id,
            403,
            'This student does not belong to you.',
        );

        $detail = $this->progress->detailForStudent($student);
        $achievements = $this->achievements->forStudent($student);

        $rows = [
            ['ReadQuest — Student Progress Report'],
            ['Student', $student->full_name],
            ['Username', $student->username],
            ['Reading level', $student->reading_level ?? ''],
            ['Points', $student->points],
            ['Overall completion', $detail['percent'].'%'],
            ['Chapters completed', $detail['completed_chapters'].' of '.$detail['total_chapters']],
            ['Generated', now()->toDayDateTimeString()],
            [],
            ['CHAPTER PROGRESS'],
            ['Book', 'Chapter', 'Title', 'Status', 'Story read', 'Read-aloud passed', 'Game done', 'Quiz passed', 'Quiz score', 'Completed at'],
        ];

        foreach ($detail['books'] as $book) {
            foreach ($book['chapters'] as $chapter) {
                $chapterProgress = $chapter['progress'] ?? null;
                $rows[] = [
                    $book['title'],
                    $chapter['chapter_number'],
                    $chapter['title'],
                    $chapterProgress['status'] ?? 'not_started',
                    $this->yesNo($chapterProgress['story_read'] ?? false),
                    $this->yesNo($chapterProgress['pronunciation_passed'] ?? false),
                    $this->yesNo($chapterProgress['game_completed'] ?? false),
                    $this->yesNo($chapterProgress['quiz_passed'] ?? false),
                    $chapterProgress['quiz_score'] ?? '',
                    $chapterProgress['completed_at'] ?? '',
                ];
            }
        }

        $rows[] = [];
        $rows[] = ['PRONUNCIATION ATTEMPTS'];
        $rows[] = ['Date', 'Accuracy', 'Fluency', 'Completeness', 'Overall', 'Validated', 'Reference text'];

        foreach ($this->pronunciation->forStudent($student->id) as $attempt) {
            $rows[] = [
                (string) $attempt->created_at,
                $attempt->accuracy_score,
                $attempt->fluency_score,
                $attempt->completeness_score,
                $attempt->pron_score,
                $this->yesNo((bool) $attempt->is_validated),
                str($attempt->reference_text ?? '')->limit(120)->value(),
            ];
        }

        $rows[] = [];
        $rows[] = ['BADGES EARNED'];
        $rows[] = ['Badge', 'Points', 'Earned at'];
        foreach ($this->rewards->forStudent($student) as $badge) {
            $rows[] = [$badge->name, $badge->points, (string) ($badge->pivot->earned_at ?? '')];
        }

        $rows[] = [];
        $rows[] = ['ACHIEVEMENTS'];
        $rows[] = ['Achievement', 'Goal', 'Progress', 'Unlocked', 'Unlocked at'];
        foreach ($achievements['achievements'] as $achievement) {
            $rows[] = [
                $achievement['name'],
                $achievement['threshold'].' '.$achievement['metric_label'],
                $achievement['current'].'/'.$achievement['threshold'],
                $this->yesNo($achievement['is_unlocked']),
                (string) ($achievement['unlocked_at'] ?? ''),
            ];
        }

        $this->logs->record(
            'report.exported',
            "{$teacher->full_name} exported the progress report for {$student->full_name}.",
            $student,
            $teacher,
        );

        $slug = str($student->full_name)->slug()->value();

        return $this->csv("readquest-report-{$slug}.csv", $rows);
    }

    /** @param array<int, array<int, mixed>> $rows */
    private function csv(string $filename, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            // BOM so Excel reads UTF-8 (and emoji in badge names) correctly.
            fwrite($handle, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
