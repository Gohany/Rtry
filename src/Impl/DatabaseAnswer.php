<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

/**
 * Whether a thrown error is a database's answer about the values it was sent, by its SQLSTATE:
 * class 21 (cardinality), 22 (data exception), 23 (integrity constraint violation), 42 (syntax
 * error or access rule), 44 (check option), 45 (MySQL's SIGNAL) or P0 (PostgreSQL's RAISE).
 * Such a message echoes what the caller sent ("Duplicate entry 'broken pipe' for key ..."), so
 * its words are never read for transport or outage evidence: anyone who can submit a unique
 * field would otherwise choose them.
 *
 * The SQLSTATE comes from a PDOException's errorInfo or string code, a mysqli_sql_exception's
 * getSqlState(), or PDO's "SQLSTATE[xxxxx]" anywhere in the message (Doctrine DBAL's wrappers
 * carry it there). Any one of them in these classes is enough: a value injected to look like a
 * connection error's SQLSTATE cannot outvote the real one.
 *
 * @internal
 */
final class DatabaseAnswer
{
    private const ANSWER_CLASSES = ['21', '22', '23', '42', '44', '45', 'P0'];

    public static function is(\Throwable $t): bool
    {
        foreach (self::sqlStates($t) as $state) {
            if (in_array(substr($state, 0, 2), self::ANSWER_CLASSES, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> upper-cased */
    private static function sqlStates(\Throwable $t): array
    {
        $states = [];
        if ($t instanceof \PDOException) {
            $info = $t->errorInfo;
            if (is_array($info) && isset($info[0]) && is_string($info[0])) {
                $states[] = $info[0];
            }
            $code = $t->getCode();
            if (is_string($code)) {
                $states[] = $code;
            }
        }
        if (method_exists($t, 'getSqlState')) {
            try {
                $state = $t->getSqlState();
                if (is_string($state)) {
                    $states[] = $state;
                }
            } catch (\Throwable) {
                // no SQLSTATE to go on
            }
        }
        if (preg_match_all('~sqlstate\[([0-9a-z]{5})\]~i', $t->getMessage(), $m)) {
            $states = array_merge($states, $m[1]);
        }
        return array_map('strtoupper', $states);
    }
}
