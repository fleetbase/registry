<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->publicPath = sys_get_temp_dir().'/registry-public-'.uniqid();
    File::ensureDirectoryExists($this->publicPath.'/assets');
    app()->usePublicPath($this->publicPath);
});

afterEach(function () {
    File::deleteDirectory($this->publicPath);
});

it('serves the website shell for every other GET path', function () {
    file_put_contents($this->publicPath.'/index.html', '<!doctype html><title>Registry</title>');
    file_put_contents($this->publicPath.'/assets/app.js', 'console.log(1);');

    foreach (['/', '/extensions/fleet-ops', '/publisher/listings/new?tab=media', '/admin/queue'] as $path) {
        $response = $this->get($path)->assertOk();
        expect($response->headers->get('Content-Type'))->toStartWith('text/html')
            ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
            ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe('<!doctype html><title>Registry</title>')
            ->and($response->headers->getCookies())->toBe([]);
    }

    // A built file the web server did not serve itself.
    $asset = $this->get('/assets/app.js')->assertOk();
    expect($asset->baseResponse->getFile()->getPathname())->toBe(realpath($this->publicPath.'/assets/app.js'));
});

it('answers 503 until the website is built', function () {
    $this->get('/extensions')
        ->assertStatus(503)
        ->assertExactJson(['errors' => ['The website has not been built. Run npm run build in web/.']]);
});

it('keeps API, package and health paths out of the website', function () {
    file_put_contents($this->publicPath.'/index.html', '<!doctype html>');

    $this->get('/v1/nope')->assertNotFound()->assertExactJson(['errors' => ['Resource not found.']]);
    $this->get('/v1/catalog/nope/deeper')->assertNotFound()->assertExactJson(['errors' => ['Resource not found.']]);
    $this->postJson('/v1/nope')->assertNotFound()->assertExactJson(['errors' => ['Resource not found.']]);
    $this->get('/composer/nope')->assertNotFound()->assertJsonPath('error', 'Not found.');
    $this->get('/npm/-/nope/deeper')->assertNotFound();
    $this->get('/up')->assertOk();
    $this->postJson('/extensions')->assertNotFound();
});

it('moves the service info to GET /v1', function () {
    $this->getJson('/v1')
        ->assertOk()
        ->assertJsonPath('service', config('app.name'))
        ->assertJsonPath('version', config('registry.version'))
        ->assertJsonPath('catalog', url('/v1/catalog/extensions'));
});
