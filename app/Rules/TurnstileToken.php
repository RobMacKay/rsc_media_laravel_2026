<?php

namespace App\Rules;

use App\Support\Turnstile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Request;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuse a form that cannot show it was filled in by a person.
 *
 * Sits on the public forms only — anything behind a login is already past the
 * point a bot is worth stopping.
 */
class TurnstileToken implements ValidationRule
{
    /**
     * What someone who fails the check is told.
     *
     * Deliberately vague about why: a bot author gets nothing useful, and a
     * person who has tripped a false positive is told the thing that fixes it.
     */
    public const MESSAGE = 'We could not tell that you are a person. Refresh the page and try again.';

    public function __construct(private ?Turnstile $turnstile = null) {}

    /**
     * Get the full rule set for a token field.
     *
     * `required` is part of it because a rule object is skipped entirely when
     * the value is empty — on its own it would wave through a post that simply
     * left the token off, which is exactly what a bot would do. With no keys
     * configured there is nothing to require, and the form behaves as it did
     * before any of this existed.
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return app(Turnstile::class)->enabled() ? ['required', new self] : [];
    }

    /**
     * Get the message to hang on the `required` half of the rule set.
     *
     * @return array<string, string>
     */
    public static function messages(string $attribute): array
    {
        return [$attribute.'.required' => __(self::MESSAGE)];
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $turnstile = $this->turnstile ?? app(Turnstile::class);

        if ($turnstile->verify(is_string($value) ? $value : null, Request::ip())) {
            return;
        }

        $fail(__(self::MESSAGE));
    }
}
