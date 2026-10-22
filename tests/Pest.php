<?php

use App\Models\Account;
use App\Models\Token;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Issue a token for the account and authenticate subsequent requests with it.
 */
function actingAsAccount(Account $account, array $scopes = [Token::SCOPE_ALL]): Token
{
    $token = Token::issue($account, 'test', $scopes);

    test()->withHeader('Authorization', 'Bearer '.$token->plainTextToken);

    return $token;
}

/**
 * Write a gzipped ustar archive of `path => contents` and return its path. Paths longer than
 * 100 bytes go through a GNU long-name entry, as npm and GNU tar write them.
 */
function makeTarball(array $files, bool $gzip = true): string
{
    $block = fn (string $data) => $data.str_repeat("\0", (512 - strlen($data) % 512) % 512);
    $header = function (string $name, int $size, string $type = '0'): string {
        $h = str_pad(substr($name, 0, 100), 100, "\0")
            .str_pad('0000644', 8, "\0").str_pad('0000000', 8, "\0").str_pad('0000000', 8, "\0")
            .str_pad(decoct($size), 11, '0', STR_PAD_LEFT)."\0"
            .str_pad(decoct(0), 11, '0', STR_PAD_LEFT)."\0"
            .'        '.$type.str_repeat("\0", 100)."ustar\0".'00'.str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
        $sum = array_sum(array_map('ord', str_split($h)));

        return substr_replace($h, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);
    };

    $tar = '';
    foreach ($files as $name => $contents) {
        if (strlen($name) > 100) {
            $tar .= $header('././@LongLink', strlen($name) + 1, 'L').$block($name."\0");
        }
        $tar .= $header($name, strlen($contents)).$block($contents);
    }
    $tar .= str_repeat("\0", 1024);

    $path = tempnam(sys_get_temp_dir(), 'tar');
    file_put_contents($path, $gzip ? gzencode($tar) : $tar);

    return $path;
}

function fixturePackage(string $file): string
{
    return base_path('database/fixtures/packages/'.$file);
}
