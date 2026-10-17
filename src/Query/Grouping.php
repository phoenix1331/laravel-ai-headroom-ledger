<?php

declare(strict_types=1);

namespace Phoenix1331\AiHeadroomLedger\Query;

/**
 * @internal
 */
enum Grouping: string
{
    case Model = 'model';
    case Provider = 'provider';
    case Agent = 'agent';
    case Day = 'day';
    case Run = 'run';
    case Tag = 'tag';
}
