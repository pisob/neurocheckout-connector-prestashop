<?php

namespace NeuroCheckout\Compatibility;

/** Kept parseable before Composer on PHP 7.1+ (legacy PrestaShop 1.7 hosts). */
final class RuntimeRequirements
{
    public static function errors(
        string $prestashopVersion,
        string $phpVersion,
        array $extensions,
        bool $autoloadAvailable,
        string $language = 'en'
    ): array {
        $fr = strtolower(substr($language, 0, 2)) === 'fr';
        $errors = [];
        if (version_compare($prestashopVersion, '1.7.7.0', '<')) {
            $errors[] = $fr
                ? 'NeuroCheckout nécessite PrestaShop 1.7.7 ou plus récent.'
                : 'NeuroCheckout requires PrestaShop 1.7.7 or newer.';
        }
        if (version_compare($phpVersion, '7.4.0', '<')) {
            $errors[] = $fr
                ? 'NeuroCheckout nécessite PHP 7.4 minimum. Version détectée : ' . $phpVersion . '. Pour PrestaShop 1.7.8, utilisez PHP 7.4 pour le site et le cron.'
                : 'NeuroCheckout requires PHP 7.4 or newer. Detected: ' . $phpVersion . '. For PrestaShop 1.7.8, use PHP 7.4 for both the website and cron.';
        }
        foreach (['curl', 'openssl'] as $extension) {
            if (!in_array($extension, $extensions, true)) {
                $errors[] = $fr
                    ? 'Extension PHP requise manquante : ' . $extension . '.'
                    : 'Required PHP extension is missing: ' . $extension . '.';
            }
        }
        if (!$autoloadAvailable) {
            $errors[] = $fr
                ? 'Le module est incomplet (vendor/autoload.php absent). Installez le ZIP du module depuis les releases officielles, pas « Code > Download ZIP ».'
                : 'The module package is incomplete (vendor/autoload.php missing). Install the module ZIP from the official releases, not "Code > Download ZIP".';
        }
        return $errors;
    }
}
