<?php

declare(strict_types=1);

namespace Stromcom\I18n\Runtime;

use Stromcom\I18n\Config\I18nConfig;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Twig integration:
 *
 *   {{ t('login.form.submit', 'Sign in') }}
 *   {{ t('cart.itemCount', '{count, plural, one {# item} other {# items}}', { count: n }) }}
 *
 * Globals:
 *   {{ current_locale }}      active locale
 *   {{ available_locales }}   list of all supported locales (for the language switcher)
 *
 * `locale_switch_url(locale)` returns the current page's same path with the query
 * `?locale=xx` — suitable for `<a href>` in a dropdown.
 */
final class TwigI18nExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LocaleContext $context,
        private readonly I18nConfig $config,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->trans(...)),
            new TwigFunction('locale_switch_url', $this->localeSwitchUrl(...)),
        ];
    }

    public function getGlobals(): array
    {
        return [
            'current_locale'    => $this->context->get(),
            'available_locales' => $this->config->targetLocales,
        ];
    }

    /**
     * @param array<string, scalar|\Stringable> $params
     */
    public function trans(string $key, string $default, array $params = []): string
    {
        return $this->translator->trans($key, $default, $params);
    }

    /**
     * Naive implementation — if the consumer needs something more extensive (e.g.
     * stripping other query parameters), they can override it. It builds the URL
     * from `$_SERVER` because Twig globals have no access to the request; the
     * consumer can override the function by registering their own TwigFunction via
     * `addFunction` after loading this extension.
     */
    public function localeSwitchUrl(string $locale): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = is_string($requestUri) ? $requestUri : '/';
        $split = explode('?', $path, 2);
        $base = $split[0];
        $qs = $split[1] ?? '';
        parse_str($qs, $params);
        $params['locale'] = $locale;
        return $base . '?' . http_build_query($params);
    }
}
