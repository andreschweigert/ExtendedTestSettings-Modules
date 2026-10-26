<?php

/**
 * ExamChecker Token - sessiongebundener Token für den AJAX-Endpunkt
 * Part of ExtendedTestSettings Plugin
 *
 * Der Handler bettet den Token in die Seite ein, die GUI prüft ihn (Regel R4).
 * Eigene Klasse, weil beide Seiten denselben Sessionschlüssel brauchen und in
 * unterschiedlichen Kontexten geladen werden.
 */

class ilExamCheckerToken
{
    private const SESSION_KEY = 'examchecker_csrf';

    public static function get(): string
    {
        $token = (string) ilSession::get(self::SESSION_KEY);

        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            ilSession::set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public static function isValid(?string $candidate): bool
    {
        return $candidate !== null && hash_equals(self::get(), $candidate);
    }
}
