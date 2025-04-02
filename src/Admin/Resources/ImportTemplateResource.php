<?php

namespace SmartCms\ImportExport\Admin\Resources;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\DeleteAction as ActionsDeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use SmartCms\ImportExport\Admin\Resources\ImportTemplateResource\ManageImportTemplates;
use SmartCms\ImportExport\Models\ImportTemplate;
use SmartCms\ImportExport\Services\ImportExportService;
use SmartCms\Store\Models\Attribute;

class ImportTemplateResource extends Resource
{
    protected static ?string $model = ImportTemplate::class;

    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-down';

    public static function getNavigationSort(): ?int
    {
        return 100;
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

    public static function getNavigationGroup(): ?string
    {
        return _nav('modules');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        $multiLangName = [];
        $seoFields = [];
        $referenceAttributes = [];
        $attributes = Attribute::query()->select('id', 'name')->get();
        foreach ($attributes as $attribute) {
            $referenceAttributes['attribute_'.$attribute->id] = $attribute->name;
        }
        foreach (get_active_languages() as $lang) {
            $multiLangName['name_'.$lang->slug] = 'Name ('.$lang->name.')';
            $seoFields['title_'.$lang->slug] = 'Title ('.$lang->name.')';
            $seoFields['heading_'.$lang->slug] = 'Heading ('.$lang->name.')';
            $seoFields['summary_'.$lang->slug] = 'Summary ('.$lang->name.')';
            $seoFields['content_'.$lang->slug] = 'Content ('.$lang->name.')';
            $seoFields['description_'.$lang->slug] = 'Meta Description ('.$lang->name.')';
        }
        $fieldList = [
            ...$multiLangName,
            'sku' => 'SKU',
            'categories' => 'Categories',
            'stock_status_id' => 'Stock Status',
            'sorting' => 'Sorting',
            'status' => 'Status',
            'images' => 'Images',
            'is_index' => 'Index',
            'is_merchant' => 'Merchant',
            'created_at' => 'Created At',
            ...$seoFields,
            ...$referenceAttributes,
        ];
        Event::dispatch('cms.admin.import-template.fields', [&$fieldList]);

        return $form
            ->schema([
                TextInput::make('name')
                    ->required(),
                CheckboxList::make('fields')
                    ->helperText(__('import_export::trans.fields_helper_text'))
                    ->bulkToggleable()
                    ->options($fieldList)
                    ->columns(2),
            ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                ActionGroup::make([
                    Action::make('export')->icon('heroicon-o-cloud-arrow-up')
                        ->label(__('import_export::trans.export_csv'))
                        ->url(function (ImportTemplate $record) {
                            $expires = now()->addMinutes(30);
                            $signedUrl = URL::temporarySignedRoute(
                                'admin.import-template.export',
                                $expires,
                                ['record' => $record->id]
                            );

                            return $signedUrl;
                        }),
                    Action::make('exportToGoogleSheets')
                        ->label(__('import_export::trans.export_to_google_sheets'))
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->action(function (ImportTemplate $record) {
                            $service = new ImportExportService($record);
                            $service->exportToGoogleSheets();
                        })->visible(function (ImportTemplate $record) {
                            return setting('import_export.google_sheets_enabled', false);
                        }),
                    Action::make('import')->icon('heroicon-o-cloud-arrow-down')
                        ->label(__('import_export::trans.import_csv'))
                        ->form([
                            FileUpload::make('file')
                                ->required()
                                ->acceptedFileTypes(['text/csv'])
                                ->disk('public')
                                ->directory('imports'),
                        ])->action(function (ImportTemplate $record, $data) {
                            $service = new ImportExportService($record);
                            $result = $service->import($data['file']);
                            Notification::make()->title('Import finished. Success: '.$result['success'].' Errors: '.$result['errors'])->success()->send();
                        }),
                    Action::make('importFromGoogleSheets')
                        ->label(__('import_export::trans.import_from_google_sheets'))
                        ->icon('heroicon-o-cloud-arrow-down')
                        ->action(function (ImportTemplate $record) {
                            $service = new ImportExportService($record);
                            try {
                                $result = $service->importFromGoogleSheets();

                                Notification::make()
                                    ->title('Import Complete')
                                    ->body("Successfully imported {$result['success']} products. Failed: {$result['errors']}.")
                                    ->success()
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Import Failed')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        })
                        ->visible(function (ImportTemplate $record) {
                            return setting('import_export.google_sheets_enabled', false) &&
                                ! empty($record->google_sheets_link);
                        }),
                    Action::make('copy_link')->icon('heroicon-o-link')->url(fn (ImportTemplate $record) => 'https://docs.google.com/spreadsheets/d/'.$record->google_sheets_link.'/edit?usp=sharing')->visible(fn (ImportTemplate $record) => $record->google_sheets_link)->openUrlInNewTab(),
                ]),
                EditAction::make(),
                ActionsDeleteAction::make(),
            ])
            ->filters([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageImportTemplates::route('/'),
        ];
    }
}
