<?php

declare(strict_types=1);

arch('no debugging calls are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'var_export', 'error_log', 'die', 'exit'])
    ->not->toBeUsed();

arch('every file declares strict types')
    ->expect(['Phoenix1331\AiHeadroomLedger', 'Phoenix1331\AiHeadroomLedger\Database\Factories'])
    ->toUseStrictTypes();

arch('php best practices')->preset()->php();

arch('no insecure functions')->preset()->security();

arch('enums are backed by their stored values')
    ->expect('Phoenix1331\AiHeadroomLedger\Enums')
    ->toBeStringBackedEnums();

arch('casts are final')
    ->expect('Phoenix1331\AiHeadroomLedger\Support')
    ->classes()
    ->toBeFinal();

arch('value objects are final and readonly')
    ->expect('Phoenix1331\AiHeadroomLedger\Data')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();
