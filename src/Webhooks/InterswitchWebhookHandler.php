<?php

namespace Aelura\BillGateway\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class InterswitchWebhookHandler
{
    public function handle(Request $request): array
    {
        $payload = $request->all();

        // TODO: Implement real Interswitch signature validation based on config.
        // For now we just log and normalize.
        Log::info('Interswitch webhook received', ['payload' => $payload]);

        return [
            'provider' => 'interswitch',
            'reference' => Arr::get($payload, 'reference') ?? Arr::get($payload, 'terminalTransactionId'),
            'provider_reference' => Arr::get($payload, 'provider_reference') ?? Arr::get($payload, 'transactionRef'),
            'status' => Arr::get($payload, 'status') ?? Arr::get($payload, 'responseCode'),
            'amount' => Arr::get($payload, 'amount'),
            'raw' => $payload,
        ];
    }
}
