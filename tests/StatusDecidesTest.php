<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\AttemptContextInterface;
use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Impl\ErrorResponse;
use Gohany\Rtry\Impl\ErrorResponseLocator;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\Rules\InstanceOfRule;
use Gohany\Rtry\Impl\Rules\RateLimitBackoffRule;
use Gohany\Rtry\Impl\Rules\ResponseStatusRule;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Tests\Support\HttpFailure;
use Gohany\Rtry\Tests\Support\SymfonyShapedFailure;
use PHPUnit\Framework\TestCase;

/**
 * An error that carries an HTTP error status (400-599) is judged by that status alone: words
 * in its message and rate-limit headers on a 4xx do not make it retryable. Transport errors,
 * which carry no HTTP status, keep their tags. And a client that is not PSR-7 can hand Rtry
 * its status and headers through an ErrorResponseReaderInterface.
 *
 * End to end: parse a real rtry: string, run Retry against a failing operation, record the
 * sleeps it took.
 */
final class StatusDecidesTest extends TestCase
{
    private FrozenClock $clock;
    private RecordingSleeper $sleeper;
    private Retry $retry;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->sleeper = new RecordingSleeper($this->clock);
        $this->retry = new Retry($this->clock, $this->sleeper, null, SeededRandom::randomizer(1118));
    }

    /** Throws $error on the first $failures attempts, then returns 'ok'. */
    private static function failing(int $failures, \Throwable $error, ?array &$seen = null): \Closure
    {
        $seen = [];
        return static function (AttemptContextInterface $attempt) use ($failures, $error, &$seen): string {
            $seen[] = $attempt->attemptNumber();
            if ($attempt->attemptNumber() <= $failures) {
                throw $error;
            }
            return 'ok';
        };
    }

    // ---- the classifier --------------------------------------------------------------------

    public function testA404WhoseMessageSaysTimeoutGetsNoTransportTag(): void
    {
        $meta = RuleBasedFailureClassifier::default()->classify(
            HttpFailure::status(404, [], 'Client error: GET https://api.example.test/timeout/settings resulted in a 404')
        );

        self::assertSame(404, $meta->getStatusCode());
        self::assertSame([], $meta->getTags());
    }

    public function testAnExceptionCodeIn400To599IsAStatusButDoesNotSilenceTransportTags(): void
    {
        // A code alone is not proof of HTTP (SMTP 421, a deadlock coded 409): it still matches
        // on=404, but only a status read from a response decides alone.
        $meta = RuleBasedFailureClassifier::default()->classify(
            new \RuntimeException('HTTP/2 404 returned for "https://api.example.test/connection refused/x".', 404)
        );

        self::assertSame(404, $meta->getStatusCode());
        self::assertSame(['ECONNREFUSED'], $meta->getTags());
    }

    public function testRateLimitHeadersOnAnOrdinary4xxDoNotTagRateLimited(): void
    {
        // Many APIs send X-RateLimit-Reset on every response, 4xx included.
        $meta = RuleBasedFailureClassifier::default()->classify(
            HttpFailure::status(404, ['X-RateLimit-Reset' => '30'])
        );

        self::assertSame(404, $meta->getStatusCode());
        self::assertNotContains('RATE_LIMITED', $meta->getTags());
    }

    public function testA429OrA503WithRetryAfterIsStillTaggedRateLimited(): void
    {
        foreach ([429, 503] as $status) {
            $meta = RuleBasedFailureClassifier::default()->classify(HttpFailure::status($status, ['Retry-After' => '2']));
            self::assertSame($status, $meta->getStatusCode());
            self::assertContains('RATE_LIMITED', $meta->getTags(), "status $status");
            self::assertSame(2000, $meta->getMinNextDelayMs());
        }
    }

    public function testATransportErrorWithAnErrnoCodeKeepsItsTags(): void
    {
        // 111 is ECONNREFUSED and 110 ETIMEDOUT on Linux: in 100-599, but not an HTTP status.
        $refused = RuleBasedFailureClassifier::default()->classify(new \RuntimeException('Connection refused [tcp://10.0.0.5:6379]', 111));
        $timedOut = RuleBasedFailureClassifier::default()->classify(new \RuntimeException('Operation timed out', 110));
        $noCode = RuleBasedFailureClassifier::default()->classify(new \RuntimeException('connection reset by peer'));

        self::assertContains('ECONNREFUSED', $refused->getTags());
        self::assertContains('ETIMEDOUT', $timedOut->getTags());
        self::assertContains('ECONNRESET', $noCode->getTags());
    }

    public function testACallersOwnRuleStillTagsAnErrorWithAStatus(): void
    {
        $classifier = RuleBasedFailureClassifier::default()
            ->addRule(new InstanceOfRule(HttpFailure::class, null, ['MINE']));

        $meta = $classifier->classify(HttpFailure::status(409, [], 'conflict after a timeout'));

        self::assertSame(409, $meta->getStatusCode());
        self::assertSame(['MINE'], $meta->getTags());
    }

    // ---- end to end: spec in, sleeps out ----------------------------------------------------

    public function testA404WithTimeoutInItsUrlRunsOnceUnderTheDefaultSpec(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;j=0;on=default');
        $error = HttpFailure::status(404, [], 'Client error: GET https://api.example.test/timeout resulted in a 404');

        $result = $this->retry->tryOutcome(self::failing(9, $error, $seen), $policy);

        self::assertSame([1], $seen);
        self::assertSame([], $this->sleeper->sleeps());
        self::assertSame(GiveUpReason::NotRetryable, $result->giveUpReason());
    }

    public function testA404CarryingXRateLimitResetRunsOnceUnderTheDefaultSpec(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;j=0');
        $error = HttpFailure::status(404, ['X-RateLimit-Reset' => '30', 'X-RateLimit-Remaining' => '4999']);

        $result = $this->retry->tryOutcome(self::failing(9, $error, $seen), $policy);

        self::assertSame([1], $seen);
        self::assertSame([], $this->sleeper->sleeps());
        self::assertSame(GiveUpReason::NotRetryable, $result->giveUpReason());
    }

    public function testASymfonyStyle404WithTimeoutInItsUrlRunsOnce(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;j=0');
        $error = new SymfonyShapedFailure(404, [], 'https://api.example.test/v1/timeouts/42');

        $this->retry->tryOutcome(self::failing(9, $error, $seen), $policy);

        self::assertSame([1], $seen);
        self::assertSame([], $this->sleeper->sleeps());
    }

    public function testAnErrnoCodedConnectionRefusedStillRetries(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=4;d=100;j=0');

        $value = $this->retry->try(self::failing(2, new \RuntimeException('Connection refused', 111), $seen), $policy);

        self::assertSame('ok', $value);
        self::assertSame([1, 2, 3], $seen);
        self::assertSame([100, 200], $this->sleeper->sleeps());
    }

    public function testA503StillHonoursRetryAfterAndA429StillRetries(): void
    {
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0');

        $this->retry->try(self::failing(1, HttpFailure::status(503, ['Retry-After' => '2'])), $policy);
        $this->retry->try(self::failing(1, HttpFailure::status(429)), $policy);

        self::assertSame([2000, 100], $this->sleeper->sleeps());
    }

    // ---- the reader seam for clients that are not PSR-7 --------------------------------------

    public function testWithoutAReaderASymfonyStyle503HonoursRetryAfter(): void
    {
        // The default reads getResponse()->getStatusCode() and getHeaders(false) when the
        // response is not PSR-7.
        $policy = (new RtryPolicyFactory())->fromSpec('rtry:a=3;d=100;j=0');

        $this->retry->try(self::failing(1, new SymfonyShapedFailure(503, ['retry-after' => ['2']])), $policy);

        self::assertSame([2000], $this->sleeper->sleeps());
    }

    public function testAReaderLetsASymfonyStyle503Or429HonourRetryAfter(): void
    {
        $factory = new RtryPolicyFactory(null, RuleBasedFailureClassifier::default(new SymfonyShapedReader()));
        $policy = $factory->fromSpec('rtry:a=3;d=100;j=0');

        $this->retry->try(self::failing(1, new SymfonyShapedFailure(503, ['retry-after' => ['2']])), $policy);
        $this->retry->try(self::failing(1, new SymfonyShapedFailure(429, ['ratelimit-reset' => ['3']])), $policy);

        self::assertSame([2000, 3000], $this->sleeper->sleeps());
    }

    public function testAReaderSeesThroughToTheStatusOnItsOwn(): void
    {
        $reader = new SymfonyShapedReader();
        $classifier = new RuleBasedFailureClassifier([new ResponseStatusRule($reader), new RateLimitBackoffRule($reader)]);

        // Only the reader's rules, and code 0: the 404 comes from the response, not the code.
        $meta = $classifier->classify(new SymfonyShapedFailure(404, ['x-ratelimit-reset' => ['30']], null, 0, 'worker timed out'));

        self::assertSame(404, $meta->getStatusCode());
        self::assertSame([], $meta->getTags());
    }

    public function testTheDefaultLocatorReadsStatusAndLowerCasedHeaders(): void
    {
        $response = (new ErrorResponseLocator())->read(HttpFailure::status(503, ['Retry-After' => '7']));

        self::assertNotNull($response);
        self::assertSame(503, $response->statusCode);
        self::assertSame(['retry-after' => ['7']], $response->headers);
        self::assertNull((new ErrorResponseLocator())->read(new \RuntimeException('no response')));
    }
}

/** What a caller writes for Symfony HttpClient: status and getHeaders(false). */
final class SymfonyShapedReader implements ErrorResponseReaderInterface
{
    public function read(\Throwable $e): ?ErrorResponse
    {
        if (!$e instanceof SymfonyShapedFailure) {
            return null;
        }
        $response = $e->getResponse();
        return new ErrorResponse($response->getStatusCode(), $response->getHeaders(false));
    }
}
