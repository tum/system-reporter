<?php

namespace Tum\SystemReporter\Collectors;

use Tum\SystemReporter\Support\ErrorBag;

/**
 * Installierte Produktionsabhängigkeiten (Composer, npm) für den Abgleich
 * mit bekannten Schwachstellen im Portal. Nur Name und Version — keine
 * Pfade, Quellen oder Hashes.
 *
 * Composer: vendor/composer/installed.json (was wirklich installiert ist),
 * ohne dev-Pakete. npm: package-lock.json, ohne dev-Abhängigkeiten; das
 * sind bei Laravel-Apps die im Frontend-Bundle ausgelieferten Bibliotheken.
 */
class PackageCollector
{
    public const MAX_PER_ECOSYSTEM = 3000;

    public function __construct(private string $basePath) {}

    /**
     * @return array<string, list<array{name: string, version: string}>>
     */
    public function collect(ErrorBag $errors): array
    {
        return array_filter([
            'composer' => $this->composer($errors),
            'npm' => $this->npm($errors),
        ], fn (array $list) => $list !== []);
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private function composer(ErrorBag $errors): array
    {
        $data = $this->readJson('vendor/composer/installed.json', 'packages.composer', $errors);
        if ($data === null) {
            return [];
        }

        // Composer 2: {"packages": [...], "dev-package-names": [...]}; Composer 1: [...]
        $packages = array_is_list($data) ? $data : (array) ($data['packages'] ?? []);
        $dev = array_flip(array_map('strtolower', array_filter((array) ($data['dev-package-names'] ?? []), 'is_string')));

        $result = [];
        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['version'] ?? null)) {
                continue;
            }
            if (isset($dev[strtolower($package['name'])])) {
                continue;
            }
            $result[] = ['name' => $package['name'], 'version' => $package['version']];
        }

        return self::limit($result);
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private function npm(ErrorBag $errors): array
    {
        $data = $this->readJson('package-lock.json', 'packages.npm', $errors);
        if ($data === null) {
            return [];
        }

        $result = [];
        if (is_array($data['packages'] ?? null)) {
            // lockfileVersion 2/3: {"node_modules/a/node_modules/@s/b": {...}}
            foreach ($data['packages'] as $path => $package) {
                if (! is_string($path) || ! str_contains($path, 'node_modules/') || ! is_array($package)) {
                    continue;
                }
                if (! empty($package['dev']) || ! empty($package['devOptional']) || ! empty($package['link']) || ! is_string($package['version'] ?? null)) {
                    continue;
                }
                $name = is_string($package['name'] ?? null)
                    ? $package['name']
                    : substr($path, strrpos($path, 'node_modules/') + strlen('node_modules/'));
                $result[] = ['name' => $name, 'version' => $package['version']];
            }
        } elseif (is_array($data['dependencies'] ?? null)) {
            // lockfileVersion 1: verschachtelte "dependencies"
            $walk = function (array $deps) use (&$walk, &$result): void {
                foreach ($deps as $name => $package) {
                    if (! is_string($name) || ! is_array($package) || ! empty($package['dev']) || ! is_string($package['version'] ?? null)) {
                        continue;
                    }
                    $result[] = ['name' => $name, 'version' => $package['version']];
                    if (is_array($package['dependencies'] ?? null)) {
                        $walk($package['dependencies']);
                    }
                }
            };
            $walk($data['dependencies']);
        }

        // Dasselbe Paket in derselben Version kann mehrfach verschachtelt sein.
        $unique = [];
        foreach ($result as $package) {
            $unique[strtolower($package['name']).'@'.$package['version']] = $package;
        }

        return self::limit(array_values($unique));
    }

    /**
     * @return array<mixed>|null
     */
    private function readJson(string $file, string $label, ErrorBag $errors): ?array
    {
        $path = $this->basePath.'/'.$file;
        if (! @is_file($path)) {
            return null; // kein Fehler: npm (oder Composer) wird nicht genutzt
        }
        $json = @file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($data)) {
            $errors->add($label, $file.' not readable');

            return null;
        }

        return $data;
    }

    /**
     * Nur prüfbare Versionen: "1.2.3", "v1.2.3", "2.0.0-beta.1". Branch-
     * Stände ("dev-main", "1.x-dev") und Git-/Datei-Quellen
     * ("git+https://…", "file:../x") lassen sich nicht gegen Versionsbereiche
     * abgleichen und machen den Report nur größer.
     */
    public static function isCheckable(string $name, string $version): bool
    {
        return $name !== '' && strlen($name) <= 214 && strlen($version) <= 64
            && preg_match('/^v?\d+(\.\d+)*([-+.][0-9A-Za-z.+-]*)?$/', $version) === 1
            && ! str_ends_with($version, '-dev');
    }

    /**
     * @param  list<array{name: string, version: string}>  $packages
     * @return list<array{name: string, version: string}>
     */
    private static function limit(array $packages): array
    {
        $packages = array_values(array_filter($packages, fn (array $p) => self::isCheckable($p['name'], $p['version'])));
        usort($packages, fn (array $a, array $b) => [$a['name'], $a['version']] <=> [$b['name'], $b['version']]);

        return array_slice($packages, 0, self::MAX_PER_ECOSYSTEM);
    }
}
