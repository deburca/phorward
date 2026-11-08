<?php

declare(strict_types=1);

namespace Phorward\Verify;

/**
 * A patch from the server that is not safe or not possible to apply; the message says why.
 */
final class PatchRejected extends \RuntimeException {}
