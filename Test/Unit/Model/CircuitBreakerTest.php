<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Test\Unit\Model;

use JustBetter\Sentry\Helper\Data;
use JustBetter\Sentry\Model\CircuitBreaker;
use JustBetter\Sentry\Model\RateLimitParser;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Sentry\HttpClient\Response;

class CircuitBreakerTest extends TestCase
{
    /**
     * @var CacheInterface&Stub
     */
    private $cache;

    /**
     * @var Data&Stub
     */
    private $helper;

    /**
     * @var RateLimitParser&Stub
     */
    private $rateLimitParser;

    /**
     * @var Json
     */
    private Json $serializer;

    /**
     * @var array<string, string>
     */
    private array $storage = [];

    protected function setUp(): void
    {
        $this->storage = [];
        $this->serializer = new Json();
        $this->helper = $this->createStub(Data::class);
        $this->cache = $this->createStub(CacheInterface::class);
        $this->rateLimitParser = $this->createStub(RateLimitParser::class);

        $this->cache->method('load')->willReturnCallback(
            function (string $key): string|false {
                if (!array_key_exists($key, $this->storage)) {
                    return false;
                }

                return $this->storage[$key];
            }
        );
        $this->cache->method('save')->willReturnCallback(
            function (string $data, string $key): bool {
                $this->storage[$key] = $data;

                return true;
            }
        );

        $this->helper->method('getCircuitBreakerFailureThreshold')->willReturn(3);
        $this->helper->method('getCircuitBreakerSuccessThreshold')->willReturn(2);
        $this->helper->method('getCircuitBreakerRecoveryTimeout')->willReturn(60);
    }

    private function createBreaker(
        ?CacheInterface $cache = null,
        ?Data $helper = null,
        ?RateLimitParser $rateLimitParser = null
    ): CircuitBreaker {
        return new CircuitBreaker(
            $cache ?? $this->cache,
            $this->serializer,
            $helper ?? $this->helper,
            $rateLimitParser ?? $this->rateLimitParser
        );
    }

    public function testStartsClosedAndAllowsRequests(): void
    {
        $this->assertTrue($this->createBreaker()->allowRequest());
    }

    public function testOpensAfterFailureThreshold(): void
    {
        $breaker = $this->createBreaker();

        $breaker->recordFailure();
        $breaker->recordFailure();
        $this->assertTrue($breaker->allowRequest());

        $breaker->recordFailure();
        $this->assertFalse($breaker->allowRequest());
    }

    public function testRecordSuccessResetsFailuresWhileClosed(): void
    {
        $breaker = $this->createBreaker();

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordSuccess();

        $breaker->recordFailure();
        $breaker->recordFailure();
        $this->assertTrue($breaker->allowRequest());
    }

    public function testHalfOpenAfterRecoveryTimeoutThenClosesOnSuccessThreshold(): void
    {
        $this->storage['justbetter_sentry_circuit_breaker'] = (string) $this->serializer->serialize([
            'state'     => CircuitBreaker::STATE_OPEN,
            'failures'  => 3,
            'successes' => 0,
            'opened_at' => microtime(true) - 120,
        ]);

        $breaker = $this->createBreaker();

        $this->assertTrue($breaker->allowRequest());

        $breaker->recordSuccess();
        $breaker->recordSuccess();

        $state = $this->serializer->unserialize($this->storage['justbetter_sentry_circuit_breaker']);
        $this->assertIsArray($state);
        $this->assertSame(CircuitBreaker::STATE_CLOSED, $state['state']);
        $this->assertSame(0, $state['failures']);
    }

    public function testHalfOpenFailureReopensCircuit(): void
    {
        $this->storage['justbetter_sentry_circuit_breaker'] = (string) $this->serializer->serialize([
            'state'     => CircuitBreaker::STATE_HALF_OPEN,
            'failures'  => 3,
            'successes' => 0,
            'opened_at' => microtime(true) - 120,
        ]);

        $breaker = $this->createBreaker();
        $breaker->recordFailure();

        $state = $this->serializer->unserialize($this->storage['justbetter_sentry_circuit_breaker']);
        $this->assertIsArray($state);
        $this->assertSame(CircuitBreaker::STATE_OPEN, $state['state']);
        $this->assertFalse($breaker->allowRequest());
    }

    public function testCorruptCacheFallsBackToClosed(): void
    {
        $this->storage['justbetter_sentry_circuit_breaker'] = 'not-json';

        $this->assertTrue($this->createBreaker()->allowRequest());
    }

    public function testRecordRateLimitOpensCircuitUsingParserRetryAt(): void
    {
        $rateLimitParser = $this->createStub(RateLimitParser::class);
        $rateLimitParser->method('getRetryAt')->willReturn(time() + 120);

        $breaker = $this->createBreaker(rateLimitParser: $rateLimitParser);
        $breaker->recordRateLimit(new Response(429, [], ''));

        $this->assertFalse($breaker->allowRequest());

        $state = $this->serializer->unserialize($this->storage['justbetter_sentry_circuit_breaker']);
        $this->assertSame(CircuitBreaker::STATE_OPEN, $state['state']);
        $this->assertEqualsWithDelta(time() + 120, $state['retry_at'], 2);
    }

    /**
     * @dataProvider unusableParserRetryAtProvider
     */
    public function testRecordRateLimitFallsBackToRecoveryTimeoutWhenParserRetryAtIsUnusable(?int $parserRetryAt): void
    {
        $rateLimitParser = $this->createStub(RateLimitParser::class);
        $rateLimitParser->method('getRetryAt')->willReturn($parserRetryAt);

        $breaker = $this->createBreaker(rateLimitParser: $rateLimitParser);
        $breaker->recordRateLimit(new Response(429, [], ''));

        $state = $this->serializer->unserialize($this->storage['justbetter_sentry_circuit_breaker']);
        $this->assertSame(CircuitBreaker::STATE_OPEN, $state['state']);
        $this->assertEqualsWithDelta(time() + 60, $state['retry_at'], 2);
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function unusableParserRetryAtProvider(): array
    {
        return [
            'parser found no usable header' => [null],
            'parser retry_at is not in the future' => [time() - 1],
        ];
    }

    /**
     * @dataProvider halfOpenTransitionProvider
     */
    public function testHalfOpenTransitionGatedByRetryAt(float $openedAtOffset, float $retryAtOffset, bool $expectedAllowed): void
    {
        $this->storage['justbetter_sentry_circuit_breaker'] = (string) $this->serializer->serialize([
            'state'     => CircuitBreaker::STATE_OPEN,
            'failures'  => 1,
            'successes' => 0,
            'opened_at' => microtime(true) + $openedAtOffset,
            'retry_at'  => microtime(true) + $retryAtOffset,
        ]);

        $this->assertSame($expectedAllowed, $this->createBreaker()->allowRequest());
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: bool}>
     */
    public static function halfOpenTransitionProvider(): array
    {
        return [
            // opened_at is inside the 60s recovery window, but retry_at (rate-limit backoff) already passed.
            'retry_at passed within recovery window allows request'        => [-5.0, -1.0, true],
            // opened_at is past the 60s recovery window, but retry_at (rate-limit backoff) is still in the future.
            'retry_at still pending beyond recovery window blocks request' => [-120.0, 120.0, false],
        ];
    }

    public function testPersistExtendsTtlToOutliveRateLimitBackoff(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('save')
            ->with(
                $this->anything(),
                'justbetter_sentry_circuit_breaker',
                $this->anything(),
                $this->greaterThanOrEqual(660)
            )
            ->willReturn(true);

        $rateLimitParser = $this->createStub(RateLimitParser::class);
        $rateLimitParser->method('getRetryAt')->willReturn(time() + 600);

        $this->createBreaker($cache, rateLimitParser: $rateLimitParser)
            ->recordRateLimit(new Response(429, [], ''));
    }
}
