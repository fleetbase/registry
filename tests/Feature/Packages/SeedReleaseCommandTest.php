<?php

use App\Models\Category;
use App\Models\File;
use App\Models\Release;
use Illuminate\Support\Facades\Artisan;

function seedReleases(): array
{
    expect(Artisan::call('registry:seed', ['--seed-release' => true, '--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true);
}

it('fabricates a free and a paid release that the package endpoints serve', function () {
    $seed = seedReleases();

    expect(collect($seed['extensions'])->pluck('paid', 'npm')->all())
        ->toBe(['@registry-demo/hello-engine' => false, '@registry-demo/pro-engine' => true]);

    $this->get('/npm/@registry-demo%2fhello-engine')->assertOk()->assertJsonPath('dist-tags.latest', '1.0.0');
    $this->get('/npm/@registry-demo/hello-engine/-/hello-engine-1.0.0.tgz')->assertOk();
    $this->get('/composer/packages.json')->assertJsonPath('available-packages', ['registry-demo/hello-api', 'registry-demo/pro-api']);
    $this->get('/composer/dist/registry-demo/hello-api/1.0.0.tar')->assertOk();

    $this->get('/npm/@registry-demo%2fpro-engine')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$seed['tokens']['unlicensed'])->get('/composer/p2/registry-demo/pro-api.json')->assertForbidden();
    $this->withHeader('Authorization', 'Bearer '.$seed['tokens']['licensed'])->get('/composer/p2/registry-demo/pro-api.json')->assertOk();
    $this->withHeader('Authorization', 'Bearer '.$seed['tokens']['licensed'])->get('/npm/@registry-demo/pro-engine/-/pro-engine-1.0.0.tgz')->assertOk();
});

it('is safe to re-run and reissues the tokens', function () {
    $first = seedReleases();
    $second = seedReleases();

    expect(Release::count())->toBe(2)
        ->and(File::count())->toBe(4)
        ->and($second['tokens']['licensed'])->not->toBe($first['tokens']['licensed']);

    $this->withHeader('Authorization', 'Bearer '.$first['tokens']['licensed'])->get('/npm/@registry-demo%2fpro-engine')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.$second['tokens']['licensed'])->get('/npm/@registry-demo%2fpro-engine')->assertOk();
});

it('seeds only reference data without the flag and refuses production without --force', function () {
    $this->artisan('registry:seed')->assertSuccessful();
    expect(Release::count())->toBe(0)->and(Category::count())->toBe(41);

    app()->detectEnvironment(fn () => 'production');
    $this->artisan('registry:seed', ['--seed-release' => true])->assertFailed();
});
