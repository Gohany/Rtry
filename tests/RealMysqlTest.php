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
 * The review's probes against a real MySQL, with the drivers' own exceptions. Skipped unless
 * RTRY_MYSQL_HOST is set (user root, password RTRY_MYSQL_PASSWORD or "root"), e.g.
 *
 *   docker network create rtry
 *   docker run -d --name rtry-mysql --network rtry -e MYSQL_ROOT_PASSWORD=root mysql:8.0
 *   docker run --rm --network rtry -e RTRY_MYSQL_HOST=rtry-mysql -v "$PWD":/app -w /app \
 *     <a php:8.2-cli image with pdo_mysql and mysqli> vendor/bin/phpunit --group mysql
 *
 * The database "rtry_probe" is created and dropped.
 */
#[Group('mysql')]
#[RequiresPhpExtension('pdo_mysql')]
#[RequiresPhpExtension('mysqli')]
final class RealMysqlTest extends TestCase
{
    private const T0 = 1_800_000_000_000;

    private static function host(): string
    {
        $host = getenv('RTRY_MYSQL_HOST');
        if (!is_string($host) || $host === '') {
            self::markTestSkipped('RTRY_MYSQL_HOST is not set');
        }
        return $host;
    }

    private static function password(): string
    {
        $password = getenv('RTRY_MYSQL_PASSWORD');
        return is_string($password) && $password !== '' ? $password : 'root';
    }

    private static function pdo(string $db = 'rtry_probe', string $user = 'root', bool $emulate = true): \PDO
    {
        return new \PDO('mysql:host=' . self::host() . ";dbname=$db", $user, $user === 'root' ? self::password() : 'x', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => $emulate]);
    }

    public static function setUpBeforeClass(): void
    {
        $host = getenv('RTRY_MYSQL_HOST');
        if (!is_string($host) || $host === '' || !extension_loaded('pdo_mysql')) {
            return;
        }
        $root = new \PDO("mysql:host=$host", 'root', self::password(), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $root->exec('DROP DATABASE IF EXISTS rtry_probe; CREATE DATABASE rtry_probe');
        $root->exec('CREATE TABLE rtry_probe.t (id INT PRIMARY KEY AUTO_INCREMENT, qty INT, d DATE, name VARCHAR(64) UNIQUE) ENGINE=InnoDB');
        $root->exec("INSERT INTO rtry_probe.t (qty, name) VALUES (1, 'timed out')");
    }

    public static function tearDownAfterClass(): void
    {
        $host = getenv('RTRY_MYSQL_HOST');
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

    /** @return iterable<string, array{\Closure(): void}> */
    public static function valueErrors(): iterable
    {
        $v = 'timed out';
        foreach ([true, false] as $emulate) {
            $mode = $emulate ? 'emulated' : 'native';
            yield "pdo 1366 $mode" => [static fn () => self::pdo(emulate: $emulate)->prepare('INSERT INTO t (qty) VALUES (?)')->execute([$v])];
            yield "pdo 1411 $mode" => [static fn () => self::pdo(emulate: $emulate)->prepare('SELECT UUID_TO_BIN(?)')->execute([$v])];
            yield "pdo 1298 $mode" => [static fn () => self::pdo(emulate: $emulate)->prepare('SET time_zone = ?')->execute([$v])];
            yield "pdo 1062 $mode" => [static fn () => self::pdo(emulate: $emulate)->prepare('INSERT INTO t (name) VALUES (?)')->execute([$v])];
        }
        yield 'pdo 1525 emulated' => [static fn () => self::pdo()->prepare('SELECT * FROM t WHERE d = ?')->execute([$v])];
        yield 'pdo connect 1049' => [static fn () => self::pdo("tenant_$v")];
        yield 'pdo connect 1045' => [static fn () => self::pdo('rtry_probe', $v)];
        yield 'mysqli 1366' => [static function () use ($v): void {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $m = new \mysqli(self::host(), 'root', self::password(), 'rtry_probe'); // kept: closing it would make the execute a 2006
            $s = $m->prepare('INSERT INTO t (qty) VALUES (?)');
            $s->bind_param('s', $v);
            $s->execute();
        }];
        yield 'mysqli connect 1049' => [static function () use ($v): void {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            new \mysqli(self::host(), 'root', self::password(), "tenant_$v");
        }];
    }

    /** @param \Closure(): void $fn */
    #[DataProvider('valueErrors')]
    public function testARealValueErrorRunsOnceAndKeepsTheCircuitClosed(\Closure $fn): void
    {
        self::host();
        $error = self::caught($fn);
        self::assertSame([], RuleBasedFailureClassifier::default()->classify($error)->getTags(), $error->getMessage());

        $clock = new FrozenClock(self::T0);
        $sleeper = new RecordingSleeper($clock);
        $breaker = new CircuitBreaker(new InMemoryCircuitBreakerStore($clock), $clock, failureThreshold: 2, windowMs: 10_000, openMs: 5_000);
        $retry = (new Retry($clock, $sleeper, null, SeededRandom::randomizer(241)))->withGuard('db', null, $breaker, new ServiceFailureVerdict());
        foreach (['rtry:a=4;d=100;j=0;on=5xx', 'rtry:a=4;d=100;j=0;on=default', 'rtry:a=4;d=100;j=0;on=default'] as $spec) {
            $result = $retry->tryOutcome(static fn (): never => throw $error, (new RtryPolicyFactory())->fromSpec($spec));
            self::assertSame([1, GiveUpReason::NotRetryable], [$result->attempts(), $result->giveUpReason()]);
        }
        self::assertSame([], $sleeper->sleeps());
        self::assertSame(CircuitState::Closed, $breaker->state('db'));
    }

    public function testARealLockWaitTimeoutStillRetriesButIsNotAnOutage(): void
    {
        self::host();
        $a = self::pdo();
        $b = self::pdo();
        $a->exec('SET innodb_lock_wait_timeout = 1');
        $b->exec('SET innodb_lock_wait_timeout = 1');
        $a->beginTransaction();
        $a->query('SELECT * FROM t WHERE id = 1 FOR UPDATE')->fetchAll();
        $b->beginTransaction();
        $error = self::caught(static fn () => $b->query('SELECT * FROM t WHERE id = 1 FOR UPDATE')->fetchAll());
        $a->rollBack();

        $tags = RuleBasedFailureClassifier::default()->classify($error)->getTags();
        sort($tags);
        self::assertSame(['DEADLOCK', 'ETIMEDOUT'], $tags);
        $outcome = RtryAttemptOutcome::fromMetadata($error, RuleBasedFailureClassifier::default()->classify($error), self::T0);
        self::assertFalse((new ServiceFailureVerdict(countRetryable: false))->isServiceFailure($outcome, false));
    }

    /** @return iterable<string, array{\Closure(): void}> */
    public static function outages(): iterable
    {
        yield 'pdo connect refused' => [static fn () => new \PDO('mysql:host=' . self::host() . ';port=1', 'root', 'x', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION])];
        yield 'pdo connection killed' => [static function (): void {
            $victim = self::pdo();
            $id = $victim->query('SELECT CONNECTION_ID()')->fetchColumn();
            self::pdo()->exec("KILL $id");
            $victim->query('SELECT 1');
        }];
        yield 'mysqli connect refused' => [static function (): void {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            new \mysqli(self::host(), 'root', 'x', 'rtry_probe', 1);
        }];
        yield 'pdo idle disconnect (2006 on PHP 8.2, 4031 on 8.5)' => [static function (): void {
            $idle = self::pdo();
            $idle->exec('SET SESSION wait_timeout = 1');
            sleep(3);
            @$idle->query('SELECT 1'); // PHP 8.2's mysqlnd also warns "Packets out of order"
        }];
        yield 'mysqli idle disconnect' => [static function (): void {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $idle = new \mysqli(self::host(), 'root', self::password(), 'rtry_probe');
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
