<?php declare(strict_types=1);

/*
 * Stand-ins for the vendor exception types UnavailableEvidence and the classifier name, for
 * when the packages are not installed (they are not dev dependencies). Declared only when the
 * real class is missing; tests build them without a constructor, so the real ones work too.
 */

namespace GuzzleHttp\Exception {
    if (!class_exists(ConnectException::class)) {
        class ConnectException extends \RuntimeException
        {
        }
    }
}

namespace Doctrine\DBAL\Exception {
    if (!class_exists(ConnectionException::class)) {
        class ConnectionException extends \RuntimeException
        {
        }
    }
    if (!class_exists(ConnectionLost::class)) {
        class ConnectionLost extends \RuntimeException
        {
        }
    }
    if (!class_exists(DeadlockException::class)) {
        class DeadlockException extends \RuntimeException
        {
        }
    }
}

namespace Predis\Connection {
    if (!class_exists(ConnectionException::class)) {
        class ConnectionException extends \RuntimeException
        {
        }
    }
}
