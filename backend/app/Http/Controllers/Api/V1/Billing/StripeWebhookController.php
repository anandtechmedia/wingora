<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Billing\Stripe\InvalidSignature;
use App\Billing\Stripe\WebhookProcessor;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public endpoint for Stripe. Nothing is read or changed until the signature over the raw body has been verified. */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookProcessor $processor): JsonResponse
    {
        try {
            $event = $processor->verify($request->getContent(), (string) $request->header('Stripe-Signature'));
        } catch (InvalidSignature $e) {
            SecurityEvent::record('billing.webhook_rejected', 'warning', null, ['reason' => $e->getMessage()], audit: false);

            return response()->json(['type' => 'about:blank', 'title' => __('Bad Request'), 'status' => 400, 'detail' => __('Invalid signature.')], 400, ['Content-Type' => 'application/problem+json']);
        }

        return response()->json(['received' => true] + $processor->process($event));
    }
}
