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
use Gohany\Rtry\Impl\RtryAttemptOutcome;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * MariaDB's own numbers against a real server, with the drivers' own exceptions. Skipped
 * unless RTRY_MARIADB_HOST is set (user root, password RTRY_MARIADB_PASSWORD or "root"), e.g.
 *
 *   docker network create rtry
 *   docker run -d --name rtry-mariadb --network rtry -e MARIADB_ROOT_PASSWORD=root mariadb:11.4
 *   docker run --rm --network rtry -e RTRY_MARIADB_HOST=rtry-mariadb -v "$PWD":/app -w /app \
 *     <a php:8.2-cli image with pdo_mysql and mysqli> vendor/bin/phpunit --group mariadb
 *
 * The database "rtry_probe" is created and dropped.
 */
#[Group('mariadb')]
#[RequiresPhpExtension('pdo_mysql')]
#[RequiresPhpExtension('mysqli')]
final class RealMariaDbTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private static function host(): string
    {
        $host = getenv('RTRY_MARIADB_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('RTRY_MARIADB_HOST is not set');
        }
        return $host;
    }

    private static function password(): string
    {
        $password = getenv('RTRY_MARIADB_PASSWORD');
        return is_string($password) && $password !== '' ? $password : 'root';
    }

    private static function pdo(bool $emulate = true): \PDO
    {
        return new \PDO('mysql:host=' . self::host() . ';dbname=rtry_probe', 'root', self::password(), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => $emulate]);
    }

    private static function mysqli(): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        return new \mysqli(self::host(), 'root', self::password(), 'rtry_probe');
    }

    public static function setUpBeforeClass(): void
    {
        $host = getenv('RTRY_MARIADB_HOST');
        if (!is_string($host) || $host === '' || !extension_loaded('pdo_mysql')) {
            return;
        }
        $root = new \PDO("mysql:host=$host", 'root', self::password(), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $root->exec('DROP DATABASE IF EXISTS rtry_probe; CREATE DATABASE rtry_probe');
        $root->exec('CREATE TABLE rtry_probe.orders (id INT PRIMARY KEY AUTO_INCREMENT, qty INT) ENGINE=InnoDB');
    }

    public static function tearDownAfterClass(): void
    {
        $host = getenv('RTRY_MARIADB_HOST');
        if (is_string($host) && $host !== '' && extension_loaded('pdo_mysql')) {
            (new \PDO("mysql:host=$host", 'root', self::password()))->exec('DROP DATABASE IF EXISTS rtry_probe');
        }
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

    private const TRIGGER_DDL = "CREATE TRIGGER audit_orders BEFORE INSERT ON orders FOR EACH ROW FOLLOWS tenant_acme_audit SET NEW.qty = NEW.qty";

    /** @return iterable<string, array{\Closure(): void}> */
    public static function triggerErrors(): iterable
    {
        yield 'pdo emulated' => [static fn () => self::pdo()->exec(self::TRIGGER_DDL)];
        yield 'pdo native' => [static fn () => self::pdo(false)->exec(self::TRIGGER_DDL)];
        yield 'mysqli' => [static fn () => self::mysqli()->query(self::TRIGGER_DDL)];
    }

    /**
     * Should-fix 1 of PR #9's re-verification at df3d975: MariaDB's 4031 is a DDL answer
     * ("Referenced trigger ... does not exist"), not MySQL's idle disconnect.
     *
     * @param \Closure(): void $fn
     */
    #[DataProvider('triggerErrors')]
    public function testMariaDbs4031RunsOnceAndKeepsTheCircuitClosed(\Closure $fn): void
    {
        self::host();
        $error = self::caught($fn);
        $number = $error instanceof \PDOException ? (int) ($error->errorInfo[1] ?? 0) : (int) $error->getCode();
        self::assertSame(4031, $number, $error->getMessage());
        self::assertSame([], RuleBasedFailureClassifier::default()->classify($error)->getTags(), $error->getMessage());

        $clock = new FrozenClock(self::T0);
        $sleeper = new RecordingSleeper($clock);
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, failureThreshold: 2, windowMs: 10_000, openMs: 5_000);
        $retry = (new Retry($clock, $sleeper, null, SeededRandom::randomizer(242)))->withGuard('db', null, $breaker, new ServiceFailureVerdict());
        foreach (['rtry:a=4;d=100;j=0;on=5xx', 'rtry:a=4;d=100;j=0;on=5xx', 'rtry:a=4;d=100;j=0;on=default'] as $spec) {
            $result = $retry->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec($spec));
            self::assertSame([1, GiveUpReason::NotRetryable], [$result->attempts(), $result->giveUpReason()]);
        }
        self::assertSame([], $sleeper->sleeps());
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    /** @return iterable<string, array{\Closure(): void}> */
    public static function outages(): iterable
    {
        yield 'pdo connection killed' => [static function (): void {
            $victim = self::pdo();
            $id = $victim->query('SELECT CONNECTION_ID()')->fetchColumn();
            self::pdo()->exec("KILL $id");
            $victim->query('SELECT 1');
        }];
        yield 'pdo idle disconnect' => [static function (): void {
            $idle = self::pdo();
            $idle->exec('SET SESSION wait_timeout = 1');
            sleep(3);
            @$idle->query('SELECT 1');
        }];
        yield 'mysqli idle disconnect' => [static function (): void {
            $idle = self::mysqli();
            $idle->query('SET SESSION wait_timeout = 1');
            sleep(3);
            @$idle->query('SELECT 1');
        }];
    }

    /** @param \Closure(): void $fn */
    #[DataProvider('outages')]
    public function testARealOutageStillCounts(\Closure $fn): void
    {
        self::host();
        $error = self::caught($fn);
        $outcome = RtryAttemptOutcome::fromMetadata($error, RuleBasedFailureClassifier::default()->classify($error), self::T0);
        self::assertTrue((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false), $error->getMessage());
    }
}
