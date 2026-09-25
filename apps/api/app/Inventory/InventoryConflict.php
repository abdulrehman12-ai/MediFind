<?php

declare(strict_types=1);

namespace App\Inventory;

use RuntimeException;

final class InventoryConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The inventory record was updated by someone else. Reload it and try again.');
    }
}
