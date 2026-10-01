<?php

namespace App\Exceptions;

use InvalidArgumentException;

class InvalidTimeException extends InvalidArgumentException
{
    public const EMPTY = 'time_empty';

    public const LETTERS = 'time_letters';

    public const BAD_SEPARATOR = 'time_bad_separator';

    public const MISSING_MINUTES = 'time_missing_minutes';

    public const HOUR_RANGE = 'time_hour_range';

    public const MINUTE_RANGE = 'time_minute_range';

    public const MINUTE_DIGITS = 'time_minute_digits';

    public const FORMAT = 'time_format';

    /**
     * @param  string|null  $suggestion  HH:MM the user most likely meant, when the digits are otherwise valid
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $suggestion = null,
    ) {
        parent::__construct($reason);
    }
}
