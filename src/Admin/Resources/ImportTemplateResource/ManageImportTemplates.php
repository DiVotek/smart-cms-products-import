<?php

namespace SmartCms\ImportExport\Admin\Resources\ImportTemplateResource;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
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
