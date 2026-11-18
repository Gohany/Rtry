<?php declare(strict_types=1);

namespace Gohany\Rtry\Tests;

use Gohany\Retry\HedgeInterface;
use Gohany\Retry\JitterInterface;
use Gohany\Retry\Testing\SeededRandom;
use Gohany\Retry\Testing\TestAttemptContext;
use Gohany\Retry\Testing\TestOutcome;
use Gohany\Rtry\Impl\Parts\Hedge;
use Gohany\Rtry\Impl\Parts\Jitter;
use Gohany\Rtry\Impl\Parts\On;
use Gohany\Rtry\Impl\Parts\Sequence;
use Gohany\Rtry\Impl\RtryPolicy;
use PHPUnit\Framework\TestCase;

final class RtryPartsTest extends TestCase
{
    public function testJitterParsesAndPrintsCanonically(): void
    {
        self::assertSame('100%@full', Jitter::default()->canonical());
        self::assertSame('20%@pm', Jitter::make('20%@PM')->canonical());
        self::assertSame('12.5%@full', Jitter::make('12.5%')->canonical());
        self::assertSame('1500@full', Jitter::make('1.5s')->canonical());
        self::assertSame('none', Jitter::make('0')->canonical());
        self::assertSame('none', Jitter::make('0%@pm')->canonical());
        self::assertSame('none', Jitter::make('250@none')->canonical());
    }

    public function testJitterDrawsOnceAndOnlyWhenTheBoundsDiffer(): void
    {
        $jitter = Jitter::make('100@full');
        $random = SeededRandom::randomizer(3);

        self::assertSame(SeededRandom::expectedDraws(3, [[900, 1000]]), [$jitter->apply(1000, $random)]);
        self::assertSame(SeededRandom::expectedDraws(3, [[0, 50]]), [$jitter->apply(50, SeededRandom::randomizer(3))], 'the window is clamped at 0');
        self::assertSame(0, Jitter::default()->apply(0, SeededRandom::randomizer(3)));
        self::assertSame(400, Jitter::none()->apply(400, SeededRandom::randomizer(3)));
        self::assertSame(JitterInterface::MODE_PM, Jitter::percentOf(10, 'pm')->mode());
    }

    public function testJitterRejectsBadValues(): void
    {
        foreach (['150%', '-5', 'abc', '20%@sideways', '%'] as $bad) {
            try {
                Jitter::make($bad);
                self::fail('accepted j=' . $bad);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSequenceForms(): void
    {
        foreach (['50,100,1.5s*', '(50,100ms,1.5s*)', '50,100,1.5s,*', '( 50 , 100 , 1.5s * )'] as $form) {
            $sequence = Sequence::make($form);
            self::assertSame([50, 100, 1500], $sequence->delaysMs(), $form);
            self::assertTrue($sequence->isRepeatLastDelay(), $form);
            self::assertSame('50,100,1500*', $sequence->canonical(), $form);
        }
        self::assertSame(1500, Sequence::make('50,100,1.5s*')->delayMsAt(9));
        self::assertNull(Sequence::make('50,100')->delayMsAt(4));
    }

    public function testSequenceRejectsEmptyForms(): void
    {
        foreach (['', '()', '*', '50,,100'] as $bad) {
            try {
                Sequence::make($bad);
                self::fail('accepted seq=' . $bad);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHedgeIsParsedAsAHint(): void
    {
        $hedge = Hedge::make('3@1.5s&1');
        self::assertSame([3, 1500, HedgeInterface::CANCEL_ON_FIRST_COMPLETION], [$hedge->lanes(), $hedge->staggerDelayMs(), $hedge->cancelPolicy()]);
        self::assertSame('3@1500&1', $hedge->canonical());
        self::assertSame('2@100', Hedge::make('2@100&0')->canonical());
    }

    public function testOnBuildsATokenDecider(): void
    {
        $on = On::make('5xx, 429 ,ETIMEDOUT');
        self::assertSame(['5xx', '429', 'ETIMEDOUT'], $on->tokens());
        self::assertSame('5xx,429,ETIMEDOUT', $on->canonical());

        $decider = $on->decider();
        $attempt = new TestAttemptContext(1);
        self::assertTrue($decider->shouldRetry(TestOutcome::failure(502), $attempt));
        self::assertTrue($decider->shouldRetry(TestOutcome::failure(null, ['etimedout']), $attempt));
        self::assertFalse($decider->shouldRetry(TestOutcome::failure(422), $attempt));
    }

    public function testNominalDelaysDoNotOverflow(): void
    {
        $policy = new RtryPolicy(attempts: 200, delayMs: 1000, exponentialBase: 10.0);
        self::assertGreaterThan(0, $policy->nominalDelayMs(200));
        self::assertSame(1_000_000, $policy->nominalDelayMs(5));
    }
}
