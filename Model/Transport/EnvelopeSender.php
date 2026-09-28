<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Model\Transport;

use JustBetter\Sentry\Helper\Data;
use JustBetter\Sentry\Model\CircuitBreaker;
use RuntimeException;
use Sentry\HttpClient\HttpClientInterface;
use Sentry\HttpClient\Request;
use Sentry\Options;
use Sentry\Transport\ResultStatus;

/**
 * Sends a pre-serialized Sentry envelope over HTTP (used by the async consumer).
 */
class EnvelopeSender
{
    /**
     * @param Data                $helper
     * @param HttpClientInterface $httpClient
     * @param CircuitBreaker      $circuitBreaker
     */
    public function __construct(
        private readonly Data $helper,
        private readonly HttpClientInterface $httpClient,
        private readonly CircuitBreaker $circuitBreaker
    ) {
    }

    /**
     * POST a pre-serialized envelope to the configured Sentry DSN and update the circuit breaker.
     *
     * Payload must already include fire-time fields (event "timestamp",
     * envelope "sent_at") — this method does not re-serialize or re-stamp.
     *
     * @param string $payload Envelope body produced at publish/fire time
     *
     * @throws RuntimeException When delivery fails for any reason other than a rate limit
     */
    public function send(string $payload): void
    {
        if ($payload === '') {
            throw new RuntimeException('Sentry envelope payload is empty.');
        }

        $dsn = $this->helper->getDSN();
        if (!is_string($dsn) || $dsn === '') {
            throw new RuntimeException('Sentry DSN is not configured.');
        }

        $options = new Options([
            'dsn'                  => $dsn,
            'http_timeout'         => $this->helper->getHttpTimeout(),
            'http_connect_timeout' => $this->helper->getHttpConnectTimeout(),
        ]);

        $request = new Request();
        $request->setStringBody($payload);

        $response = $this->httpClient->sendRequest($request, $options);
        $status = ResultStatus::createFromHttpStatusCode($response->getStatusCode());

        switch ($status) {
            case ResultStatus::success():
                $this->circuitBreaker->recordSuccess();

                return;
            case ResultStatus::rateLimit():
                // Expected while quota is exhausted; the breaker backs off, logging each probe is noise.
                $this->circuitBreaker->recordRateLimit($response);

                return;
            case ResultStatus::failed():
            case ResultStatus::unknown():
                $this->circuitBreaker->recordFailure();
                break;
            // invalid / contentTooLarge: rejected payload, says nothing about Sentry health
        }

        throw new RuntimeException(
            sprintf(
                'Sentry envelope delivery failed with HTTP %d (%s): %s',
                $response->getStatusCode(),
                $status,
                $response->getError()
            )
        );
    }
}
