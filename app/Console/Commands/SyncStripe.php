<?php

namespace App\Console\Commands;

use App\Commerce\ConnectAccounts;
use App\Commerce\PriceCatalog;
use App\Commerce\Stripe;
use App\Models\Organization;
use App\Models\Price;
use Illuminate\Console\Command;

/**
 * `php artisan registry:stripe-sync` reads every connected account's state from Stripe and mirrors
 * every paid price that has no Stripe price yet: run once after `registry:import`, whose rows
 * carry Connect account and product ids but none of the state Checkout needs.
 */
class SyncStripe extends Command
{
    protected $signature = 'registry:stripe-sync {--dry-run : List what would be synced}';

    protected $description = 'Read connected accounts from Stripe and mirror paid prices that have no Stripe price';

    public function handle(ConnectAccounts $accounts, PriceCatalog $catalog): int
    {
        if (! Stripe::configured()) {
            $this->error('Set STRIPE_SECRET first.');

            return self::FAILURE;
        }

        $failed = 0;

        Organization::query()->whereNotNull('stripe_connect_id')->each(function (Organization $organization) use ($accounts, &$failed) {
            if ($this->option('dry-run')) {
                $this->line("account  {$organization->slug}  {$organization->stripe_connect_id}");

                return;
            }
            try {
                $accounts->refresh($organization);
                $this->line("account  {$organization->slug}  {$organization->payout_status}");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("account  {$organization->slug}  failed: {$e->getMessage()}");
            }
        });

        Price::query()->with('extension')->where('model', '!=', Price::MODEL_FREE)->where('amount', '>', 0)->whereNull('stripe_price_id')->each(function (Price $price) use ($catalog, &$failed) {
            if (! $price->extension) {
                return;
            }
            if ($this->option('dry-run')) {
                $this->line("price    {$price->extension->slug}  {$price->amount} {$price->currency}");

                return;
            }
            try {
                $catalog->sync($price, $price->extension)->save();
                $this->line("price    {$price->extension->slug}  {$price->stripe_price_id}");
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("price    {$price->extension->slug}  failed: {$e->getMessage()}");
            }
        });

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
