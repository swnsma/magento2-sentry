<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Model;

use JustBetter\Sentry\Helper\Data;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Sentry\HttpClient\Response;

/**
 * Cache-backed circuit breaker for outbound Sentry HTTP calls.
 *
 * States: closed (normal) → open (fail fast) → half-open (probe) → closed.
 * Opens after repeated failures, or immediately on a Sentry rate limit (429).
 */
class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private const CACHE_KEY = 'justbetter_sentry_circuit_breaker';
    private const CACHE_TAG = 'JUSTBETTER_SENTRY_CIRCUIT_BREAKER';

    /**
     * @var array{state:string,failures:int,successes:int,opened_at:float,retry_at:float}|null
     */
    private ?array $state = null;

    /**
     * @param CacheInterface  $cache
     * @param Json            $serializer
     * @param Data            $helper
     * @param RateLimitParser $rateLimitParser
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Json $serializer,
        private readonly Data $helper,
        private readonly RateLimitParser $rateLimitParser
    ) {
    }

    /**
     * Whether an outbound Sentry delivery attempt is allowed.
     */
    public function allowRequest(): bool
    {
        $state = $this->getState();

        return match ($state['state']) {
            self::STATE_CLOSED, self::STATE_HALF_OPEN => true,
            self::STATE_OPEN => $this->tryTransitionToHalfOpen($state),
            default          => true,
        };
    }

    /**
     * Record a successful Sentry HTTP delivery.
     */
    public function recordSuccess(): void
    {
        $state = $this->getState();

        if ($state['state'] === self::STATE_HALF_OPEN) {
            $state['successes']++;
            if ($state['successes'] >= $this->helper->getCircuitBreakerSuccessThreshold()) {
                $this->resetToClosed();

                return;
            }
            $this->persist($state);

            return;
        }

        if ($state['failures'] !== 0 || $state['state'] !== self::STATE_CLOSED) {
            $this->resetToClosed();
        }
    }

    /**
     * Record a failed Sentry HTTP delivery.
     */
    public function recordFailure(): void
    {
        $state = $this->getState();

        if ($state['state'] === self::STATE_HALF_OPEN) {
            $this->openCircuit($this->helper->getCircuitBreakerFailureThreshold());

            return;
        }

        $state['failures']++;
        $state['successes'] = 0;

        if ($state['failures'] >= $this->helper->getCircuitBreakerFailureThreshold()) {
            $this->openCircuit($state['failures']);

            return;
        }

        $this->persist($state);
    }

    /**
     * Open the circuit right away until the time Sentry asked us to back off to.
     *
     * @param Response $response 429 response carrying X-Sentry-Rate-Limits / Retry-After
     */
    public function recordRateLimit(Response $response): void
    {
        $retryAt = $this->rateLimitParser->getRetryAt($response);
        if (!$retryAt || $retryAt <= time()) {
            $retryAt = time() + $this->helper->getCircuitBreakerRecoveryTimeout();
        }

        $this->persist([
            'state'     => self::STATE_OPEN,
            'failures'  => $this->getState()['failures'],
            'successes' => 0,
            'opened_at' => microtime(true),
            'retry_at'  => (float) $retryAt,
        ]);
    }

    /**
     * Load circuit breaker state from in-memory cache or Magento cache.
     *
     * @return array{state:string,failures:int,successes:int,opened_at:float,retry_at:float}
     */
    private function getState(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if (!is_string($cached) || $cached === '') {
            return $this->state = $this->closedState();
        }

        try {
            $decoded = $this->serializer->unserialize($cached);
        } catch (\InvalidArgumentException) {
            return $this->state = $this->closedState();
        }

        if (!is_array($decoded)) {
            return $this->state = $this->closedState();
        }

        return $this->state = [
            'state'     => (string) ($decoded['state'] ?? self::STATE_CLOSED),
            'failures'  => (int) ($decoded['failures'] ?? 0),
            'successes' => (int) ($decoded['successes'] ?? 0),
            'opened_at' => (float) ($decoded['opened_at'] ?? 0.0),
            'retry_at'  => (float) ($decoded['retry_at'] ?? 0.0),
        ];
    }

    /**
     * Transition from open to half-open once the rate limit or the recovery timeout has passed.
     *
     * @param array{state:string,failures:int,successes:int,opened_at:float,retry_at:float} $state
     */
    private function tryTransitionToHalfOpen(array $state): bool
    {
        $reopenAt = $state['retry_at'] > 0
            ? $state['retry_at']
            : $state['opened_at'] + $this->helper->getCircuitBreakerRecoveryTimeout();
        if ($state['opened_at'] <= 0 || microtime(true) < $reopenAt) {
            return false;
        }

        $this->persist([
            'state'     => self::STATE_HALF_OPEN,
            'failures'  => $state['failures'],
            'successes' => 0,
            'opened_at' => $state['opened_at'],
            'retry_at'  => 0.0,
        ]);

        return true;
    }

    /**
     * Default closed circuit state.
     *
     * @return array{state:string,failures:int,successes:int,opened_at:float,retry_at:float}
     */
    private function closedState(): array
    {
        return [
            'state'     => self::STATE_CLOSED,
            'failures'  => 0,
            'successes' => 0,
            'opened_at' => 0.0,
            'retry_at'  => 0.0,
        ];
    }

    /**
     * Reset circuit to closed and clear counters.
     */
    private function resetToClosed(): void
    {
        $this->persist($this->closedState());
    }

    /**
     * Open the circuit after failure threshold is reached.
     *
     * @param int $failures Current failure count
     */
    private function openCircuit(int $failures): void
    {
        $this->persist([
            'state'     => self::STATE_OPEN,
            'failures'  => $failures,
            'successes' => 0,
            'opened_at' => microtime(true),
            'retry_at'  => 0.0,
        ]);
    }

    /**
     * Persist circuit state to memory and Magento cache.
     *
     * @param array{state:string,failures:int,successes:int,opened_at:float,retry_at:float} $state
     */
    private function persist(array $state): void
    {
        $this->state = $state;
        // Outlive both the recovery window and a rate-limit backoff, so open state survives across requests.
        $recoveryTimeout = $this->helper->getCircuitBreakerRecoveryTimeout();
        $ttl = max(
            300,
            $recoveryTimeout * 5,
            (int) ceil($state['retry_at'] - microtime(true)) + $recoveryTimeout
        );
        $this->cache->save(
            (string) $this->serializer->serialize($state),
            self::CACHE_KEY,
            [self::CACHE_TAG],
            $ttl
        );
    }
}
