<?php

namespace App\Console\Commands;

use App\Support\Maintenance;
use Illuminate\Console\Command;

/**
 * `php artisan registry:maintenance on` freezes publishing and checkout for the cutover window;
 * `off` lifts it; `status` shows it. Catalog, package downloads and Stripe webhooks keep working.
 */
class ManageMaintenance extends Command
{
    protected $signature = 'registry:maintenance
        {action=status : on, off or status}
        {--message= : Message shown to publishers and buyers (default: registry.maintenance.message)}';

    protected $description = 'Turn the cutover freeze (publishing and checkout answer 503) on or off';

    public function handle(): int
    {
        $action = strtolower((string) $this->argument('action'));
        if (! in_array($action, ['on', 'off', 'status'], true)) {
            $this->error("Unknown action [{$action}]: use on, off or status.");

            return self::INVALID;
        }

        if ($action === 'on') {
            Maintenance::enable($this->option('message') ?: null);
        } elseif ($action === 'off') {
            Maintenance::disable();
        }

        $status = Maintenance::status();
        $this->line(sprintf('Maintenance: <%s>%s</>%s', $status['active'] ? 'comment' : 'info', $status['active'] ? 'ON' : 'off', $status['since'] ? " since {$status['since']}" : ''));
        if ($status['active']) {
            $this->line('Message: '.$status['message']);
        }
        if ($action === 'off' && $status['forced']) {
            $this->warn('REGISTRY_MAINTENANCE=true still forces it on; unset it and restart the tasks.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
