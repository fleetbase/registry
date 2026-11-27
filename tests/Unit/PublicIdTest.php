<?php

use App\Models\Extension;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Release;
use App\Models\Token;

it('uses the public id prefixes from the plan', function () {
    expect(Extension::getPublicIdType())->toBe('ext')
        ->and(Release::getPublicIdType())->toBe('rel')
        ->and(Licence::getPublicIdType())->toBe('lic')
        ->and(Instance::getPublicIdType())->toBe('inst')
        ->and(Token::getPublicIdType())->toBe('tok');
});
