<?php

namespace Tum\SystemReporter\Support;

/**
 * Führt ein Shell-Kommando aus, sofern der Host das erlaubt. Eigene Klasse,
 * damit Tests sie ersetzen können.
 */
class CommandRunner
{
    public function available(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }

    /**
     * @return array{exit: int, output: list<string>}|null null, wenn exec gesperrt ist
     */
    public function run(string $command): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $output = [];
        $exit = 0;
        exec($command.' 2>/dev/null', $output, $exit);

        return ['exit' => $exit, 'output' => $output];
    }
}
