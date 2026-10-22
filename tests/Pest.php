<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\File;
use App\Models\Release;
use App\Models\Instance;
use App\Models\Token;
use App\Services\ReleaseStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Package tests run against a fake registry disk.
pest()->beforeEach(function () {
    config(['registry.storage.disk' => 'packages']);
    Storage::fake('packages');
})->in('Feature/Packages');

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

/**
 * A published listing owning one package of the given kind.
 */
function makePackage(string $kind, string $name, bool $paid = false, array $extension = []): ExtensionPackage
{
    $factory = Extension::factory()->published()->state($extension);
    $factory = $paid ? $factory->paid() : $factory->free();

    return $factory->create()->packages()->create(['kind' => $kind, 'name' => $name])->load('extension.price');
}

/**
 * A release of the package's extension whose archive holds a generated manifest. A second
 * call for the same version attaches the other kind's archive to the existing release.
 */
function makeRelease(ExtensionPackage $package, string $version, string $channel = Release::CHANNEL_STABLE, string $status = Release::STATUS_PUBLISHED, array $manifest = [], ?string $publishedAt = null): Release
{
    $npm = $package->kind === ExtensionPackage::KIND_NPM;
    $manifest = array_merge(['name' => $package->name], $npm ? ['version' => $version] : [], $manifest);
    $archive = makeTarball(['package/'.($npm ? 'package.json' : 'composer.json') => json_encode($manifest)]);

    $release = Release::query()->firstOrCreate(
        ['extension_uuid' => $package->extension_uuid, 'version' => $version],
        Release::factory()->raw([
            'extension_uuid' => $package->extension_uuid,
            'version' => $version,
            'channel' => $channel,
            'status' => $status,
            'published_at' => $publishedAt ?? now(),
            'yanked_at' => $status === Release::STATUS_YANKED ? now() : null,
        ]),
    );

    $file = app(ReleaseStorage::class)->store($archive, $npm ? File::TYPE_ENGINE_TARBALL : File::TYPE_API_TARBALL, $release, [
        'name' => $npm ? basename($package->name).'-'.$version.'.tgz' : str_replace('/', '-', $package->name).'-'.$version.'.tar',
    ]);
    $release->forceFill([$npm ? 'engine_tarball_file_uuid' : 'api_tarball_file_uuid' => $file->uuid])->save();

    return $release;
}

/**
 * Issue an instance token and authenticate subsequent requests with it.
 */
function actingAsInstance(Instance $instance): string
{
    $plain = $instance->issueToken();

    test()->withHeader('Authorization', 'Bearer '.$plain);

    return $plain;
}
