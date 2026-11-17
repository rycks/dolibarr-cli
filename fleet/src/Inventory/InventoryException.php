<?php

declare(strict_types=1);

namespace Dolifleet\Inventory;

use RuntimeException;

/**
 * Invalid inventory or target: usage error of the pilot (exit code 2).
 */
final class InventoryException extends RuntimeException
{
}
