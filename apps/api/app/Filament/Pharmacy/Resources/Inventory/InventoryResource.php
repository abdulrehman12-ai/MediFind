<?php

declare(strict_types=1);

namespace App\Filament\Pharmacy\Resources\Inventory;

use App\Filament\Pharmacy\Resources\Inventory\Pages\CreateInventory;
use App\Filament\Pharmacy\Resources\Inventory\Pages\EditInventory;
use App\Filament\Pharmacy\Resources\Inventory\Pages\ListInventory;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class InventoryResource extends Resource
{
    protected static ?string $model = PharmacyInventory::class;

    protected static ?string $navigationLabel = 'Inventory';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('medicine_id')
                ->options(fn (): array => Medicine::query()->where('status', 'active')->orderBy('generic_name')->pluck('generic_name', 'id')->all())
                ->required(),
            Select::make('stock_status')->options([
                'unknown' => 'Unknown',
                'in_stock' => 'In stock',
                'low_stock' => 'Low stock',
                'out_of_stock' => 'Out of stock',
            ])->required(),
            TextInput::make('quantity_on_hand')->numeric(),
            TextInput::make('unit_price_paisa')->numeric()->required()->minValue(0),
            Toggle::make('is_available_for_pickup'),
            TextInput::make('version')->numeric()->default(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('medicine.generic_name'),
            TextColumn::make('stock_status'),
            TextColumn::make('quantity_on_hand'),
            TextColumn::make('unit_price_paisa'),
            TextColumn::make('is_available_for_pickup'),
            TextColumn::make('version'),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = Filament::getTenant();

        if ($tenant instanceof Pharmacy) {
            $query->where('pharmacy_id', $tenant->getKey());
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInventory::route('/'),
            'create' => CreateInventory::route('/create'),
            'edit' => EditInventory::route('/{record}'),
        ];
    }
}
