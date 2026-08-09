<?php

namespace Database\Seeders;

use App\Domain\Achievement\Models\Achievement;
use Illuminate\Database\Seeder;

/**
 * The achievement catalog. Achievements are milestones a pupil works toward;
 * each names a metric tracked by AchievementService and a threshold to reach.
 *
 * Note: point-based achievements award 0 points on purpose, so unlocking one
 * can never push the student past the next point milestone by itself.
 */
class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        $achievements = [
            // ---- Reading the stories ----
            ['code' => 'story_first', 'name' => 'Page Turner', 'icon' => 'book', 'description' => 'Read your very first story.', 'metric' => 'stories_read', 'threshold' => 1, 'points' => 5, 'sequence' => 1],
            ['code' => 'story_ten', 'name' => 'Story Explorer', 'icon' => 'map-pin', 'description' => 'Read ten stories.', 'metric' => 'stories_read', 'threshold' => 10, 'points' => 25, 'sequence' => 2],

            // ---- Chapters & books ----
            ['code' => 'chapter_first', 'name' => 'Adventure Begins', 'icon' => 'sparkles', 'description' => 'Finish your first chapter.', 'metric' => 'chapters_completed', 'threshold' => 1, 'points' => 10, 'sequence' => 3],
            ['code' => 'chapter_five', 'name' => 'Chapter Champion', 'icon' => 'milestone', 'description' => 'Finish five chapters.', 'metric' => 'chapters_completed', 'threshold' => 5, 'points' => 30, 'sequence' => 4],
            ['code' => 'chapter_twelve', 'name' => 'Halfway Hero', 'icon' => 'flag', 'description' => 'Finish twelve chapters.', 'metric' => 'chapters_completed', 'threshold' => 12, 'points' => 60, 'sequence' => 5],
            ['code' => 'chapter_all', 'name' => 'Grand Adventurer', 'icon' => 'map', 'description' => 'Finish all twenty-four chapters.', 'metric' => 'chapters_completed', 'threshold' => 24, 'points' => 120, 'sequence' => 6],
            ['code' => 'book_first', 'name' => 'Book Finisher', 'icon' => 'book-marked', 'description' => 'Complete a whole book.', 'metric' => 'books_completed', 'threshold' => 1, 'points' => 40, 'sequence' => 7],
            ['code' => 'book_three', 'name' => 'Shelf Builder', 'icon' => 'books', 'description' => 'Complete three books.', 'metric' => 'books_completed', 'threshold' => 3, 'points' => 80, 'sequence' => 8],
            ['code' => 'book_six', 'name' => 'Library Legend', 'icon' => 'institution', 'description' => 'Complete all six DepEd Leveled Reader books.', 'metric' => 'books_completed', 'threshold' => 6, 'points' => 150, 'sequence' => 9],

            // ---- Scanned / page-based books ----
            ['code' => 'page_first', 'name' => 'First Page', 'icon' => 'story', 'description' => 'Finished your first page in a scanned book.', 'metric' => 'pages_read', 'threshold' => 1, 'points' => 5, 'sequence' => 19],
            ['code' => 'page_twenty', 'name' => 'Page Master', 'icon' => 'scroll', 'description' => 'Finished twenty pages.', 'metric' => 'pages_read', 'threshold' => 20, 'points' => 60, 'sequence' => 20],

            // ---- Reading aloud ----
            ['code' => 'readaloud_first', 'name' => 'Brave Voice', 'icon' => 'mic', 'description' => 'Pass your first read-aloud.', 'metric' => 'read_alouds_passed', 'threshold' => 1, 'points' => 10, 'sequence' => 10],
            ['code' => 'readaloud_ten', 'name' => 'Confident Reader', 'icon' => 'speech', 'description' => 'Pass ten read-alouds.', 'metric' => 'read_alouds_passed', 'threshold' => 10, 'points' => 50, 'sequence' => 11],
            ['code' => 'clear_three', 'name' => 'Crystal Clear', 'icon' => 'star', 'description' => 'Score 90% or higher on three read-alouds.', 'metric' => 'clear_reads', 'threshold' => 3, 'points' => 60, 'sequence' => 12],

            // ---- Quizzes & games ----
            ['code' => 'quiz_perfect', 'name' => 'Sharp Thinker', 'icon' => 'quiz', 'description' => 'Get a perfect score on a quiz.', 'metric' => 'perfect_quizzes', 'threshold' => 1, 'points' => 20, 'sequence' => 13],
            ['code' => 'quiz_perfect_five', 'name' => 'Quiz Genius', 'icon' => 'graduation', 'description' => 'Get five perfect quiz scores.', 'metric' => 'perfect_quizzes', 'threshold' => 5, 'points' => 70, 'sequence' => 14],
            ['code' => 'game_five', 'name' => 'Word Wizard', 'icon' => 'game', 'description' => 'Finish five chapter games.', 'metric' => 'games_completed', 'threshold' => 5, 'points' => 30, 'sequence' => 15],

            // ---- Collecting ----
            ['code' => 'badge_three', 'name' => 'Badge Collector', 'icon' => 'badges', 'description' => 'Earn three badges.', 'metric' => 'badges_earned', 'threshold' => 3, 'points' => 25, 'sequence' => 16],
            ['code' => 'points_100', 'name' => 'Rising Star', 'icon' => 'star', 'description' => 'Reach 100 points.', 'metric' => 'points', 'threshold' => 100, 'points' => 0, 'sequence' => 17],
            ['code' => 'points_500', 'name' => 'Super Star', 'icon' => 'crown', 'description' => 'Reach 500 points.', 'metric' => 'points', 'threshold' => 500, 'points' => 0, 'sequence' => 18],
        ];

        foreach ($achievements as $achievement) {
            Achievement::updateOrCreate(['code' => $achievement['code']], $achievement);
        }
    }
}
