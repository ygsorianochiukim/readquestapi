<?php

namespace App\Domain\Theme;

/**
 * The look a book (or one of its chapters) is read in.
 *
 * The student app owns the artwork — colours, scenery, the little things that
 * float past — so all the API keeps is which one was picked. A chapter with no
 * theme of its own is read in its book's.
 */
final class Themes
{
    public const KEYS = [
        'jungle',
        'party',
        'halloween',
        'ocean',
        'space',
        'candy',
        'winter',
        'farm',
        'dinosaur',
        'fairytale',
    ];

    /** Validation rule for an optional theme field. */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::KEYS);
    }
}
