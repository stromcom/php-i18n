<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

/**
 * What to do when the runtime does not find a key in the bundle:
 *
 * - ThrowInDev: in dev throw MissingTranslationException, otherwise warning + fallback to default
 * - LogAndFallback: always log a warning + return default
 * - Silent: return default without logging
 */
enum MissingKeyPolicy: string
{
    case ThrowInDev = 'throw_in_dev';
    case LogAndFallback = 'log_and_fallback';
    case Silent = 'silent';
}
