<?php

namespace App\Rules;

use App\Mcp\PluginRepo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Reject agent names whose plugin-repo slug would collide with a
 * reserved repo path — the public fleet package's derived name shadows
 * any per-agent clone URL that maps to it.
 */
class UnreservedAgentName implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Str::slug((string) $value).'.git' === app(PluginRepo::class)->publicRepoName()) {
            $fail('This name is reserved by the fleet plugin repo.');
        }
    }
}
