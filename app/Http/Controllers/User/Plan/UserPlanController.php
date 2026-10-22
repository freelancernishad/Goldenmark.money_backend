<?php

namespace App\Http\Controllers\User\Plan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class UserPlanController extends Controller
{

    public function getActivePlan(Request $request)
    {
        $user = $request->user();

        $active = $user->planSubscriptions()
            ->where('status', 'active')
            ->with('plan') // Eager load plan
            ->latest('start_date')
            ->first();

        if (!$active) {
            $payAsYouGoPlan = \App\Models\Plan\Plan::where('name', 'like', '%Pay As You Go%')->first()
                ?? \App\Models\Plan\Plan::find(1)
                ?? \App\Models\Plan\Plan::first();

            $activeData = [
                'id' => null,
                'user_id' => $user->id,
                'plan_id' => $payAsYouGoPlan ? $payAsYouGoPlan->id : null,
                'status' => 'active',
                'is_default' => true,
                'plan' => $payAsYouGoPlan,
            ];
        } else {
            $activeData = $active->toArray();
        }

        // Dynamically compute service rates from the active plan
        $targetPlan = $active ? $active->plan : ($payAsYouGoPlan ?? null);
        if ($targetPlan) {
            $checkRate = null;
            $emailRate = null;
            $mailRate = null;

            $features = is_array($targetPlan->features) 
                ? $targetPlan->features 
                : (is_array($targetPlan->formatted_features) ? $targetPlan->formatted_features : []);

            foreach ($features as $f) {
                $lbl = strtolower(trim($f['label'] ?? ''));
                if (isset($f['price']) && preg_match('/([0-9.]+)/', (string)$f['price'], $m)) {
                    $val = (float)$m[1];
                    if ($val > 0) {
                        if (str_contains($lbl, 'print') || str_contains($lbl, 'check')) {
                            if (!$checkRate) $checkRate = $val;
                        }
                        if (str_contains($lbl, 'email') || str_contains($lbl, 'e-check')) {
                            if (!$emailRate) $emailRate = $val;
                        }
                        if (str_contains($lbl, 'mail')) {
                            if (!$mailRate) $mailRate = $val;
                        }
                    }
                }
            }

            // Package rate calculation if not explicit in features
            preg_match('/(\d+)/', $targetPlan->name, $matches);
            $checksCount = isset($matches[1]) ? (int)$matches[1] : 0;
            $planPrice = floatval($targetPlan->discounted_price > 0 ? $targetPlan->discounted_price : $targetPlan->monthly_price);
            if ($checksCount > 0 && $planPrice > 0) {
                $computed = round($planPrice / $checksCount, 2);
                if (!$checkRate) $checkRate = $computed;
                if (!$emailRate) $emailRate = $computed;
                if (!$mailRate) $mailRate = round($computed * 2, 2);
            }

            if (!$checkRate) $checkRate = 0.50;
            if (!$emailRate) $emailRate = $checkRate;
            if (!$mailRate) $mailRate = round($checkRate * 2, 2);

            $activeData['check_print_rate'] = $checkRate;
            $activeData['email_check_rate'] = $emailRate;
            $activeData['mail_check_rate'] = $mailRate;
            $activeData['per_check_rate'] = '$' . number_format($checkRate, 2) . ' / check';

            if (isset($activeData['plan'])) {
                if (is_array($activeData['plan'])) {
                    $activeData['plan']['check_print_rate'] = $checkRate;
                    $activeData['plan']['email_check_rate'] = $emailRate;
                    $activeData['plan']['mail_check_rate'] = $mailRate;
                    $activeData['plan']['per_check_rate'] = '$' . number_format($checkRate, 2) . ' / check';
                } elseif (is_object($activeData['plan'])) {
                    $activeData['plan']->check_print_rate = $checkRate;
                    $activeData['plan']->email_check_rate = $emailRate;
                    $activeData['plan']->mail_check_rate = $mailRate;
                    $activeData['plan']->per_check_rate = '$' . number_format($checkRate, 2) . ' / check';
                }
            }
        }

        // Ensure credit_balance is initialized or synced if missing despite paid payments
        if (is_null($user->credit_balance) || (float)$user->credit_balance <= 0) {
            $paymentsSum = (float) $user->payments()->whereIn('status', ['paid', 'Success', 'completed', 'succeeded'])->sum('amount');
            $subsSum = (float) $user->planSubscriptions()->where('status', 'active')->sum('final_amount');
            
            $totalRecharged = max($paymentsSum, $subsSum);
            if ($totalRecharged > 0) {
                $usedCredits = (float) ($user->used_credits ?? 0);
                $netCredit = max(0, $totalRecharged - $usedCredits);
                if ($netCredit <= 0 && $paymentsSum > 0) {
                    $netCredit = $paymentsSum;
                }
                $user->credit_balance = $netCredit;
                $user->save();
            }
        }

        $currentCredit = (float) ($user->credit_balance ?? 0);

        $activeData['total_recharge_credit'] = $currentCredit;
        $activeData['recharge_credit'] = $currentCredit;
        $activeData['credit_balance'] = $currentCredit;

        return response()->json($activeData);
    }

    public function getSubscriptionHistory(Request $request)
    {
        $user = $request->user();

        $subscriptions = $user->planSubscriptions()
            ->with('plan') // Eager load plan
            ->latest('start_date')
            ->paginate(10);

        return response()->json($subscriptions);
    }

    public function getUserPayments(Request $request)
    {
        $user = $request->user();

        $payments = $user->payments()
            ->latest('created_at')
            ->paginate(10);

        return response()->json($payments);
    }

    public function getCreditsStatement(Request $request)
    {
        $user = $request->user();

        // 1. Fetch all Credit Recharges (from Payments / Subscriptions)
        $recharges = $user->payments()
            ->where('status', 'paid')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => 'RECHARGE-' . str_pad($p->id, 5, '0', STR_PAD_LEFT),
                    'date' => $p->created_at->format('m/d/Y H:i'),
                    'raw_date' => $p->created_at->timestamp,
                    'description' => 'Credit Recharge Payment (Via Stripe)',
                    'type' => 'Credit',
                    'amount' => (float) $p->amount,
                    'status' => 'Success',
                ];
            });

        // If no payments record yet, check active subscriptions
        if ($recharges->isEmpty()) {
            $subs = $user->planSubscriptions()
                ->where('status', 'active')
                ->orderBy('start_date', 'asc')
                ->get()
                ->map(function ($s) {
                    return [
                        'id' => 'SUB-' . str_pad($s->id, 5, '0', STR_PAD_LEFT),
                        'date' => \Carbon\Carbon::parse($s->start_date)->format('m/d/Y H:i'),
                        'raw_date' => \Carbon\Carbon::parse($s->start_date)->timestamp,
                        'description' => 'Plan Purchase Credit: ' . ($s->plan->name ?? 'Subscription'),
                        'type' => 'Credit',
                        'amount' => (float) $s->final_amount,
                        'status' => 'Success',
                    ];
                });
            $recharges = collect($subs);
        }

        // 2. Fetch all Debit Usages (from Payment Logs / Check Actions)
        $debits = \DB::table('company_payment_logs')
            ->join('company_payments', 'company_payments.id', '=', 'company_payment_logs.company_payment_id')
            ->where('company_payment_logs.initiated_by', $user->id)
            ->whereIn('company_payment_logs.note', [
                'Check PDF printed / downloaded',
                'E-check sent via email',
                'Mail check sent',
            ])
            ->orderBy('company_payment_logs.created_at', 'asc')
            ->select('company_payment_logs.*', 'company_payments.check_number')
            ->get()
            ->map(function ($log) {
                $serviceName = str_contains($log->note, 'printed') ? 'Check Print' : (str_contains($log->note, 'email') ? 'Email Check' : 'Mail Check');
                return [
                    'id' => 'USAGE-' . str_pad($log->id, 5, '0', STR_PAD_LEFT),
                    'date' => \Carbon\Carbon::parse($log->created_at)->format('m/d/Y H:i'),
                    'raw_date' => \Carbon\Carbon::parse($log->created_at)->timestamp,
                    'description' => "{$serviceName} #CK-{$log->check_number}",
                    'type' => 'Debit',
                    'amount' => 0.50,
                    'status' => 'Success',
                ];
            });

        // 3. Merge and Sort chronologically
        $all = collect($recharges)->concat($debits)->sortBy('raw_date')->values();

        // 4. Calculate Running Balance
        $runningBalance = 0.00;
        $statement = $all->map(function ($item) use (&$runningBalance) {
            if ($item['type'] === 'Credit') {
                $runningBalance += $item['amount'];
            } else {
                $runningBalance -= $item['amount'];
            }
            $item['balance'] = max(0, $runningBalance);
            return $item;
        });

        $reversed = $statement->reverse()->values();

        // Server-side Search Filter
        if ($request->filled('search')) {
            $search = strtolower($request->input('search'));
            $reversed = $reversed->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['description'] ?? ''), $search) ||
                       str_contains(strtolower($item['id'] ?? ''), $search);
            })->values();
        }

        // Server-side Type Filter (Credit / Debit)
        if ($request->filled('type') && in_array(strtolower($request->type), ['credit', 'debit'])) {
            $targetType = ucfirst(strtolower($request->type));
            $reversed = $reversed->filter(function ($item) use ($targetType) {
                return $item['type'] === $targetType;
            })->values();
        }

        $perPage = max(1, (int) $request->input('per_page', 10));
        $page = max(1, (int) $request->input('page', 1));
        $total = $reversed->count();
        $offset = ($page - 1) * $perPage;
        $items = $reversed->slice($offset, $perPage)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $total,
            $perPage,
            $page,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'query' => $request->query(),
            ]
        );

        return response()->json($paginator);
    }
}
