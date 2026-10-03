<?php

/**
 * A teacher fills a chapter's pages without a scan: by typing a page's
 * sentences, or by having the chapter's story text cut into pages.
 */
it('adds a page the teacher typed to the chapter', function () {
    $teacher = makeTeacher();
    $book = makeBook(2);
    $chapter = $book->chapters()->orderBy('chapter_number')->first();

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/books/{$book->id}/pages/text", [
            'chapter_id' => $chapter->id,
            'text' => "  Nash brings a bird nest.\nHe puts it on his desk.  ",
        ])
        ->assertCreated()
        ->assertJsonPath('data.chapter_id', $chapter->id)
        ->assertJsonPath('data.text', "Nash brings a bird nest.\nHe puts it on his desk.")
        ->assertJsonPath('data.image_url', null);
});

it('will not put a typed page in another book\'s chapter', function () {
    $teacher = makeTeacher();
    $book = makeBook(1);
    $other = makeBook(1)->chapters()->first();

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/books/{$book->id}/pages/text", ['chapter_id' => $other->id, 'text' => 'Hi.'])
        ->assertNotFound();
});

it('cuts the story text into pages of a few sentences each', function () {
    $teacher = makeTeacher();
    $chapter = makeBook(1)->chapters()->first();
    $chapter->update(['story_text' => 'It is Monday in Mrs. Post’s class. We must bring a solid. I put my vest on the desk. '
        .'“What is it?” Mrs. Post asks. I say, “It is a solid!”']);

    $response = $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/chapters/{$chapter->id}/pages/generate", ['sentences_per_page' => 2])
        ->assertCreated();

    expect($response->json('data'))->toHaveCount(3)
        ->and($response->json('data.0.text'))->toBe("It is Monday in Mrs. Post’s class.\nWe must bring a solid.")
        ->and($response->json('data.2.text'))->toBe('I say, “It is a solid!”')
        ->and($chapter->pages()->count())->toBe(3);
});

it('does not generate over pages the chapter already has', function () {
    $teacher = makeTeacher();
    $book = makeBook(1);
    $chapter = $book->chapters()->first();
    $chapter->update(['story_text' => 'One. Two.']);
    $book->pages()->create(['chapter_id' => $chapter->id, 'page_number' => 1, 'text' => 'Already here.']);

    $this->withHeaders(teacherHeaders($teacher))
        ->postJson("/api/v1/chapters/{$chapter->id}/pages/generate")
        ->assertStatus(422);
});
