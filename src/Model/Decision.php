<?php

declare(strict_types=1);

namespace Botbye\Model;

enum Decision: string
{
    case ALLOW = 'ALLOW';
    case BLOCK = 'BLOCK';
    case MFA = 'MFA';
    case CHALLENGE = 'CHALLENGE';
    case IN_PROGRESS = 'IN_PROGRESS';
}
