<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Test\Unit\Model\Transport;

use JustBetter\Sentry\Helper\Data;
use JustBetter\Sentry\Model\CircuitBreaker;
use JustBetter\Sentry\Model\Transport\EnvelopeSender;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Response;

class EnvelopeSenderTest extends TestCase
{
    private function createHelper(): Data
    {
        $helper = $this->createStub(Data::class);
        $helper->method('getDSN')->willReturn('https://key@sentry.example/1');

        return $helper;
    }

    public function testEmptyPayloadThrows(): void
    {
        $sender = new EnvelopeSender(
            $this->createHelper(),
            $this->createStub(HttpClientInterface::class),
            $this->createMock(CircuitBreaker::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sentry envelope payload is empty.');

        $sender->send('');
    }

    /**
     * @dataProvider missingDsnProvider
     */
    public function testMissingDsnThrows(?string $dsn): void
    {
        $helper = $this->createStub(Data::class);
        $helper->method('getDSN')->willReturn($dsn);

        $sender = new EnvelopeSender(
            $helper,
            $this->createStub(HttpClientInterface::class),
            $this->createMock(CircuitBreaker::class)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sentry DSN is not configured.');

        $sender->send('not-empty');
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function missingDsnProvider(): array
    {
        return [
            'null dsn'  => [null],
            'empty dsn' => [''],
        ];
    }

    public function testSuccessRecordsCircuitSuccessAndReturns(): void
    {
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('sendRequest')->willReturn(new Response(200, [], ''));

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->expects($this->once())->method('recordSuccess');
        $circuitBreaker->expects($this->never())->method('recordFailure');
        $circuitBreaker->expects($this->never())->method('recordRateLimit');

        $sender = new EnvelopeSender($this->createHelper(), $httpClient, $circuitBreaker);
        $sender->send('envelope-bytes');

        $this->addToAssertionCount(1);
    }

    public function testRateLimitRecordsRateLimitAndThrows(): void
    {
        $response = new Response(429, ['Retry-After' => ['30']], 'rate limited');
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->expects($this->once())->method('recordRateLimit')->with($response);
        $circuitBreaker->expects($this->never())->method('recordSuccess');
        $circuitBreaker->expects($this->never())->method('recordFailure');

        $sender = new EnvelopeSender($this->createHelper(), $httpClient, $circuitBreaker);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sentry envelope delivery failed with HTTP 429 (RATE_LIMIT): rate limited');

        $sender->send('envelope-bytes');
    }

    /**
     * @dataProvider serverFailureStatusCodeProvider
     */
    public function testServerFailureRecordsFailureAndThrows(int $statusCode, string $expectedMessage): void
    {
        $response = new Response($statusCode, [], 'boom');
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->expects($this->once())->method('recordFailure');
        $circuitBreaker->expects($this->never())->method('recordSuccess');
        $circuitBreaker->expects($this->never())->method('recordRateLimit');

        $sender = new EnvelopeSender($this->createHelper(), $httpClient, $circuitBreaker);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $sender->send('envelope-bytes');
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function serverFailureStatusCodeProvider(): array
    {
        return [
            '5xx server error'      => [503, 'Sentry envelope delivery failed with HTTP 503 (FAILED): boom'],
            'unrecognized status 0' => [0, 'Sentry envelope delivery failed with HTTP 0 (UNKNOWN): boom'],
        ];
    }

    /**
     * @dataProvider rejectedPayloadStatusCodeProvider
     */
    public function testRejectedPayloadDoesNotTouchCircuitBreaker(int $statusCode, string $expectedMessage): void
    {
        $response = new Response($statusCode, [], 'rejected');
        $httpClient = $this->createStub(HttpClientInterface::class);
        $httpClient->method('sendRequest')->willReturn($response);

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->expects($this->never())->method('recordSuccess');
        $circuitBreaker->expects($this->never())->method('recordFailure');
        $circuitBreaker->expects($this->never())->method('recordRateLimit');

        $sender = new EnvelopeSender($this->createHelper(), $httpClient, $circuitBreaker);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedMessage);

        $sender->send('envelope-bytes');
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function rejectedPayloadStatusCodeProvider(): array
    {
        return [
            '413 content too large' => [413, 'Sentry envelope delivery failed with HTTP 413 (CONTENT_TOO_LARGE): rejected'],
            '400 invalid'           => [400, 'Sentry envelope delivery failed with HTTP 400 (INVALID): rejected'],
        ];
    }
}
