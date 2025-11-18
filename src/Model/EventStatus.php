<?php

declare(strict_types=1);

namespace Botbye\Model;

enum EventStatus: string
{
    case SUCCESSFUL = 'SUCCESSFUL';
    case FAILED = 'FAILED';
    case PENDING = 'PENDING';
}
