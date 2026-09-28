<?php

namespace App\Enums;

/**
 * The answer shapes a form can ask for.
 *
 * Each case owns its own validation and its own storage decision, so adding a
 * type is one case here plus one branch in the fill view — nothing has to be
 * remembered in three places.
 */
enum FormFieldType: string
{
    case ShortText = 'short_text';
    case LongText = 'long_text';
    case Number = 'number';
    case YesNo = 'yes_no';
    case Picture = 'picture';

    public function label(): string
    {
        return match ($this) {
            self::ShortText => 'Short text',
            self::LongText => 'Long text',
            self::Number => 'Number',
            self::YesNo => 'Yes or no',
            self::Picture => 'Pictures',
        };
    }

    /** Shown under the type in the builder so the author knows what they are asking for. */
    public function hint(): string
    {
        return match ($this) {
            self::ShortText => 'One line — a name, a reference, a location.',
            self::LongText => 'A paragraph or several.',
            self::Number => 'Digits only. Decimals and negatives are allowed.',
            self::YesNo => 'A single yes or no.',
            self::Picture => 'Up to '.self::MAX_PICTURES.' images.',
        };
    }

    /**
     * Pictures are stored as files, everything else as text in the answer row.
     * The distinction decides which half of the submit path an answer takes.
     */
    public function isFile(): bool
    {
        return $this === self::Picture;
    }

    public const MAX_PICTURES = 5;

    /** Bound on a stored text answer, so one submission cannot be a novel. */
    public function maxLength(): int
    {
        return match ($this) {
            self::LongText => 5000,
            default => 255,
        };
    }

    /**
     * Validation for one answer to a field of this type.
     *
     * `required` is deliberately handled by the caller rather than baked in
     * here: the picture path validates a file array, and "required" means
     * something different for a file than for a string.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::ShortText => ['string', 'max:'.$this->maxLength()],
            self::LongText => ['string', 'max:'.$this->maxLength()],
            self::Number => ['numeric'],
            self::YesNo => ['in:yes,no'],
            self::Picture => ['array', 'max:'.self::MAX_PICTURES],
        };
    }

    /** @return list<self> */
    public static function selectable(): array
    {
        return [self::ShortText, self::LongText, self::Number, self::YesNo, self::Picture];
    }
}
