<?php

namespace SmartCms\ImportExport\Resources;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use SmartCms\Core\Models\Field;
use SmartCms\ImportExport\Models\RequiredFieldTemplates;

class RequiredaFieldTemplatesResource extends Resource
{
    protected static ?string $model = RequiredFieldTemplates::class;

    protected static ?string $navigationIcon = 'heroicon-o-collection';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required(),
                CheckboxList::make('fields')
                    ->options([
                        Field::all()->pluck('name', 'id')
                    ])
                    ->columns(2)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([]);
    }
}
