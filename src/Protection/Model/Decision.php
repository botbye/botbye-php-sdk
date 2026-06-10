<?php

declare(strict_types=1);

namespace Botbye\Protection\Model;

enum Decision: string
{
    case ALLOW = 'ALLOW';
    case CHALLENGE = 'CHALLENGE';
    case BLOCK = 'BLOCK';
}
