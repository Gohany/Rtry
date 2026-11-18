<?php declare(strict_types=1);

namespace Gohany\Rtry\Impl;

use Gohany\Rtry\Contracts\ErrorResponseReaderInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The default reader: a PSR-7 response returned by the error's getResponse() (Guzzle, and
 * most PSR-18 clients' exceptions).
 */
final class Psr7ErrorResponseReader implements ErrorResponseReaderInterface
{
    public function read(\Throwable $e): ?ErrorResponse
    {
        if (!method_exists($e, 'getResponse')) {
            return null;
        }
        try {
            $response = $e->getResponse();
            if (!$response instanceof ResponseInterface) {
                return null;
            }
            return new ErrorResponse($response->getStatusCode(), $response->getHeaders());
        } catch (\Throwable) {
            return null;
        }
    }
}
