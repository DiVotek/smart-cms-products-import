<?php

namespace SmartCms\ImportExport\Events;

use SmartCms\ImportExport\Admin\Resources\ImportTemplateResource;

class AdminNavigationResources
{
    public function __invoke(array &$items)
    {
        $items = array_merge([
            ImportTemplateResource::class,
        ], $items);
    }
}
