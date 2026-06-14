<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorResource\Pages;

use App\Filament\Resources\InstructorResource;
use Filament\Resources\Pages\ViewRecord;

// Intentionally has no `getHeaderActions()` override — `ViewRecord` already
// renders a breadcrumb back to the list, and a custom action would duplicate it.
class ViewInstructor extends ViewRecord
{
    protected static string $resource = InstructorResource::class;
}
