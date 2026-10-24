<?php

namespace App\Http\Resources;

class ReleaseCheckResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        $output = $this->output ?? [];

        return [
            'check' => $this->check,
            'passed' => (bool) $this->passed,
            'skipped' => (bool) ($output['skipped'] ?? false),
            'summary' => $output['summary'] ?? null,
            'errors' => $output['errors'] ?? [],
            'warnings' => $output['warnings'] ?? [],
            'output' => $output,
            'updated_at' => $this->updated_at,
        ];
    }
}
