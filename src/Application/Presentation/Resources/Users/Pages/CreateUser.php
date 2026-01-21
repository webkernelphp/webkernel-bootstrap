<?php

namespace Webkernel\Presentation\Resources\Users\Pages;

use Webkernel\Presentation\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;
}
