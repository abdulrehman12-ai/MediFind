<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Medicines;

use App\Filament\Admin\Resources\Medicines\Pages\CreateMedicine;
use App\Filament\Admin\Resources\Medicines\Pages\EditMedicine;
use App\Filament\Admin\Resources\Medicines\Pages\ListMedicines;
use App\Models\Medicine;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class MedicineResource extends Resource
{
    protected static ?string $model = Medicine::class;

    protected static ?string $navigationLabel = 'Medicines';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('generic_name')->required()->maxLength(180),
            TextInput::make('brand_name')->maxLength(180),
            TextInput::make('strength')->maxLength(80),
            TextInput::make('dosage_form')->required()->maxLength(80),
            TextInput::make('manufacturer')->maxLength(180),
            Toggle::make('prescription_required'),
            Toggle::make('controlled_drug'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('generic_name'),
            TextColumn::make('strength'),
            TextColumn::make('dosage_form'),
            TextColumn::make('manufacturer'),
            TextColumn::make('status'),
            TextColumn::make('controlled_drug'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMedicines::route('/'),
            'create' => CreateMedicine::route('/create'),
            'edit' => EditMedicine::route('/{record}'),
        ];
    }
}
