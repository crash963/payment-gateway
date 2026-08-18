<?php

namespace Database\Seeders;

use App\Models\Merchant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates one fixed demo merchant for local manual testing (curl/Postman), so the
     * API key is always the same known value instead of a fresh random one every time
     * you re-seed. Uses forceFill(), not create()/updateOrCreate() - those go through
     * the normal $fillable guard, which deliberately excludes api_key_hash/
     * webhook_secret (see Merchant model), so they'd be silently dropped here too, the
     * same trap Payment::createPending() exists to avoid.
     */
    public function run(): void
    {
        $apiKey = 'pf_demo_local_testing_key';

        $merchant = Merchant::query()->where('name', 'Demo Shop')->first() ?? new Merchant;

        $merchant->forceFill([
            'name' => 'Demo Shop',
            'api_key_hash' => Merchant::hashApiKey($apiKey),
            'webhook_secret' => Str::random(40),
            'active' => true,
            // Points at DemoMerchantWebhookReceiverController - our own app standing
            // in for the merchant's server, purely so webhook delivery is demoable
            // without a second real server. url() (not a hardcoded string) so this
            // stays correct whatever APP_URL/port the app is actually running on.
            'webhook_url' => url('/api/demo/webhook-receiver'),
        ])->save();

        $this->command->info("Demo merchant ready. API key: {$apiKey}");
    }
}
