<?php

namespace App\Enums;

enum AssetSource: string
{
    case Upload = 'upload';
    case Generated = 'generated';
    case Imported = 'imported';
    case Derived = 'derived';
}
