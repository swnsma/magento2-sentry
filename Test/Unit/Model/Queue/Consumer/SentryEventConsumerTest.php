<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Test\Unit\Model\Queue\Consumer;

use JustBetter\Sentry\Helper\Data;
use JustBetter\Sentry\Model\CircuitBreaker;
use JustBetter\Sentry\Model\DeliveryGuard;
use JustBetter\Sentry\Model\Queue\Consumer\SentryEventConsumer;
use JustBetter\Sentry\Model\Transport\EnvelopeSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SentryEventConsumerTest extends TestCase
{
    private function createConsumer(
        ?EnvelopeSender $envelopeSender = null,
        ?CircuitBreaker $circuitBreaker = null,
        ?Data $helper = null,
        ?LoggerInterface $logger = null,
        ?DeliveryGuard $guard = null
    ): SentryEventConsumer {
        return new SentryEventConsumer(
            $envelopeSender ?? $this->createStub(EnvelopeSender::class),
            $circuitBreaker ?? $this->createStub(CircuitBreaker::class),
            $helper ?? $this->activeHelperStub(),
            $guard ?? new DeliveryGuard(),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    private function activeHelperStub(): Data
    {
        $helper = $this->createStub(Data::class);
        $helper->method('isActive')->willReturn(true);

        return $helper;
    }

    public function testSkipsWhenModuleInactive(): void
    {
        $helper = $this->createStub(Data::class);
        $helper->method('isActive')->willReturn(false);

        $envelopeSender = $this->createMock(EnvelopeSender::class);
        $envelopeSender->expects($this->never())->method('send');

        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->expects($this->never())->method('allowRequest');

        $guard = new DeliveryGuard();
        $this->createConsumer($envelopeSender, $circuitBreaker, $helper, null, $guard)->process('payload');
        $this->assertTrue($guard->isActive());
    }

    public function testSkipsEmptyPayload(): void
    {
        $envelopeSender = $this->createMock(EnvelopeSender::class);
        $envelopeSender->expects($this->never())->method('send');

        $this->createConsumer($envelopeSender)->process('');
    }

    public function testSkipsWhileCircuitIsOpen(): void
    {
        $envelopeSender = $this->createMock(EnvelopeSender::class);
        $envelopeSender->expects($this->never())->method('send');

        $circuitBreaker = $this->createStub(CircuitBreaker::class);
        $circuitBreaker->method('allowRequest')->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->createConsumer($envelopeSender, $circuitBreaker)->process('envelope-bytes');
    }

    public function testSuccessfulDeliveryDoesNotLog(): void
    {
        $envelopeSender = $this->createMock(EnvelopeSender::class);
        $envelopeSender->expects($this->once())->method('send')->with('envelope-bytes');

        $circuitBreaker = $this->createStub(CircuitBreaker::class);
        $circuitBreaker->method('allowRequest')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->createConsumer($envelopeSender, $circuitBreaker, null, $logger)->process('envelope-bytes');
    }

    public function testFailedDeliveryLogsWarningAndNeverThrows(): void
    {
        $exception = new RuntimeException('sentry 503');
        $envelopeSender = $this->createStub(EnvelopeSender::class);
        $envelopeSender->method('send')->willThrowException($exception);

        $circuitBreaker = $this->createStub(CircuitBreaker::class);
        $circuitBreaker->method('allowRequest')->willReturn(true);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Sentry envelope delivery failed', ['exception' => $exception]);

        $this->createConsumer($envelopeSender, $circuitBreaker, null, $logger)->process('envelope-bytes');

        $this->addToAssertionCount(1);
    }
}
