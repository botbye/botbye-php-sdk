<?php

declare(strict_types=1);

namespace Botbye\Protection\Model;

enum EventStatus: string
{
    case SUCCESSFUL = 'SUCCESSFUL';
    case FAILED = 'FAILED';
    case ATTEMPTED = 'ATTEMPTED';
}
