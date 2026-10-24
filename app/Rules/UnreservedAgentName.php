<?php

namespace App\Rules;

use App\Mcp\PluginRepo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Reject agent names that would collide with a reserved value: the
 * public fleet package's derived repo name, and `owner` — the magic
 * author stamp the knowledge graph writes for the operator's own edits,
 * which an agent name must never shadow or absorb.
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

        if (Str::lower(trim((string) $value)) === 'owner') {
            $fail('This name is reserved for the operator.');
        }
    }
}
