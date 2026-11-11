<?php

declare(strict_types=1);

namespace Specrig;

enum Write: string
{
    case Insert = 'insert';
    case Update = 'update';
    case Delete = 'delete';
}
