<?php

declare(strict_types=1);

namespace JustBetter\Sentry\Test\Unit\Model;

use JustBetter\Sentry\Model\RateLimitParser;
use PHPUnit\Framework\TestCase;
use Sentry\HttpClient\Response;

class RateLimitParserTest extends TestCase
{
    /**
     * @dataProvider retryAtProvider
     *
     * @param array<string, array<int, string>> $headers
     */
    public function testGetRetryAt(array $headers, ?int $expectedOffsetSeconds): void
    {
        $parser = new RateLimitParser();
        $retryAt = $parser->getRetryAt(new Response(429, $headers, ''));

        if ($expectedOffsetSeconds === null) {
            $this->assertNull($retryAt);

            return;
        }

        $this->assertEqualsWithDelta(time() + $expectedOffsetSeconds, $retryAt, 2);
    }

    /**
     * @return array<string, array{0: array<string, array<int, string>>, 1: int|null}>
     */
    public static function retryAtProvider(): array
    {
        return [
            'rate limits header single entry'                  => [['X-Sentry-Rate-Limits' => ['120:error:key']], 120],
            'rate limits header takes max across all entries'  => [
                ['X-Sentry-Rate-Limits' => ['30:error:key,90:transaction:key']],
                90,
            ],
            'rate limits header skips non-digit retry_after'   => [
                ['X-Sentry-Rate-Limits' => ['abc:error:key,45:transaction:key']],
                45,
            ],
            'rate limits header with only non-digit entries is unusable' => [
                ['X-Sentry-Rate-Limits' => ['abc:error:key']],
                null,
            ],
            'retry-after numeric seconds'                      => [['Retry-After' => ['30']], 30],
            'retry-after rfc1123 future date'                  => [
                ['Retry-After' => [(new \DateTimeImmutable('+1 year'))->format(\DateTimeImmutable::RFC1123)]],
                (new \DateTimeImmutable('+1 year'))->getTimestamp() - time(),
            ],
            'retry-after rfc1123 past date is unusable'        => [
                ['Retry-After' => [(new \DateTimeImmutable('-1 year'))->format(\DateTimeImmutable::RFC1123)]],
                null,
            ],
            'retry-after unparseable value is unusable'        => [['Retry-After' => ['not-a-date']], null],
            'no rate-limit headers at all'                     => [[], null],
        ];
    }
}
