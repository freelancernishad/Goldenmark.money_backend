<?php

namespace App\Listeners;

use App\Events\StripePaymentEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CheckStripePaymentStatus
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(StripePaymentEvent $event)
    {
        Log::info("CheckStripePaymentStatus Listener Triggered for Event: {$event->type}");

        // Only process for checkout session or subscription events to avoid double processing with payment_intent
        // payment_intent.succeeded often fires alongside checkout.session.completed
        if (!in_array($event->type, ['checkout.session.completed', 'invoice.payment_succeeded'])) {
             Log::info("Skipping subscription processing for event type: {$event->type}");
             return;
        }

        if ($event->status === 'success') {
            Log::info("Payment Verified via Event Listener!", [
                'user_id' => $event->userId,
                'amount' => $event->payload['amount_total'] ?? $event->payload['amount'] ?? 'N/A',
                'currency' => $event->payload['currency'] ?? 'N/A',
                // 'raw_payload' => $event->payload
            ]);

            $this->processSubscription($event);

        } elseif ($event->status === 'failed') {
            Log::warning("Payment Failed Alert via Event Listener!", [
                'user_id' => $event->userId,
                'reason' => $event->payload['last_payment_error']['message'] ?? 'Unknown error'
            ]);
        }
    }

    protected function processSubscription(StripePaymentEvent $event)
    {
        if (!$event->userId) return;

        $user = \App\Models\User::find($event->userId);
        if (!$user) return;

        $sessionId = $event->payload['id'] ?? null;
        $log = null;
        if ($sessionId) {
            $log = \App\Models\StripeLog::where('session_id', $sessionId)->first();
        }

        // Use Log as fallback if payload is missing crucial data (like Plan ID or Sub ID)
        $subscriptionId = $event->payload['subscription'] ?? $log?->subscription_id ?? null;
        $planId = $event->payload['metadata']['plan_id'] ?? $log?->plan_id ?? null;

        Log::info("Processing subscription. User: {$event->userId}, Session: " . ($sessionId ?? 'N/A') . ", SubID: " . ($subscriptionId ?? 'NONE') . ", PlanID: " . ($planId ?? 'MISSING'));

        if (!$planId) {
            Log::warning("Plan ID not found for successful payment.");
            return;
        }

        $plan = \App\Models\Plan\Plan::find($planId);
        if (!$plan) return;

        $startDate = now();
        $endDate = null;
        $nextBillingDate = null;

        // 1. Source dates from StripeLog (Highest priority as it reflects already processed logic)
        if ($log && $log->next_payment_date) {
            $nextBillingDate = \Carbon\Carbon::parse($log->next_payment_date);
            $endDate = \Carbon\Carbon::parse($log->next_payment_date);
            Log::info("Using dates from StripeLog: " . $nextBillingDate->toDateTimeString());
        }

        // 2. Source dates from Stripe API if still missing (for subscription modes)
        if (!$nextBillingDate && $subscriptionId) {
            try {
                \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                $stripeSubscription = \Stripe\Subscription::retrieve($subscriptionId);
                
                if (!empty($stripeSubscription->current_period_end)) {
                    $nextBillingDate = \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end);
                    $endDate = \Carbon\Carbon::createFromTimestamp($stripeSubscription->current_period_end);
                    Log::info("Using dates from Stripe API: " . $nextBillingDate->toDateTimeString());
                }
            } catch (\Exception $e) {
                Log::error("Failed to retrieve Stripe subscription dates: " . $e->getMessage());
            }
        } 
        
        // 3. Fallback calculation using Plan Duration
        if (!$endDate && $plan->duration) {
             $numericDuration = (int) preg_replace('/[^0-9]/', '', $plan->duration);
             if ($numericDuration > 0) {
                 if (str_contains(strtolower($plan->duration), 'year')) {
                     $endDate = now()->addYears($numericDuration);
                 } else {
                     $endDate = now()->addMonths($numericDuration);
                 }
                 Log::info("Using fallback calculated end_date: " . $endDate->toDateTimeString());
             }
        }

        // Match Attributes: Use SubID if exists, otherwise try to find recent user/plan match
        if ($subscriptionId) {
            $matchAttributes = ['stripe_subscription_id' => $subscriptionId];
        } else {
            $existing = \App\Models\Plan\PlanSubscription::where('user_id', $user->id)
                ->where('plan_id', $plan->id)
                ->where('status', 'active')
                ->whereNull('stripe_subscription_id')
                ->where('created_at', '>=', now()->subMinutes(10))
                ->first();
                
            if ($existing) {
                Log::info("Updating existing active one-time subscription: {$existing->id}");
                $matchAttributes = ['id' => $existing->id];
            } else {
                Log::info("Matching new one-time subscription.");
                $matchAttributes = [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'stripe_subscription_id' => null,
                    'created_at' => now(), 
                ];
            }
        }

        // Amounts: Stripe amount_total is in cents, always convert to dollars
        $rawAmount = ($event->payload['amount_total'] ?? $event->payload['amount'] ?? 0);
        $finalAmount = $rawAmount > 0 ? ($rawAmount / 100) : 0;

        // If amount from payload is 0 or missing, fallback to plan price
        if ($finalAmount <= 0) {
            $finalAmount = $plan->discounted_price;
        }

        $originalAmount = $plan->original_price;
        $discountAmount = $originalAmount - $finalAmount;
        if ($discountAmount < 0) $discountAmount = 0;
        
        $discountPercent = $originalAmount > 0 ? ($discountAmount / $originalAmount) * 100 : 0;

        $sub = \App\Models\Plan\PlanSubscription::updateOrCreate(
            $matchAttributes,
            [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'next_billing_date' => $nextBillingDate,
                'status' => 'active',
                'original_amount' => $originalAmount,
                'final_amount' => $finalAmount,
                'discount_amount' => $discountAmount,
                'discount_percent' => $discountPercent,
                'plan_features' => $plan->features, 
                'billing_interval' => $subscriptionId ? ($plan->duration_type === 'year' ? 'yearly' : 'monthly') : null,
            ]
        );


        $oldSubscriptions = \App\Models\Plan\PlanSubscription::where('user_id', $user->id)
            ->where('id', '!=', $sub->id) // Exclude the new one
            ->where('status', 'active')
            ->get();

        foreach ($oldSubscriptions as $oldSub) {
             // Cancel locally
            $oldSub->update(['status' => 'canceled', 'end_date' => now()]);
            
            // Cancel in Stripe if applicable
            if ($oldSub->stripe_subscription_id) {
                try {
                    \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
                    $stripeSub = \Stripe\Subscription::retrieve($oldSub->stripe_subscription_id);
                    $stripeSub->cancel(); 
                    Log::info("Cancelled old Stripe subscription: {$oldSub->stripe_subscription_id}");
                } catch (\Exception $e) {
                     Log::error("Failed to cancel old Stripe subscription: " . $e->getMessage());
                }
            }
            Log::info("Auto-cancelled old subscription ID: {$oldSub->id} due to upgrade/switch.");
        }

        // Record Coupon Usage if coupon_id is present in metadata
        $couponId = $event->payload['metadata']['coupon_id'] ?? $log?->meta_data['coupon_id'] ?? null;
        if ($couponId) {
            $alreadyRecorded = \App\Models\Coupon\CouponUsage::where('coupon_id', $couponId)
                ->where('user_id', $user->id)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->exists();

            if (!$alreadyRecorded) {
                \App\Models\Coupon\CouponUsage::create([
                    'coupon_id' => $couponId,
                    'user_id' => $user->id,
                    'used_at' => now(),
                ]);
                Log::info("Coupon usage recorded for user {$user->id}, coupon {$couponId}");
            }
        }

        Log::info("PlanSubscription processed. ID: {$sub->id}, Final Amount: {$finalAmount}, Ends: " . ($sub->end_date ? $sub->end_date->toDateTimeString() : 'Never'));

        // Create Payment Record
        if ($finalAmount > 0) {
            $this->createPaymentRecord($user, $sub, $finalAmount, $event->payload);
        }
    }

    protected function createPaymentRecord($user, $subscription, $amount, $payload)
    {
        try {
            $transactionId = $payload['id'] ?? $payload['payment_intent'] ?? 'tx_' . uniqid();
            
            // Avoid duplicates
            if (\App\Models\Payment::where('transaction_id', $transactionId)->exists()) {
                Log::info("Payment record already exists for transaction: {$transactionId}");
                return;
            }

            $paymentMethod = $payload['payment_method_types'][0] ?? 'card';
            if (isset($payload['payment_method_details']['type'])) {
                $paymentMethod = $payload['payment_method_details']['type'];
            }

            \App\Models\Payment::create([
                'user_id' => $user->id,
                'payable_id' => $subscription->id,
                'payable_type' => get_class($subscription),
                'amount' => $amount,
                'currency' => $payload['currency'] ?? 'usd',
                'payment_method' => $paymentMethod,
                'transaction_id' => $transactionId,
                'stripe_session_id' => $payload['id'] ?? null,
                'stripe_payment_intent_id' => $payload['payment_intent'] ?? null,
                'status' => 'paid', // Assuming success if we are here
                'gateway_response' => $payload,
            ]);

            // Add the paid amount directly to the user's credit balance
            $user->increment('credit_balance', $amount);

            Log::info("Payment record created for Transaction: {$transactionId}, added \${$amount} credit to user {$user->id}");

        } catch (\Exception $e) {
            Log::error("Failed to create Payment record: " . $e->getMessage());
        }
    }
}
