<?php

namespace SmartCms\ImportExport\Admin\Resources\ImportTemplateResource;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Http\Request;
use SmartCms\ImportExport\Admin\Resources\ImportTemplateResource;
use SmartCms\ImportExport\Models\ImportTemplate;
use SmartCms\ImportExport\Services\ImportExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManageImportTemplates extends ManageRecords
{
    protected static string $resource = ImportTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('help')
                ->help(_hints('help.contact_form')),

            Action::make('Settings')
                ->settings()
                ->fillForm(function (): array {
                    return [
                        'google_sheets_admin_emails' => setting('import_export.google_sheets_admin_emails', ''),
                        'google_sheets_enabled' => setting('import_export.google_sheets_enabled', false),
                        'google_sheets_service_account_json' => setting('import_export.google_sheets_service_account_json'),
                    ];
                })
                ->action(function (array $data): void {
                    setting([
                        'import_export.google_sheets_admin_emails' => $data['google_sheets_admin_emails'],
                        'import_export.google_sheets_enabled' => $data['google_sheets_enabled'],
                        'import_export.google_sheets_service_account_json' => $data['google_sheets_service_account_json'],
                    ]);
                })
                ->form(function ($form) {
                    return $form
                        ->schema([
                            TextInput::make('google_sheets_admin_emails')
                                ->label('Admin Emails')
                                ->helperText('Enter the email addresses of the admins who should have access to the Google Sheets (comma separated)')
                                ->columnSpanFull(),
                            Toggle::make('google_sheets_enabled')
                                ->label('Enable Google Sheets Integration')
                                ->live()
                                ->helperText('Turn on to allow import/export directly to Google Sheets'),
                            Textarea::make('google_sheets_service_account_json')
                                ->label('Service Account JSON Key')
                                ->helperText('Paste the entire JSON content of your service account key file')
                                ->visible(fn ($get) => $get('google_sheets_enabled'))
                                ->columnSpanFull(),
                        ]);
                }),
            CreateAction::make(),
        ];
    }

    public function export(Request $request, $recordId): StreamedResponse
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid signature');
        }
        $record = ImportTemplate::find($recordId);
        $service = new ImportExportService($record);

        return $service->export();
    }
}
