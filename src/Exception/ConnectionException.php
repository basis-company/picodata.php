<?php

declare(strict_types=1);

namespace Basis\Picodata\Exception;

/**
 * The host is unreachable: connect failed or the connection dropped
 * mid-statement. A retry against another host may succeed — this is what
 * Driver\Pool fails over on. A statement the server *rejected* is an
 * ExecutionException instead and must not be retried anywhere.
 */
final class ConnectionException extends PicodataException
{
}
