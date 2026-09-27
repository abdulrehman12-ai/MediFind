<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderRequestMode: string
{
    case CatalogItems = 'catalog_items';
    case PrescriptionSelectedItems = 'prescription_selected_items';
    case PrescriptionFullImage = 'prescription_full_image';
}
