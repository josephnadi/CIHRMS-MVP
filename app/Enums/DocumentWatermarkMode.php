<?php

namespace App\Enums;

enum DocumentWatermarkMode: string
{
    case None   = 'none';
    case OnBurn = 'on_burn';
    case Always = 'always';
}
