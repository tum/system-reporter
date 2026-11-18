<?php

namespace Tum\SystemReporter\Support;

/**
 * Sammelt, was nicht auslesbar war ("os.release: not readable"). Landet
 * als `collect_errors` im Report, damit das Portal "fehlt" von "nicht
 * ermittelbar" unterscheiden kann.
 */
class ErrorBag
{
    /** @var list<string> */
    private array $errors = [];

    public function add(string $key, string $reason): void
    {
        $this->errors[] = mb_substr($key.': '.$reason, 0, 500);
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->errors;
    }
}
