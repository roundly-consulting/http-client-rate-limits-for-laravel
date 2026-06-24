<?php

declare(strict_types=1);

namespace RoundlyConsulting\HttpClientRateLimits\Exceptions;

use RuntimeException;

/**
 * Base exception for every error thrown by the package, so consumers can
 * catch the whole family with a single type.
 */
class RateLimitException extends RuntimeException {}
