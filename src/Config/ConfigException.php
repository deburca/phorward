<?php

declare(strict_types=1);

namespace Phorward\Config;

/**
 * A configuration or usage error: nothing was sent anywhere, and the caller should exit with status 2.
 */
final class ConfigException extends \RuntimeException {
}
