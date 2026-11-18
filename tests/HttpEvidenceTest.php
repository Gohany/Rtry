<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\GiveUpReason;
use Gohany\Retry\Testing\FrozenClock;
use Gohany\Retry\Testing\RecordingSleeper;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Gohany\Rtry\Impl\ErrorResponse;
use Gohany\Rtry\Impl\ErrorResponseLocator;
use Gohany\Rtry\Impl\Retry;
use Gohany\Rtry\Impl\RtryPolicyFactory;
use Gohany\Rtry\Impl\Rules\MethodStatusRule;
use Gohany\Rtry\Impl\Rules\RateLimitBackoffRule;
use Gohany\Rtry\Impl\Rules\ResponseStatusRule;
use Gohany\Rtry\Impl\RuleBasedFailureClassifier;
use Gohany\Rtry\Tests\Support\HttpFailure;
use Gohany\Rtry\Tests\Support\ResponsePropertyFailure;
use Gohany\Rtry\Tests\Support\StatusAccessorFailure;
use Gohany\Rtry\Tests\Support\SymfonyShapedFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PR #6 hostile review, blockers 1 and 2 and should-fixes 3, 4 and 6: an HTTP status decides
 * wherever it sits on the error (a wrapped exception, an SDK accessor, a response that is not
 * PSR-7); an exception code alone is not proof of HTTP, so it never silences transport tags;
 * and only transport evidence (specific phrases, cURL numbers, transport types) makes an error
 * with no HTTP status retryable. Bare words such as "timeout" or "refused" do not.
 *
 * End to end: parse a real rtry: string, run Retry against an operation that always throws,
 * record the attempts and sleeps.
 */
final class HttpEvidenceTest extends TestCase
{
    private const DEFAULT_SPEC = 'rtry:a=4;d=100;j=0;on=default';

    /** @var list<int> */
    private array $sleeps = [];

    /** @return array{int, ?GiveUpReason} attempts and give-up reason; sleeps land in $this->sleeps */
    private function attempt(string $spec, \Throwable $error, ?RuleBasedFailureClassifier $classifier = null): array
    {
        $clock = new FrozenClock();
        $sleeper = new RecordingSleeper($clock);
        $retry = new Retry($clock, $sleeper, null, SeededRandom::randomizer(1118));
        $policy = (new RtryPolicyFactory(null, $classifier))->fromSpec($spec);

        $result = $retry->tryOutcome(static fn () => throw $error, $policy);

        $this->sleeps = $sleeper->sleeps();
        self::assertSame($error, $result->lastError(), 'the caller always gets its own error back');
        return [$result->attempts(), $result->giveUpReason()];
    }

    // ---- blocker 1: the status decides at every level ---------------------------------------

    /** @return iterable<string, array{\Throwable}> */
    public static function errorsThatMustRunOnce(): iterable
    {
        // An HTTP status that is not retryable, wherever it is.
        yield 'PSR-7 404 wrapped, its URL says timeout' => [new \RuntimeException(
            'Request failed: Client error: GET https://api.example.test/v1/timeout-settings resulted in a 404',
            0,
            HttpFailure::status(404, [], 'Client error: GET https://api.example.test/v1/timeout-settings resulted in a 404'),
        )];
        yield 'PSR-7 422 on a charge, wrapped, body says refused' => [new \RuntimeException(
            'Request failed: card refused by issuer',
            0,
            HttpFailure::status(422, [], 'card refused by issuer'),
        )];
        yield 'PSR-7 403 wrapped twice' => [new \RuntimeException(
            'sync failed: connection timed out?',
            0,
            new \RuntimeException('upstream connection refused?', 0, HttpFailure::status(403)),
        )];
        yield 'getHttpStatus() 404, code 0 (Stripe shape)' => [new StatusAccessorFailure(404, 'No such timeout_rule: tr_123', 'getHttpStatus')];
        yield 'getStatusCode() 400, code 0 (AWS shape)' => [new StatusAccessorFailure(400, 'Connection timed out is not a valid value', 'getStatusCode')];
        yield 'getResponse() not PSR-7, 404, code 0' => [new SymfonyShapedFailure(404, [], null, 0, 'worker timed out')];

        // A real response below 400 or above 599 is not a transport error either.
        yield '200 carrying X-RateLimit-Reset' => [HttpFailure::status(200, ['X-RateLimit-Reset' => '30'])];
        yield '302 carrying X-RateLimit-Reset' => [HttpFailure::status(302, ['X-RateLimit-Reset' => '30'])];
        yield '304 whose URL says timeout' => [HttpFailure::status(304, [], 'GET https://api.example.test/timeout timed out')];
        yield '999 that says timed out' => [HttpFailure::status(999, [], 'upstream timed out')];

        // No HTTP at all: validation and programming errors, whatever words they use.
        yield 'DomainException: payment refused' => [new \DomainException('Payment refused by issuer')];
        yield 'InvalidArgumentException: timeout must be positive' => [new \InvalidArgumentException('timeout must be a positive integer')];
        yield 'TypeError naming $timeout' => [new \TypeError('Argument #2 ($timeout) must be of type int, string given')];
        yield 'LogicException: consent refused' => [new \LogicException('user refused consent')];
        yield 'LogicException even with a transport phrase' => [new \LogicException('connection refused is not a valid state here')];
        yield 'RuntimeException: invalid timeout setting' => [new \RuntimeException('Invalid timeout setting: -1')];
        yield 'RuntimeException: request refused' => [new \RuntimeException('Request refused: account suspended')];
    }

    #[DataProvider('errorsThatMustRunOnce')]
    public function testRunsOnceUnderTheDefaultSpec(\Throwable $error): void
    {
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt(self::DEFAULT_SPEC, $error));
        self::assertSame([], $this->sleeps);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function errorsThatMustRetry(): iterable
    {
        // Retryable HTTP statuses, wherever they are.
        yield 'PSR-7 503 wrapped' => [new \RuntimeException('Request failed', 0, HttpFailure::status(503))];
        yield 'getStatusCode() 503, code 0 (AWS shape)' => [new StatusAccessorFailure(503, 'Service Unavailable', 'getStatusCode')];
        yield 'getHttpStatus() 429, code 0 (Stripe shape)' => [new StatusAccessorFailure(429, 'Too many requests', 'getHttpStatus')];
        yield 'getResponse() not PSR-7, 502, code 0' => [new SymfonyShapedFailure(502, [], null, 0, 'Bad gateway')];
        yield 'an exception coded 503, as in 2.x' => [new \RuntimeException('Service Unavailable', 503)];

        // Transport evidence: specific phrases, cURL numbers, errno-coded errors.
        yield 'cURL error 28' => [new \RuntimeException('cURL error 28: Operation timed out after 1001 milliseconds with 0 bytes received')];
        yield 'cURL error 7, no transport words' => [new \RuntimeException('cURL error 7: Failed to connect to api.example.test port 443 after 3 ms: Could not connect to server')];
        yield 'cURL error 56' => [new \RuntimeException('cURL error 56: Recv failure')];
        yield 'stream connection refused' => [new \RuntimeException('stream_socket_client(): Unable to connect to tcp://10.0.0.5:6379 (Connection refused)')];
        yield 'Windows connection refused' => [new \RuntimeException('No connection could be made because the target machine actively refused it')];
        yield 'errno 111' => [new \RuntimeException('Connection refused', 111)];
        yield 'errno 110' => [new \RuntimeException('Connection timed out', 110)];
        yield 'errno 104' => [new \RuntimeException('Connection reset by peer', 104)];
        yield 'Symfony idle timeout' => [new \RuntimeException('Idle timeout reached for "https://api.example.test/v1/things".')];
        yield 'a transport error wrapped' => [new \RuntimeException('Import failed', 0, new \RuntimeException('Connection refused'))];
        yield 'an ErrorException from a stream warning' => [new \ErrorException('fsockopen(): Unable to connect to 10.0.0.5:25 (Connection timed out)')];
        yield 'MySQL lock wait timeout' => [new \RuntimeException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction')];

        // Blocker 2: codes from 400 to 599 that are not HTTP statuses keep their tags.
        yield 'SMTP 421 coded 421' => [new \RuntimeException('Expected response code "250" but got code "421", with message "421 4.4.2 Connection timed out".', 421)];
        yield 'SMTP 451 coded 451' => [new \RuntimeException('451 4.4.1 Timeout waiting for data from client', 451)];
        yield 'deadlock coded 409' => [new \RuntimeException('Deadlock found when trying to get lock; try restarting transaction', 409)];
    }

    #[DataProvider('errorsThatMustRetry')]
    public function testRetriesUnderTheDefaultSpec(\Throwable $error): void
    {
        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt(self::DEFAULT_SPEC, $error));
        self::assertSame([100, 200, 400], $this->sleeps);
    }

    public function testRetryAfterIsReadFromAWrappedResponse(): void
    {
        $error = new \RuntimeException('Request failed', 0, HttpFailure::status(503, ['Retry-After' => '2']));

        self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100;j=0', $error));
        self::assertSame([2000], $this->sleeps);
    }

    public function testAnExceptionCodeStillMatchesOnAsIn2x(): void
    {
        // No transport words: only the code can match, and it does for a status token.
        self::assertSame([3, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=3;d=100;j=0;on=451', new \RuntimeException('mailbox busy', 451)));
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt('rtry:a=3;d=100;j=0', new \RuntimeException('mailbox busy', 451)));
    }

    public function testAnExceptionCodeIsAStatusButNeverSilencesTransportTags(): void
    {
        $meta = RuleBasedFailureClassifier::default()->classify(new \RuntimeException('421 4.4.2 Connection timed out', 421));

        self::assertSame(421, $meta->getStatusCode());
        self::assertContains('ETIMEDOUT', $meta->getTags());
    }

    /** Should-fix 5: an on=ETIMEDOUT spec still retries a 504 that says it timed out, as in 2.2. */
    public function testATimedOut504StillMatchesOnEtimedout(): void
    {
        $error = HttpFailure::status(504, [], 'upstream timed out (110: Connection timed out) while reading response header');

        self::assertSame([4, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=4;d=100;j=0;on=ETIMEDOUT', $error));
    }

    // ---- the gate's boundaries (should-fix 6) -----------------------------------------------

    /** @return iterable<string, array{int, bool}> */
    public static function responseStatuses(): iterable
    {
        foreach ([408, 429, 500, 503, 504, 599] as $status) {
            yield "$status keeps transport tags" => [$status, true];
        }
        foreach ([100, 200, 302, 399, 400, 401, 404, 407, 409, 422, 428, 430, 451, 499, 600, 999] as $status) {
            yield "$status drops them" => [$status, false];
        }
    }

    #[DataProvider('responseStatuses')]
    public function testOnlyARetryableResponseStatusKeepsMessageTags(int $status, bool $keeps): void
    {
        $meta = RuleBasedFailureClassifier::default()->classify(
            HttpFailure::status($status, [], 'upstream timed out (110: Connection timed out); connection refused; connection reset')
        );

        $tags = $meta->getTags();
        self::assertSame($keeps, in_array('ETIMEDOUT', $tags, true), "status $status: " . implode(',', $tags));
        self::assertSame($keeps, in_array('ECONNREFUSED', $tags, true), "status $status");
        self::assertSame($keeps, in_array('ECONNRESET', $tags, true), "status $status");
        if (!$keeps) {
            self::assertSame([], $tags, "status $status");
        }
    }

    public function testNoRateLimitedTagBelow400(): void
    {
        foreach ([200, 302, 304] as $status) {
            $meta = RuleBasedFailureClassifier::default()->classify(HttpFailure::status($status, ['X-RateLimit-Reset' => '30', 'Retry-After' => '2']));
            self::assertNotContains('RATE_LIMITED', $meta->getTags(), "status $status");
        }
    }

    // ---- should-fix 4: a reader adds to the default reading -----------------------------------

    public function testACustomReaderStillLetsPsr7ResponsesBeRead(): void
    {
        $classifier = RuleBasedFailureClassifier::default(new ResponsePropertyReader());

        self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100;j=0', HttpFailure::status(503, ['Retry-After' => '2']), $classifier));
        self::assertSame([2000], $this->sleeps);

        $notFound = HttpFailure::status(404, [], 'Client error: GET https://api.example.test/timeout resulted in a 404');
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt('rtry:a=4;d=100;j=0', $notFound, $classifier));

        $symfony = new SymfonyShapedFailure(503, ['retry-after' => ['3']]);
        self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100;j=0', $symfony, $classifier));
        self::assertSame([3000], $this->sleeps);
    }

    public function testAReaderThatThrowsFoundNothingAndTheDefaultReadingStillWorks(): void
    {
        $classifier = RuleBasedFailureClassifier::default(new ThrowingReader());

        self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100;j=0', HttpFailure::status(503, ['Retry-After' => '2']), $classifier));
        self::assertSame([2000], $this->sleeps);

        $standalone = new RuleBasedFailureClassifier([new ResponseStatusRule(new ThrowingReader()), new RateLimitBackoffRule(new ThrowingReader())]);
        $meta = $standalone->classify(HttpFailure::status(429, ['Retry-After' => '4']));
        self::assertSame(429, $meta->getStatusCode());
        self::assertSame(4000, $meta->getMinNextDelayMs());
    }

    // ---- should-fix 6: a reader supplies a status the code does not -------------------------

    public function testOnlyAReaderCanSeeAResponseKeptInAProperty(): void
    {
        $unavailable = ResponsePropertyFailure::status(503, ['retry-after' => ['2']], 'Service Unavailable');

        // Without the reader: code 0, no transport words, so nothing says retry.
        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt('rtry:a=2;d=100;j=0', $unavailable));

        // With it: the reader's 503 and its Retry-After.
        $classifier = RuleBasedFailureClassifier::default(new ResponsePropertyReader());
        self::assertSame([2, GiveUpReason::AttemptsExhausted], $this->attempt('rtry:a=2;d=100;j=0', $unavailable, $classifier));
        self::assertSame([2000], $this->sleeps);
    }

    public function testAReadersStatusAloneDecides(): void
    {
        // No headers, so only the status rule can see this 404; without it the words would retry.
        $notFound = ResponsePropertyFailure::status(404, [], 'Connection refused for key k_123');
        $classifier = RuleBasedFailureClassifier::default(new ResponsePropertyReader());

        self::assertSame([1, GiveUpReason::NotRetryable], $this->attempt('rtry:a=4;d=100;j=0', $notFound, $classifier));
        self::assertSame(404, $classifier->classify($notFound)->getStatusCode());
    }

    public function testAReaderStatusOutside100To599IsNoStatus(): void
    {
        $reader = new FixedReader(999);
        $meta = (new RuleBasedFailureClassifier([new ResponseStatusRule($reader)]))->classify(new \RuntimeException('Connection refused'));
        self::assertNull($meta->getStatusCode());
        self::assertSame([], $meta->getTags(), 'a response was found, so it is not a transport error');

        $reader = new FixedReader(0);
        $meta = (new RuleBasedFailureClassifier([new ResponseStatusRule($reader)]))->classify(new \RuntimeException('Connection refused'));
        self::assertNull($meta->getStatusCode());
        self::assertSame(['ECONNREFUSED'], $meta->getTags(), 'status 0 means no response');
    }

    // ---- compatibility and the locator ------------------------------------------------------

    public function testTheDefaultClassifierStillHasAMethodStatusRule(): void
    {
        self::assertTrue(RuleBasedFailureClassifier::default()->hasRuleOfType(MethodStatusRule::class));
        self::assertTrue(RuleBasedFailureClassifier::default()->hasRuleOfType(RateLimitBackoffRule::class));
    }

    public function testTheLocatorReadsEachErrorOnce(): void
    {
        $reader = new CountingReader();
        $classifier = RuleBasedFailureClassifier::default($reader);
        $error = new \RuntimeException('wrapped', 0, HttpFailure::status(503, ['Retry-After' => '1']));

        $classifier->classify($error);
        $classifier->classify($error);

        self::assertSame(2, $reader->reads, 'one read per throwable in the chain, not one per rule or per call');
    }

    public function testTheLocatorReadsPsr7SymfonyShapeAndAccessors(): void
    {
        $locator = new ErrorResponseLocator();

        $psr7 = $locator->read(new \LogicException('outer', 0, HttpFailure::status(503, ['Retry-After' => '7'])));
        self::assertNotNull($psr7);
        self::assertSame([503, ['retry-after' => ['7']]], [$psr7->statusCode, $psr7->headers]);

        $symfony = $locator->read(new SymfonyShapedFailure(429, ['retry-after' => ['3']]));
        self::assertNotNull($symfony);
        self::assertSame([429, ['retry-after' => ['3']]], [$symfony->statusCode, $symfony->headers]);

        $accessor = $locator->read(new StatusAccessorFailure(502, 'Bad gateway', 'getStatusCode'));
        self::assertNotNull($accessor);
        self::assertSame([502, []], [$accessor->statusCode, $accessor->headers]);

        self::assertNull($locator->read(new \RuntimeException('no response', 503)));
    }

    public function testANestedHeaderValueRaisesNoWarning(): void
    {
        $response = new ErrorResponse(503, ['Retry-After' => [['2'], '3'], 'X-Odd' => [new \stdClass()]]);

        self::assertSame(['retry-after' => ['3']], $response->headers);
    }
}

/** Reads the response a ResponsePropertyFailure keeps in a property, and nothing else. */
final class ResponsePropertyReader implements ErrorResponseReaderInterface
{
    public function read(\Throwable $e): ?ErrorResponse
    {
        if (!$e instanceof ResponsePropertyFailure) {
            return null;
        }
        return new ErrorResponse($e->response->status(), $e->response->headers());
    }
}

final class ThrowingReader implements ErrorResponseReaderInterface
{
    public function read(\Throwable $e): ?ErrorResponse
    {
        throw new \RuntimeException('reader bug');
    }
}

final class FixedReader implements ErrorResponseReaderInterface
{
    public function __construct(private readonly int $status)
    {
    }

    public function read(\Throwable $e): ?ErrorResponse
    {
        return new ErrorResponse($this->status);
    }
}

final class CountingReader implements ErrorResponseReaderInterface
{
    public int $reads = 0;

    public function read(\Throwable $e): ?ErrorResponse
    {
        $this->reads++;
        return null;
    }
}
