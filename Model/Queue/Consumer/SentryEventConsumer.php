<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Model\Queue\Consumer;

use JustBetter\Sentry\Helper\Data;
use JustBetter\Sentry\Model\CircuitBreaker;
use JustBetter\Sentry\Model\Transport\EnvelopeSender;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers queued Sentry envelopes as-is (fire-time timestamps already in payload).
 *
 * Never throws: Magento would reject the message without requeue anyway, and log it
 * through a Sentry-attached logger, which feeds the failure back into this queue.
 */
class SentryEventConsumer
{
    /**
     * @param EnvelopeSender  $envelopeSender
     * @param CircuitBreaker  $circuitBreaker
     * @param Data            $helper
     * @param LoggerInterface $logger File-only channel, excluded from Sentry by MonologPlugin
     */
    public function __construct(
        private readonly EnvelopeSender $envelopeSender,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly Data $helper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Deliver a queued Sentry envelope; drop it while the circuit is open.
     *
     * @param string $payload Serialized Sentry envelope
     */
    public function process(string $payload): void
    {
        if (!$this->helper->isActive() || $payload === '' || !$this->circuitBreaker->allowRequest()) {
            return;
        }

        try {
            $this->envelopeSender->send($payload);
        } catch (Throwable $exception) {
            $this->logger->warning('Sentry envelope delivery failed', ['exception' => $exception]);
        }
    }
}
