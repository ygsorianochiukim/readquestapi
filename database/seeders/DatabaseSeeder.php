<?php

namespace Database\Seeders;

use App\Domain\Badge\Models\Badge;
use App\Domain\Book\Models\Book;
use App\Domain\Student\Models\Student;
use App\Domain\Teachers\Models\Teachers;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // ---- Demo teacher ----
        $teacher = Teachers::firstOrCreate(
            ['email' => 'teacher@readquest.test'],
            [
                'first_name' => 'Demo',
                'last_name' => 'Teacher',
                'phone_number' => '09123456789',
                'password' => 'password', // auto-hashed via the model cast
                'status' => 'active',
            ],
        );

        // ---- A couple of demo students ----
        $juan = Student::firstOrCreate(
            ['username' => 'juan.dela.cruz'],
            [
                'teacher_id' => $teacher->id,
                'first_name' => 'Juan',
                'last_name' => 'Dela Cruz',
                'password' => 'password',
                'reading_level' => 'Level 1',
                'status' => 'active',
            ],
        );

        $maria = Student::firstOrCreate(
            ['username' => 'maria.santos'],
            [
                'teacher_id' => $teacher->id,
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'password' => 'password',
                'reading_level' => 'Level 2',
                'status' => 'active',
            ],
        );

        // ---- The 6 DepEd Leveled Reader Books, each with 4 chapters ----
        // NOTE: Titles are placeholders; replace with the official DepEd titles.
        for ($b = 1; $b <= 6; $b++) {
            $book = Book::firstOrCreate(
                ['sequence' => $b],
                [
                    'title' => "DepEd Leveled Reader Book {$b}",
                    'description' => "Official DepEd Leveled Reader Book {$b}, calibrated to reading level {$b}.",
                    'reading_level' => "Level {$b}",
                    'status' => 'active',
                ],
            );

            for ($c = 1; $c <= 4; $c++) {
                $chapter = $book->chapters()->firstOrCreate(
                    ['chapter_number' => $c],
                    [
                        'title' => "Chapter {$c}",
                        'story_text' => "Placeholder story text for Book {$b}, Chapter {$c}. Replace with the actual DepEd story content.",
                    ],
                );

                // Seed sample quiz questions only for the very first chapter.
                if ($b === 1 && $c === 1 && $chapter->quizQuestions()->count() === 0) {
                    $chapter->quizQuestions()->createMany([
                        [
                            'question_text' => 'Who is the main character of the story?',
                            'choices' => ['The dog', 'The boy', 'The teacher', 'The bird'],
                            'correct_answer' => 'The boy',
                        ],
                        [
                            'question_text' => 'Where does the story take place?',
                            'choices' => ['In a school', 'In a forest', 'At the beach', 'In a market'],
                            'correct_answer' => 'In a school',
                        ],
                    ]);
                }
            }
        }

        // ---- Sample gamification badges ----
        $badges = [
            ['name' => 'First Steps', 'icon' => '👣', 'description' => 'Completed your very first chapter.', 'criteria' => 'Finish 1 chapter', 'points' => 10],
            ['name' => 'Bookworm', 'icon' => '🐛', 'description' => 'Finished a whole book.', 'criteria' => 'Complete all 4 chapters of a book', 'points' => 50],
            ['name' => 'Clear Speaker', 'icon' => '🎤', 'description' => 'Great pronunciation score.', 'criteria' => 'Score 90%+ on a pronunciation activity', 'points' => 30],
            ['name' => 'Quiz Master', 'icon' => '🧠', 'description' => 'Aced a chapter quiz.', 'criteria' => 'Get a perfect quiz score', 'points' => 20],
            ['name' => 'Reading Star', 'icon' => '⭐', 'description' => 'Finished all six books!', 'criteria' => 'Complete every book', 'points' => 100],
        ];

        foreach ($badges as $badge) {
            Badge::firstOrCreate(['name' => $badge['name']], $badge);
        }

        // ---- Assign books to the demo students so they have a reading journey ----
        $firstThreeBooks = Book::orderBy('sequence')->take(3)->pluck('id');
        $firstTwoBooks = Book::orderBy('sequence')->take(2)->pluck('id');

        $assign = fn (Student $student, $bookIds) => $student->books()->syncWithoutDetaching(
            $bookIds->mapWithKeys(fn ($bookId) => [$bookId => ['assigned_at' => now()]])->all()
        );

        $assign($juan, $firstThreeBooks);
        $assign($maria, $firstTwoBooks);
    }
}
