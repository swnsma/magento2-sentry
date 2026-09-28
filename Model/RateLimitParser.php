<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Model;

use DateTimeImmutable;
use Sentry\HttpClient\Response;

/**
 * Reads the back-off time from a Sentry 429 response as one global limit across all categories.
 *
 * @see \Sentry\Transport\RateLimiter::handleResponse() header semantics; keeps per-category state in memory only
 */
class RateLimitParser
{
    private const RATE_LIMITS_HEADER = 'X-Sentry-Rate-Limits';
    private const RETRY_AFTER_HEADER = 'Retry-After';

    /**
     * Unix timestamp until which Sentry asked us to stop sending, or null when the response doesn't say.
     *
     * @param Response $response
     * @return int|null
     */
    public function getRetryAt(Response $response): ?int
    {
        $now = time();

        if ($response->hasHeader(self::RATE_LIMITS_HEADER)) {
            $seconds = null;
            // Entry format: retry_after:categories:scope:reason_code[:namespaces]
            foreach (explode(',', $response->getHeaderLine(self::RATE_LIMITS_HEADER)) as $limit) {
                $retryAfter = trim(explode(':', $limit, 2)[0]);
                if (ctype_digit($retryAfter)) {
                    $seconds = max($seconds ?? 0, (int) $retryAfter);
                }
            }

            return $seconds === null ? null : $now + $seconds;
        }

        if ($response->hasHeader(self::RETRY_AFTER_HEADER)) {
            $retryAfter = trim($response->getHeaderLine(self::RETRY_AFTER_HEADER));
            if (ctype_digit($retryAfter)) {
                return $now + (int) $retryAfter;
            }

            $date = DateTimeImmutable::createFromFormat(DateTimeImmutable::RFC1123, $retryAfter);
            if ($date && $date->getTimestamp() > $now) {
                return $date->getTimestamp();
            }
        }

        return null;
    }
}
