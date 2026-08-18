<?php

namespace App\Providers;

use App\Services\Copilot\CopilotService;
use App\Services\Copilot\OpenAiClient;
use App\Services\Copilot\Tools\GetPaymentEventsTool;
use App\Services\Copilot\Tools\GetPaymentTool;
use App\Services\Copilot\Tools\GetWebhookDeliveriesTool;
use App\Services\Copilot\Tools\ResendWebhookTool;
use App\Services\Copilot\Tools\SearchDocumentationTool;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // CopilotService takes an array of CopilotTool instances in its constructor -
        // the container can't infer that wiring on its own (it's not a single typed
        // dependency), so the registry of "which tools does the copilot actually have"
        // lives here, in one explicit, easy-to-scan place.
        $this->app->bind(CopilotService::class, fn ($app) => new CopilotService(
            $app->make(OpenAiClient::class),
            [
                $app->make(GetPaymentTool::class),
                $app->make(GetPaymentEventsTool::class),
                $app->make(GetWebhookDeliveriesTool::class),
                $app->make(SearchDocumentationTool::class),
                $app->make(ResendWebhookTool::class),
            ],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
