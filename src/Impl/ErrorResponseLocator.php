<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Rtry\Contracts\ErrorResponse;
use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Finds the HTTP response on a thrown error, wherever it sits. It walks the error and its
 * getPrevious() chain, outermost first, and on each one tries in turn:
 *
 * 1. the caller's reader, if one was given (a reader adds to the rest, never replaces it);
 * 2. getResponse() returning a response with getStatusCode(): PSR-7 (Guzzle, PSR-18 clients),
 *    or Symfony HttpClient's, whose headers are read with getHeaders(false);
 * 3. a public $response property with status() returning 100 to 599, and headers(): the
 *    shape of Laravel's HTTP client RequestException, which has no getResponse() and whose
 *    message carries the response body;
 * 4. an HTTP status accessor on the error itself: getStatusCode() (AWS SDK, Symfony
 *    HttpKernel), getHttpStatus() (Stripe) or getHttpStatusCode(), returning 100 to 599.
 *
 * The exception code is never read here: a code from 400 to 599 is as often SMTP's 421 or a
 * driver's 409 as an HTTP status. A status below 100 counts as no response. Never throws.
 * The answer for each error is remembered for as long as the error lives, so the rules that
 * share one locator read each error once.
 */
final class ErrorResponseLocator implements ErrorResponseReaderInterface
{
    private const MAX_DEPTH = 16;
    private const STATUS_ACCESSORS = ['getStatusCode', 'getHttpStatus', 'getHttpStatusCode'];

    /** @var \WeakMap<\Throwable, ErrorResponse|false> */
    private \WeakMap $seen;

    public function __construct(private readonly ?ErrorResponseReaderInterface $reader = null)
    {
        $this->seen = new \WeakMap();
    }

    /** $reader itself when it is already a locator, otherwise a locator that tries it first. */
    public static function wrap(?ErrorResponseReaderInterface $reader): self
    {
        return $reader instanceof self ? $reader : new self($reader);
    }

    public function read(\Throwable $e): ?ErrorResponse
    {
        if (isset($this->seen[$e])) {
            return $this->seen[$e] ?: null;
        }
        $found = null;
        for ($t = $e, $depth = 0; $t !== null && $depth < self::MAX_DEPTH; $t = $t->getPrevious(), $depth++) {
            $found = $this->readOne($t);
            if ($found !== null) {
                break;
            }
        }
        $this->seen[$e] = $found ?? false;
        return $found;
    }

    private function readOne(\Throwable $t): ?ErrorResponse
    {
        if ($this->reader !== null) {
            try {
                $response = $this->reader->read($t);
                if ($response !== null && $response->statusCode >= 100) {
                    return $response;
                }
            } catch (\Throwable) {
                // a reader that throws found nothing
            }
        }
        return self::fromGetResponse($t) ?? self::fromResponseProperty($t) ?? self::fromAccessor($t);
    }

    private static function fromGetResponse(\Throwable $t): ?ErrorResponse
    {
        if (!method_exists($t, 'getResponse')) {
            return null;
        }
        try {
            $response = $t->getResponse();
            if (!is_object($response) || !method_exists($response, 'getStatusCode')) {
                return null;
            }
            $status = $response->getStatusCode();
        } catch (\Throwable) {
            return null;
        }
        if (!is_int($status) || $status < 100) {
            return null;
        }

        $headers = [];
        if (method_exists($response, 'getHeaders')) {
            try {
                // Symfony's getHeaders() throws on a 4xx/5xx unless passed false; PSR-7's takes none.
                $headers = $response instanceof ResponseInterface ? $response->getHeaders() : $response->getHeaders(false);
            } catch (\Throwable) {
                $headers = [];
            }
        }
        return new ErrorResponse($status, is_array($headers) ? $headers : []);
    }

    private static function fromResponseProperty(\Throwable $t): ?ErrorResponse
    {
        // Public properties only: get_object_vars() from here sees no private or protected one.
        $response = get_object_vars($t)['response'] ?? null;
        if (!is_object($response) || !method_exists($response, 'status')) {
            return null;
        }
        try {
            $status = $response->status();
        } catch (\Throwable) {
            return null;
        }
        if (!is_int($status) || $status < 100 || $status > 599) {
            return null;
        }

        $headers = [];
        if (method_exists($response, 'headers')) {
            try {
                $headers = $response->headers();
            } catch (\Throwable) {
                $headers = [];
            }
        }
        return new ErrorResponse($status, is_array($headers) ? $headers : []);
    }

    private static function fromAccessor(\Throwable $t): ?ErrorResponse
    {
        foreach (self::STATUS_ACCESSORS as $method) {
            if (!method_exists($t, $method)) {
                continue;
            }
            try {
                $status = $t->{$method}();
            } catch (\Throwable) {
                continue;
            }
            if (is_int($status) && $status >= 100 && $status <= 599) {
                return new ErrorResponse($status);
            }
        }
        return null;
    }
}
