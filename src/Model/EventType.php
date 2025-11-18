<?php

declare(strict_types=1);

namespace Botbye\Model;

enum EventType: string
{
    case LOGIN = 'LOGIN';
    case REGISTRATION = 'REGISTRATION';
    case CUSTOM = 'CUSTOM';
}
