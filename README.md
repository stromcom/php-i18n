# stromcom/php-i18n

Translator client + AST scanner for PHP / Twig / XSLT sources. Connects an application
to the self-hosted translator.

**Zero regexes on sources.** PHP via the `nikic/php-parser` AST, Twig via a custom
`Twig\NodeVisitorInterface` plugged into `Twig\NodeTraverser`, XSLT via
`DOMDocument` + `DOMXPath` (element variant — XPath inside a `select=` expression is still TODO).

## Architecture

| Tier | Classes | When it runs |
|---|---|---|
| **Runtime** | `Translator`, `BundleLoader`, `LocaleResolver`, `LocaleMiddleware`, `LocaleContext`, `TwigI18nExtension`, `MissingKeyPolicy` | In Lambda / per request. Prefers `build/locales/<locale>.cache.php` (OPcache hot path) with a fallback to `<locale>.json`. |
| **Scan** | `PhpScanner`, `TwigScanner`, `XsltScanner`, `ScannerPipeline`, AST visitors | Buildtime — CI or `composer i18n:sync`. |
| **Build** | `TranslatorClient`, `BundleFetcher`, `KeySync`, `EtagStore` | Buildtime — HTTP communication with the translator. |
| **Console** | `SyncCommand`, `FetchCommand`, `ScanCommand`, `StatusCommand` | Buildtime — CLI entry points. |

## Use in a consumer

### DI registration

```php
// config/dependencies.php
use Stromcom\I18n\Config\I18nConfig;
use Stromcom\I18n\Config\I18nServiceProvider;
use Stromcom\I18n\Runtime\MissingKeyPolicy;

return array_merge(I18nServiceProvider::definitions(), [
    I18nConfig::class => static fn () => new I18nConfig(
        projectId: 'auth-stromcom-cz',
        token:    (string) ($_ENV['I18N_TOKEN'] ?? ''),
        baseUrl:  'https://translator.stromcom.cz',
        sourceLocale: 'en',
        targetLocales: ['cs', 'en', 'de', 'sk'],
        fallbackLocale: 'en',
        bundlesDir: dirname(__DIR__) . '/build/locales',
        scanPaths: [dirname(__DIR__) . '/src', dirname(__DIR__) . '/templates'],
        missingKeyPolicy: MissingKeyPolicy::LogAndFallback,
        isDevelop: false,
    ),
    // … your own DI definitions …
]);
```

### Slim middleware pipeline

```php
$app->add(\Stromcom\I18n\Runtime\LocaleMiddleware::class);
// LocaleMiddleware must run after the session and before the route handler.
```

### Twig environment

```php
$twig->addExtension($container->get(\Stromcom\I18n\Runtime\TwigI18nExtension::class));
```

### Symfony Console

```php
foreach (\Stromcom\I18n\Config\I18nServiceProvider::consoleCommands() as $cmd) {
    $app->addCommand($container->get($cmd));
}
```

## Use in the application

```twig
{# templates/login.twig #}
<button type="submit">{{ t('login.form.submit', 'Sign in') }}</button>

{# ICU plurals (requires ext-intl) #}
<p>{{ t('cart.itemCount', '{count, plural, one {# item} other {# items}}', { count: itemCount }) }}</p>

{# language switcher #}
<select>{% for loc in available_locales %}<option {% if loc == current_locale %}selected{% endif %}>{{ loc }}</option>{% endfor %}</select>
```

```php
// In a handler / domain service
$msg = $this->translator->trans('email.password_reset.subject', 'Reset your password');

// With ICU values
$msg = $this->translator->trans('admin.users.deleted', '{count, plural, one {# user deleted} other {# users deleted}}', ['count' => $n]);

// With a note (3rd positional / named arg `note:`) — the scanner extracts it as metadata
// for translators, the runtime ignores it:
$msg = $this->translator->trans('signup.title', 'Sign up', note: 'Page heading');
```

### XSLT — two-pass renderer

The consumer calls a single method; the package runs the XSLT transformation plus
post-processing of `<i18n:t/>` elements. AVTs in attributes (`count="{$total}"`) are
evaluated in the first pass, and attributes with concrete values then feed into
`MessageFormatter`:

```php
$renderer = $container->get(\Stromcom\I18n\Runtime\XsltRenderer::class);
$html = $renderer->render(
    xslPath: __DIR__ . '/templates/product.xsl',
    data: $xmlSource,           // string or DOMDocument
    locale: 'cs',               // optional, default = LocaleContext::get()
    xsltParams: ['user' => 'Petr'],   // <xsl:param> values
    outputFormat: null,         // null = auto from <xsl:output method>, otherwise 'html'|'xml'|'text'
);
```

In the XSL template:

```xml
<xsl:stylesheet version="1.0"
                xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
                xmlns:i18n="https://stromcom.cz/i18n"
                exclude-result-prefixes="i18n">
    <xsl:output method="xml" encoding="UTF-8"/>
    <xsl:template match="/">
        <h1><i18n:t key="page.title" default="Welcome" note="Homepage heading"/></h1>

        <!-- ICU plural with a dynamic value from the data -->
        <p><i18n:t key="cart.count"
                   default="{{count, plural, one {{# item}} other {{# items}}}}"
                   count="{data/@items}"/></p>
    </xsl:template>
</xsl:stylesheet>
```
[code-analysis.yml](.github/workflows/code-analysis.yml)
**Important — escaping ICU placeholders in XSL attributes:**

XSLT 1.0 evaluates `{...}` in attributes of literal result elements as Attribute
Value Templates (AVTs) — XPath expressions. ICU placeholders such as `{name}` or
`{count, plural, ...}` therefore **must be doubled to `{{name}}`** / `{{count, ...}}`.
This is the XSLT 1.0 standard.

A safer alternative when the ICU template has too many curly braces to escape: use
`<xsl:attribute>`, whose text content is **not** an AVT:

```xml
<i18n:t key="greet">
    <xsl:attribute name="default">Hello {name}</xsl:attribute>
    <xsl:attribute name="name"><xsl:value-of select="$user_name"/></xsl:attribute>
</i18n:t>
```

**Rules for `<i18n:t/>` attributes:**

| Attribute | Meaning |
|---|---|
| `key` | Key identifier (required) |
| `default` | Source text — ICU template (required) |
| `note` | Metadata for translators — ignored by the runtime |
| others | ICU `MessageFormatter` params (`{paramName}` in default) |

An element without `key` or `default` → removed from the output (warning in the scanner if it passes the scan).

## CLI

```bash
composer i18n:scan           # Debug dump of discovered keys (local only)
composer i18n:sync           # Scan + POST to /keys/sync (idempotent UPSERT)
composer i18n:fetch          # GET published bundles → build/locales/
composer i18n:fetch --draft  # GET draft bundles (for local dev)
composer i18n:fetch --locale=cs   # A single locale only
composer i18n:status         # Coverage report (how many keys translated per locale)
```

## Quality

```bash
composer install       # inside packages/stromcom-i18n/
composer test          # PHPUnit (28 tests, AST scanners, runtime, BundleLoader)
composer stan          # PHPStan level max + strict-rules → 0 errors
```
Then in consumers' `composer.json`:

```diff
"repositories": [
-    { "type": "path", "url": "packages/stromcom-i18n", "options": { "symlink": true } }
+    { "type": "vcs", "url": "https://github.com/stromcom/php-i18n.git" }
],
"require": {
-    "stromcom/php-i18n": "@dev",
+    "stromcom/php-i18n": "^0.1",
}
```

## What the package does not do

- **JavaScript / React** — will be a separate npm package `@stromcom/i18n` (different repo). AST parsing JS from PHP is hell, and the frontend needs its own runtime helper anyway.
- **The XPath function `i18n:t('key', 'default')` inside a `select=` attribute** — requires an XPath parser and, on top of that, does not handle ICU plurals as elegantly as the element-only variant with attributes + AVTs. The two-pass renderer (XSLT + DOM post-processor) fully replaces that need.
- **Runtime fetch from the translator** — bundles must be retrieved during the CI build (`composer i18n:fetch`) and packed into the deploy artifact. The runtime only reads from disk.

## On-disk bundle format

`i18n:fetch` writes **two files** per locale:

| File | Purpose | Who reads it |
|---|---|---|
| `build/locales/<locale>.json` | Source of truth, raw response from the translator (wrapped `{version, locale, translations: {...}}`) | `BundleLoader` as a fallback, debugging |
| `build/locales/<locale>.cache.php` | `<?php return [flat-map]` via `var_export` | OPcache hot path — `require` caches bytecode in shared memory, no `json_decode` |

`BundleLoader` tries `.cache.php` first **only if its mtime is ≥ the JSON**. After `i18n:fetch`
the mtimes are synced (via `touch()`). If someone manually edits the JSON (mtime > PHP cache),
the loader detects it and falls back to JSON — no stale cache.

For **Lambda** (singleton `BundleLoader` + in-memory cache) the difference is 0 — the bundle is
parsed once per cold start. For **PHP-FPM hosting** OPcache is a win: bytecode is shared between
workers, 0 parsing after the first request any worker made.
