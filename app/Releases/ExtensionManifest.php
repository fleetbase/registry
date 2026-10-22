<?php

namespace App\Releases;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates an `extension.json` manifest against the JSON Schema (draft 2020-12) shared with
 * registry-bridge (`resources/schemas/extension.schema.json`, copied from the bridge's
 * `phase-0/central-catalog` branch; keep the two in step).
 */
class ExtensionManifest
{
    public const MAX_ERRORS = 25;

    public static function schemaPath(): string
    {
        return resource_path('schemas/extension.schema.json');
    }

    /**
     * Decode manifest JSON. Null when it is not a JSON object.
     */
    public static function decode(string $json): ?array
    {
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json);
        $decoded = json_decode($json, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    /**
     * Validation errors as `pointer: message` lines; empty when the manifest is valid.
     *
     * @return list<string>
     */
    public static function validate(array $manifest): array
    {
        // Opis expects JSON objects as stdClass.
        $data = json_decode((string) json_encode($manifest, JSON_UNESCAPED_SLASHES));

        $schema = json_decode((string) file_get_contents(static::schemaPath()), false);
        if (! is_object($schema)) {
            throw new \RuntimeException('Unable to load the extension manifest schema from '.static::schemaPath());
        }

        $validator = new Validator;
        $validator->setMaxErrors(self::MAX_ERRORS);

        $result = $validator->validate($data, $schema);
        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();
        if ($error === null) {
            return ['/: The manifest is invalid.'];
        }

        $lines = [];
        foreach ((new ErrorFormatter)->formatKeyed($error) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = trim(($pointer === '' ? '/' : $pointer).': '.$message);
            }
        }

        return $lines;
    }

    /**
     * Whether a manifest `engine` / `api` value names a package (rather than pointing at
     * `package.json` / `composer.json`).
     */
    public static function namesPackage(mixed $reference): bool
    {
        return is_string($reference) && $reference !== '' && ! in_array($reference, ['package.json', 'composer.json'], true);
    }
}
