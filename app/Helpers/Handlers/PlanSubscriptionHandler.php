<?php

namespace App\Helpers\Handlers;

use App\Models\Plan\PlanSubscription;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Stripe\StripeObject;
use Carbon\Carbon;

class PlanSubscriptionHandler
{
    /**
     * Main entry for handling Stripe events related to plan subscriptions
     *
     * @param StripeObject $object
     */
    public static function handle(StripeObject $object)
    {
        // Determine object type (Checkout Session, Invoice, Subscription)
        switch ($object->object) {
            case 'checkout.session':
                self::handleCheckoutSession($object);
                break;

            case 'invoice':
                self::handleInvoice($object);
                break;

            case 'subscription':
                self::handleSubscription($object);
                break;

            default:
                Log::warning("PlanSubscriptionHandler received unknown Stripe object type: {$object->object}");
        }
    }

    // -------------------------------
    // Checkout Session (New Subscription / One-time)
    // -------------------------------
    protected static function handleCheckoutSession(StripeObject $session)
    {
        $userId = $session->metadata->user_id ?? null;
        $planId = $session->metadata->plan_id ?? null;
        $mode = $session->metadata->mode ?? 'subscription'; // 'subscription' or 'payment'

        if (!$userId || !$planId) {
            Log::warning("Missing user_id or plan_id in session metadata: {$session->id}");
            return;
        }

        $isRecurring = $mode === 'subscription';
        $stripeSubId = $session->subscription ?? null;

        $subscription = PlanSubscription::updateOrCreate(
            [
                'user_id' => $userId,
                'plan_id' => $planId,
            ],
            [
                'stripe_subscription_id' => $stripeSubId,
                'start_date' => now(),
                'end_date' => $isRecurring ? null : now()->addMonth(),
                'next_billing_date' => $isRecurring ? now()->addMonth() : null,
                'status' => 'active',
                'original_amount' => $session->amount_total / 100,
                'final_amount' => $session->amount_total / 100,
                'plan_features' => isset($session->metadata->plan_features)
                    ? json_decode($session->metadata->plan_features, true)
                    : null,
                'billing_interval' => $isRecurring ? 'monthly' : null,
                'billing_cycle' => 1,
            ]
        );

        // Note: Payment creation and logging is handled by CheckStripePaymentStatus listener to avoid duplicate entries

        Log::info("Plan subscription created/updated for user {$userId}, subscription ID: {$subscription->id}");
    }

    // -------------------------------
    // Invoice (Recurring Payment)
    // -------------------------------
    protected static function handleInvoice(StripeObject $invoice)
    {
        $stripeSubId = $invoice->subscription ?? null;
        if (!$stripeSubId) return;

        $subscription = PlanSubscription::where('stripe_subscription_id', $stripeSubId)->first();
        if (!$subscription) return;

        // Increment billing cycle and update next billing date
        $subscription->incrementBillingCycle();

        // Record payment
        try {
            $paidAmount = $invoice->amount_paid / 100;
            $subscription->payments()->create([
                'user_id' => $subscription->user_id,
                'amount' => $paidAmount,
                'currency' => $invoice->currency,
                'payment_method' => 'stripe',
                'transaction_id' => $invoice->payment_intent,
                'status' => 'succeeded',
                'webhook_status' => 'invoice.payment_succeeded',
                'webhook_received_at' => now(),
                'meta' => $invoice,
            ]);

            if ($subscription->user && $paidAmount > 0) {
                $subscription->user->increment('credit_balance', $paidAmount);
            }
        } catch (\Exception $e) {
            Log::error("Failed to record recurring payment for subscription {$subscription->id}: " . $e->getMessage());
        }

        Log::info("Recurring payment recorded for subscription ID: {$subscription->id}");
    }

    // -------------------------------
    // Subscription events (status updates)
    // -------------------------------
    protected static function handleSubscription(StripeObject $stripeSubscription)
    {
        $subscription = PlanSubscription::where('stripe_subscription_id', $stripeSubscription->id)->first();
        if (!$subscription) return;

        $subscription->update([
            'status' => $stripeSubscription->status,
            'end_date' => isset($stripeSubscription->current_period_end)
                ? Carbon::createFromTimestamp($stripeSubscription->current_period_end)
                : $subscription->end_date,
        ]);

        Log::info("Subscription status updated: {$subscription->id}, status: {$stripeSubscription->status}");
    }
}
