<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown by LocalDiskSpaceGuard when a stage should not start because the
 * local work volume does not have enough free space. Treated as a
 * retryable/actionable failure, not a permanent one — retry once space has
 * been freed (e.g. after cleanup of stale work files).
 */
class InsufficientDiskSpaceException extends RuntimeException
{
}
