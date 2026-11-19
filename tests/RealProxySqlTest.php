<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\CircuitState;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Impl\Breaker\CircuitBreaker;
use Gohany\Rtry\Impl\Breaker\InMemoryCircuitBreakerStore;
use Gohany\Rtry\Impl\Breaker\ServiceFailureVerdict;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * ProxySQL's own errors, from a real ProxySQL 2.7 in front of a real MySQL, read as in 2.4.0:
 * its query timeout (1907, `mysql-default_query_timeout`) and an unreachable backend (9001) get ETIMEDOUT, retry under
 * on=default and open the circuit. Skipped unless RTRY_PROXYSQL_HOST is set, e.g.
 *
 *   docker network create rtry
 *   docker run -d --name rtry-mysql --network rtry -e MYSQL_ROOT_PASSWORD=root mysql:8.0
 *   docker run -d --name rtry-proxysql --network rtry \
 *     -v "$PWD/tests/Support/proxysql.cnf":/etc/proxysql.cnf:ro proxysql/proxysql:2.7.1
 *   docker run --rm --network rtry -e RTRY_PROXYSQL_HOST=rtry-proxysql -v "$PWD":/app -w /app \
 *     <a php:8.2-cli image with pdo_mysql and mysqli> vendor/bin/phpunit --group proxysql
 */
#[Group('proxysql')]
#[RequiresPhpExtension('pdo_mysql')]
#[RequiresPhpExtension('mysqli')]
final class RealProxySqlTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    /** Longer than default_query_timeout (1.5 s). Not SLEEP(): a killed SLEEP returns 1, so ProxySQL sends no error. */
    private const LONG_QUERY = 'SELECT COUNT(*) FROM information_schema.columns a, information_schema.columns b, information_schema.columns c';

    private static function host(): string
    {
        $host = getenv('RTRY_PROXYSQL_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('RTRY_PROXYSQL_HOST is not set');
        }
        return $host;
    }

    private static function caught(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        self::fail('no error');
    }

    /** @return iterable<string, array{\Closure(): void, int}> */
    public static function errors(): iterable
    {
        yield 'pdo query timeout' => [static function (): void {
            (new \PDO('mysql:host=' . self::host() . ';port=6033', 'root', 'root', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]))->query(self::LONG_QUERY);
        }, 1907];
        yield 'mysqli query timeout' => [static function (): void {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            (new \mysqli(self::host(), 'root', 'root', '', 6033))->query(self::LONG_QUERY);
        }, 1907];
        yield 'pdo backend unreachable' => [static function (): void {
            (new \PDO('mysql:host=' . self::host() . ';port=6033', 'down', 'down', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]))->query('SELECT 1');
        }, 9001];
        yield 'mysqli backend unreachable' => [static function (): void {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            (new \mysqli(self::host(), 'down', 'down', '', 6033))->query('SELECT 1');
        }, 9001];
    }

    /** @param \Closure(): void $fn */
    #[DataProvider('errors')]
    public function testProxySqlsOwnErrorRetriesAndOpensTheCircuit(\Closure $fn, int $number): void
    {
        self::host();
        $error = self::caught($fn);
        $code = $error instanceof \PDOException ? ($error->errorInfo[1] ?? null) : $error->getCode();
        self::assertSame($number, $code, $error->getMessage());
        self::assertSame(['ETIMEDOUT'], RuleBasedFailureClassifier::default()->classify($error)->getTags(), $error->getMessage());

        $clock = new FrozenClock(self::T0);
        $sleeper = new RecordingSleeper($clock);
        $result = (new Retry($clock, $sleeper, null, SeededRandom::randomizer(241)))->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0;on=default'));
        self::assertSame([3, GiveUpReason::AttemptsExhausted, [100, 200]], [$result->attempts(), $result->giveUpReason(), $sleeper->sleeps()]);

        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, failureThreshold: 2, windowMs: 10_000, openMs: 5_000);
        $retry = (new Retry($clock, $sleeper, null, SeededRandom::randomizer(241)))->withGuard('db', null, $breaker, new ServiceFailureVerdict());
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=1;d=100;j=0;on=5xx');
        foreach ([1, 2] as $_) {
            $retry->tryOutcome(static fn (): never => throw $error, $policy);
        }
        self::assertSame(CircuitState::Open, $breaker->state('db'));
    }
}
