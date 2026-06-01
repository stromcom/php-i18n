<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

interface TranslatorInterface
{
    /**
     * @param string                       $key          Identifier (dot-notation), e.g. `login.form.email_label`.
     * @param string                       $default      Default text in the source locale. Fallback if the key is missing from the bundle.
     * @param array<string, scalar|\Stringable> $params  ICU placeholder values (`{name}`, `{count, plural, …}`).
     * @param string|null                  $locale       Override the active locale (otherwise `LocaleContext::get()`).
     */
    public function trans(string $key, string $default, array $params = [], ?string $locale = null): string;
}
