<?php

declare(strict_types=1);

namespace Botbye\Model;

enum Decision: string
{
    case ALLOW = 'ALLOW';
    case CHALLENGE = 'CHALLENGE';
    case BLOCK = 'BLOCK';
}
