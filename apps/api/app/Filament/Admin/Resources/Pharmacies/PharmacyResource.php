<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Pharmacies;

use App\Filament\Admin\Resources\Pharmacies\Pages\CreatePharmacy;
use App\Filament\Admin\Resources\Pharmacies\Pages\EditPharmacy;
use App\Filament\Admin\Resources\Pharmacies\Pages\ListPharmacies;
use App\Models\Pharmacy;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class PharmacyResource extends Resource
{
    protected static ?string $model = Pharmacy::class;

    protected static ?string $navigationLabel = 'Pharmacies';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(180),
            Textarea::make('address_text')->required()->maxLength(300),
            TextInput::make('phone_e164')->maxLength(20),
            TextInput::make('license_number')->maxLength(100),
            TextInput::make('latitude')->maxLength(20),
            TextInput::make('longitude')->maxLength(20),
            Textarea::make('pickup_instructions'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('status'),
                TextColumn::make('license_status'),
                TextColumn::make('location_review_status'),
            ])
            ->recordUrl(fn (Pharmacy $record): string => self::getUrl('edit', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPharmacies::route('/'),
            'create' => CreatePharmacy::route('/create'),
            'edit' => EditPharmacy::route('/{record}'),
        ];
    }
}
