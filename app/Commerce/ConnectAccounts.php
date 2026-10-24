<?php

namespace App\Commerce;

use App\Models\Account;
use App\Models\Event;
use App\Models\Organization;
use Stripe\Account as StripeAccount;
use Stripe\AccountSession;

/**
 * Stripe Connect Express accounts for publisher organizations: one account per organization,
 * onboarding and management through Account Sessions (embedded components on the website), and
 * `payout_status` kept from `account.updated`.
 */
class ConnectAccounts
{
    /** Embedded components an Account Session enables. */
    public const COMPONENTS = ['account_onboarding', 'account_management', 'notification_banner', 'payouts', 'tax_registrations', 'tax_settings'];

    /**
     * Create the organization's Express account, or return the organization unchanged when it
     * has one. The platform pays Stripe's fees and carries losses, as the console's accounts do.
     */
    public function create(Organization $organization, Account $by): Organization
    {
        if ($organization->hasStripeAccount()) {
            return $organization;
        }

        $params = array_filter([
            'controller' => [
                'stripe_dashboard' => ['type' => 'express'],
                'fees' => ['payer' => 'application'],
                'losses' => ['payments' => 'application'],
            ],
            'country' => config('registry.commerce.connect_country'),
            'email' => $by->email,
            'business_profile' => array_filter(['name' => $organization->name, 'url' => $organization->website]),
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => ['organization' => $organization->public_id, 'organization_uuid' => $organization->uuid],
        ], fn ($value) => $value !== null && $value !== []);

        $account = Stripe::client()->accounts->create($params, ['idempotency_key' => 'connect-account:'.$organization->uuid]);

        $organization->forceFill(['stripe_connect_id' => $account->id])->save();
        Event::record('organization.stripe_account_created', $organization, $by, ['account' => $account->id]);

        return $this->sync($organization, $account);
    }

    /**
     * A short-lived client secret for Connect.js on the website.
     */
    public function session(Organization $organization): AccountSession
    {
        $components = [];
        foreach (self::COMPONENTS as $component) {
            $components[$component] = ['enabled' => true];
        }
        foreach (['account_onboarding', 'account_management', 'notification_banner'] as $component) {
            $components[$component]['features'] = ['external_account_collection' => true];
        }

        return Stripe::client()->accountSessions->create([
            'account' => $organization->stripe_connect_id,
            'components' => $components,
        ]);
    }

    /**
     * Read the account from Stripe and store its state (the website calls this when onboarding
     * exits, so the page does not wait for the webhook).
     */
    public function refresh(Organization $organization): Organization
    {
        return $this->sync($organization, Stripe::client()->accounts->retrieve($organization->stripe_connect_id));
    }

    /**
     * Store what the account says: `payout_status` and the details behind it in `meta.stripe`.
     */
    public function sync(Organization $organization, StripeAccount $account): Organization
    {
        $requirements = $account['requirements'] ?? null;
        $from = $organization->payout_status;
        $status = static::payoutStatus($account);

        $organization->forceFill([
            'payout_status' => $status,
            'meta' => array_merge((array) $organization->meta, [
                'stripe' => [
                    'charges_enabled' => (bool) ($account['charges_enabled'] ?? false),
                    'payouts_enabled' => (bool) ($account['payouts_enabled'] ?? false),
                    'details_submitted' => (bool) ($account['details_submitted'] ?? false),
                    'transfers' => $account['capabilities']['transfers'] ?? null,
                    'card_payments' => $account['capabilities']['card_payments'] ?? null,
                    'disabled_reason' => $requirements['disabled_reason'] ?? null,
                    'currently_due' => array_values((array) ($requirements['currently_due'] ?? [])),
                    'past_due' => array_values((array) ($requirements['past_due'] ?? [])),
                    'current_deadline' => $requirements['current_deadline'] ?? null,
                    'country' => $account['country'] ?? null,
                    'default_currency' => $account['default_currency'] ?? null,
                    'synced_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();

        if ($from !== $status) {
            Event::record('organization.payout_status_changed', $organization, null, ['from' => $from, 'to' => $status, 'account' => $account->id]);
        }

        return $organization;
    }

    /**
     * `enabled` once charges and payouts work, `rejected` when Stripe rejected the account,
     * `pending` until the publisher submits their details, else `restricted` (Stripe needs more).
     */
    public static function payoutStatus(StripeAccount $account): string
    {
        $disabled = (string) ($account['requirements']['disabled_reason'] ?? '');

        return match (true) {
            str_starts_with($disabled, 'rejected') => Organization::PAYOUT_REJECTED,
            ($account['charges_enabled'] ?? false) && ($account['payouts_enabled'] ?? false) => Organization::PAYOUT_ENABLED,
            ! ($account['details_submitted'] ?? false) => Organization::PAYOUT_PENDING,
            default => Organization::PAYOUT_RESTRICTED,
        };
    }

    /**
     * What the website and API show about an organization's connected account.
     */
    public static function summary(Organization $organization): array
    {
        $state = $organization->stripeState();

        return [
            'connected' => $organization->hasStripeAccount(),
            'payout_status' => $organization->payout_status,
            'can_receive_payments' => $organization->canReceivePayments(),
            'charges_enabled' => (bool) ($state['charges_enabled'] ?? false),
            'payouts_enabled' => (bool) ($state['payouts_enabled'] ?? false),
            'details_submitted' => (bool) ($state['details_submitted'] ?? false),
            'requirements_due' => array_values(array_unique(array_merge($state['past_due'] ?? [], $state['currently_due'] ?? []))),
            'current_deadline' => $state['current_deadline'] ?? null,
            'country' => $state['country'] ?? null,
            'default_currency' => isset($state['default_currency']) ? strtoupper($state['default_currency']) : null,
            'synced_at' => $state['synced_at'] ?? null,
        ];
    }
}
