<?php

declare(strict_types=1);

use App\Filament\Resources\InstructorResource\Pages\ViewInstructor;
use Filament\Pages\Page;

it('does not declare any header actions (the Filament built-in breadcrumb handles navigation)', function () {
    // A custom getHeaderActions() would duplicate the built-in breadcrumb.
    // If a future PR adds one, this test fails and forces a deliberate
    // decision about whether the visual duplication is worth it.
    //
    // getHeaderActions is defined on the base Page class, so we assert the
    // declaring class is the framework class, not ViewInstructor.
    $reflection = new ReflectionClass(ViewInstructor::class);

    expect($reflection->hasMethod('getHeaderActions'))->toBeTrue();

    $method = $reflection->getMethod('getHeaderActions');
    $declaringClass = $method->getDeclaringClass()->getName();

    expect($declaringClass)
        ->toBe(Page::class)
        ->not->toBe(ViewInstructor::class);
});
