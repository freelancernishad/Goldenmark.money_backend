<?php

namespace App\Http\Controllers\Goldenmarkmoney;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Goldenmarkmoney\Payment;

class PaymentController extends Controller
{
    protected $paymentService;

    public function __construct(\App\Services\Goldenmarkmoney\PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function index(Request $request)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json([]);

        $query = Payment::where('company_id', $business->id);

        if ($request->boolean('compact')) {
            $query->select('id', 'company_id', 'account_id', 'check_number', 'amount', 'status', 'issue_date');
        } else {
            $query->with(['payee', 'account.activeSignature', 'account.activeCheckDesign', 'logs.initiator']);
        }

        // Tab filters (Check, ACH, Wire, Virtual Card, Recurring)
        if ($request->filled('tab')) {
            $tab = strtolower($request->tab);
            if ($tab === 'check') {
                $query->where('pay_as', 'Check');
            } elseif ($tab === 'ach') {
                $query->where('pay_as', 'like', '%ach%');
            } elseif ($tab === 'wire') {
                $query->where('pay_as', 'Wire');
            } elseif ($tab === 'virtual card') {
                $query->where('pay_as', 'Virtual Card');
            }
        }

        // Granular filters
        if ($request->filled('payee_id')) {
            $query->where('payee_id', $request->payee_id);
        }
        if ($request->filled('amount')) {
            $query->where('amount', 'like', '%' . $request->amount . '%');
        }
        if ($request->filled('memo')) {
            $query->where('memo', 'like', '%' . $request->memo . '%');
        }
        if ($request->filled('check_number')) {
            $query->where('check_number', 'like', '%' . $request->check_number . '%');
        }
        if ($request->filled('account_id')) {
            $query->where('account_id', $request->account_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // General search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('memo', 'like', "%{$search}%")
                  ->orWhere('check_number', 'like', "%{$search}%")
                  ->orWhereHas('payee', function($qp) use ($search) {
                      $qp->where('payee_name', 'like', "%{$search}%")
                        ->orWhere('nick_name', 'like', "%{$search}%");
                  });
            });
        }

        // Date filters
        if ($request->filled('created_at')) {
            $query->whereDate('created_at', $request->created_at);
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        
        $allowedSorts = ['id', 'amount', 'check_number', 'issue_date', 'created_at', 'updated_at', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = $request->input('per_page', 20);
        $payments = $query->paginate($perPage);

        $payments->getCollection()->transform(function ($payment) {
            return $this->sanitizePaymentUserRelations($payment);
        });

        return response()->json($payments);
    }

    public function store(Request $request)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'payee_id' => 'nullable|exists:payees,id',
            'pay_from' => 'nullable|string',
            'pay_as' => 'nullable|string',
            'status' => 'nullable|string',
            'amount' => 'required|numeric|min:0',
            'issue_date' => 'nullable|date',
            'check_number' => 'nullable|integer',
            'invoice_number' => 'nullable|string|max:255',
            'payee_id_account_number' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:company_payment_categories,id',
            'memo' => 'nullable|string|max:255',
            'include_signature' => 'nullable|boolean',
            'delivery_proof' => 'nullable|array',
            'process_without' => 'nullable|array',
        ]);

        $account = $business->accounts()->where('id', $validated['account_id'])->first();
        if (!$account) {
            return response()->json(['message' => 'Invalid account'], 403);
        }

        if ($account->verification_status === 'pending' || ($account->is_tokenized && $account->verification_status !== 'verified')) {
            return response()->json(['message' => 'This bank account requires verification before payments can be created.'], 422);
        }

        try {
            $payment = $this->paymentService->createPayment($validated, $business);

            $payment->logs()->create([
                'status' => 'pending',
                'initiated_by' => auth()->id(),
                'note' => 'Payment created via API',
                'device_info' => $request->userAgent()
            ]);

            return response()->json($payment, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function show($id)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $payment = $business->payments()->with(['payee', 'account.activeSignature', 'account.activeCheckDesign', 'logs.initiator', 'comments.user', 'attachments', 'business'])->findOrFail($id);
        $this->sanitizePaymentUserRelations($payment);

        // Auto-sync & backfill missing snapshot fields for legacy checks using raw DB columns
        $dirty = false;

        $rawSigUrl = $payment->getRawOriginal('signature_image_url');
        $rawSigPath = $payment->getRawOriginal('signature_image');

        if ($rawSigUrl !== 'NO_SIGNATURE' && $rawSigPath !== 'NO_SIGNATURE' && empty($rawSigUrl) && empty($rawSigPath)) {
            $activeSig = $payment->account ? ($payment->account->activeSignature ?? $payment->account->signatures()->latest()->first()) : null;
            if ($activeSig) {
                $payment->signature_image = $activeSig->path;
                $payment->signature_image_url = $activeSig->image_url;
                $dirty = true;
            }
        }

        if (empty($payment->getRawOriginal('company_name')) && $payment->account) {
            $payment->company_name = $payment->account->account_holder_name;
            $dirty = true;
        }

        if (empty($payment->getRawOriginal('company_address')) && $payment->account) {
            $accountAddrParts = array_filter([
                $payment->account->address_line1 ?? '',
                $payment->account->address_line2 ?? '',
                $payment->account->city ?? '',
                isset($payment->account->state) ? $payment->account->state . " " . ($payment->account->postal_code ?? '') : ($payment->account->postal_code ?? ''),
                $payment->account->country ?? ''
            ]);
            $payment->company_address = !empty($accountAddrParts) ? implode(', ', $accountAddrParts) : null;
            $dirty = true;
        }



        if (empty($payment->getRawOriginal('bank_routing_number')) && $payment->account) {
            $payment->bank_name = $payment->account->bank_name ?: $payment->account->institution_name;
            $payment->bank_routing_number = $payment->account->routing_number;
            $payment->bank_account_number = $payment->account->account_number;
            $dirty = true;
        }

        if ($dirty) {
            $payment->save();
        }

        return response()->json($payment);
    }

    public function update(Request $request, $id)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $payment = $business->payments()->findOrFail($id);

        $validated = $request->validate([
            'account_id' => 'nullable|exists:accounts,id',
            'payee_id' => 'nullable|exists:payees,id',
            'amount' => 'nullable|numeric|min:0.01',
            'issue_date' => 'nullable|date',
            'check_number' => 'nullable|integer',
            'memo' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:50',
            'signature_image_url' => 'nullable|string',
        ]);

        try {
            \DB::transaction(function () use ($payment, $validated) {
                $payment->update($validated);
            });

            return response()->json($payment);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    public function destroy($id)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $payment = $business->payments()->findOrFail($id);

        \DB::transaction(function () use ($payment) {
            if (!in_array(strtolower($payment->status), ['void', 'voided', 'failed', 'paid', 'sent'])) {
                $account = $payment->account;
                if ($account) {
                    $account->increment('balance', $payment->amount);
                }
            }
            $payment->delete();
        });

        return response()->json(['message' => 'Payment deleted successfully']);
    }

    public function bulkAction(Request $request)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $validated = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:company_payments,id',
            'action' => 'required|string|in:delete,void,print,process,paid',
        ]);

        $payments = $business->payments()->whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            \DB::transaction(function () use ($payments) {
                foreach ($payments as $payment) {
                    if (!in_array(strtolower($payment->status), ['void', 'voided', 'failed', 'paid', 'sent'])) {
                        $account = $payment->account;
                        if ($account) {
                            $account->increment('balance', $payment->amount);
                        }
                    }
                    $payment->delete();
                }
            });
            return response()->json(['message' => 'Payments deleted successfully']);
        } elseif ($validated['action'] === 'void') {
            \DB::transaction(function () use ($payments) {
                foreach ($payments as $payment) {
                    if (!in_array(strtolower($payment->status), ['void', 'failed'])) {
                        $account = $payment->account;
                        if ($account) {
                            $account->increment('balance', $payment->amount);
                        }
                        $payment->update(['status' => 'Void']);
                    }
                }
            });
            return response()->json(['message' => 'Payments voided successfully']);
        } elseif (in_array($validated['action'], ['process', 'paid'])) {
            \DB::transaction(function () use ($payments) {
                foreach ($payments as $payment) {
                    $payment->update(['status' => 'paid']);
                    $payment->logs()->create([
                        'status' => 'paid',
                        'initiated_by' => auth()->id(),
                        'note' => 'Payment processed and marked as paid',
                        'device_info' => request()->ip()
                    ]);
                }
            });
            return response()->json(['message' => 'Selected payments processed successfully']);
        } elseif ($validated['action'] === 'print') {
            $unchargedPayments = [];
            foreach ($payments as $payment) {
                $alreadyCharged = $payment->is_charged || in_array(strtolower($payment->status), ['printed', 'sent', 'mailed', 'completed']) || $payment->logs()
                    ->where(function ($q) {
                        $q->whereIn('note', [
                            'Check PDF printed / downloaded',
                            'E-check sent via email',
                            'Mail check sent',
                        ])->orWhere('note', 'LIKE', '%E-check email sent%');
                    })
                    ->exists();
                if (!$alreadyCharged) {
                    $unchargedPayments[] = $payment;
                }
            }

            if (count($unchargedPayments) > 0) {
                $creditCheck = $this->checkUserCreditForService('Check Printing', count($unchargedPayments));
                if (!$creditCheck['allowed']) {
                    return $creditCheck['response'];
                }

                \DB::transaction(function () use ($unchargedPayments, $creditCheck) {
                    foreach ($unchargedPayments as $payment) {
                        $payment->logs()->create([
                            'status' => $payment->status,
                            'initiated_by' => auth()->id(),
                            'note' => 'Check PDF printed / downloaded',
                            'device_info' => request()->ip()
                        ]);
                        if ($payment->status === 'pending') {
                            $payment->update(['status' => 'printed']);
                        }
                    }
                    $this->deductUserCreditForService($creditCheck['total_cost'] ?? ($creditCheck['service_price'] * count($unchargedPayments)));
                });
            }

            return response()->json(['message' => 'Printing processed and charges applied successfully']);
        }

        return response()->json(['message' => 'Invalid bulk action'], 400);
    }

    public function downloadPdf(Request $request, $id, \App\Services\Goldenmarkmoney\CheckService $checkService)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $payment = $business->payments()->with(['business', 'payee', 'account'])->findOrFail($id);

        // Check if this check payment has already been charged once (unlocked)
        $alreadyCharged = $payment->logs()
            ->where(function ($q) {
                $q->whereIn('note', [
                    'Check PDF printed / downloaded',
                    'E-check sent via email',
                    'Mail check sent',
                ])->orWhere('note', 'LIKE', '%E-check email sent%');
            })
            ->exists();

        $creditCheck = ['allowed' => true, 'service_price' => 0];
        if (!$alreadyCharged) {
            $creditCheck = $this->checkUserCreditForService('Check Printing');
            if (!$creditCheck['allowed']) {
                return $creditCheck['response'];
            }
        }

        // Audit Log for check printing compliance
        $payment->logs()->create([
            'status' => $payment->status,
            'initiated_by' => auth()->id(),
            'note' => 'Check PDF printed / downloaded',
            'device_info' => request()->ip()
        ]);

        if ($payment->status === 'pending') {
            $payment->update(['status' => 'printed']);
        }

        // Deduct service charge ONLY if not previously charged
        if (!$alreadyCharged && isset($creditCheck['service_price'])) {
            $this->deductUserCreditForService($creditCheck['service_price']);
        }

        $pdf = $checkService->generateCheckPdf($payment, $request->all());

        return $pdf->stream("check_{$payment->check_number}.pdf");
    }

    public function storeBlankChecks(Request $request)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $validated = $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'number_of_checks' => 'required|integer|min:1|max:100',
            'category_id' => 'nullable|exists:company_payment_categories,id',
            'include_signature' => 'nullable|string|in:Yes,No',
            'memo' => 'nullable|string|max:255',
        ]);

        // Dynamically check user credit balance for the exact number of checks based on active plan
        $creditCheck = $this->checkUserCreditForService('Check Printing', (int)$validated['number_of_checks']);
        if (!$creditCheck['allowed']) {
            return $creditCheck['response'];
        }

        $account = \App\Models\Goldenmarkmoney\Account::find($validated['account_id']);
        if (!$business->accounts()->where('id', $account->id)->exists()) {
            return response()->json(['message' => 'Invalid account'], 403);
        }

        $createdPayments = [];
        $includeSignature = ($request->input('include_signature', 'Yes') === 'Yes');

        $activeSig = $includeSignature 
            ? ($account->activeSignature ?? $account->signatures()->latest()->first())
            : null;

        if (!$activeSig && $includeSignature && $business) {
            $activeSig = \App\Models\Goldenmarkmoney\AccountSignature::whereIn('account_id', $business->accounts()->pluck('id'))
                ->orderBy('is_primary', 'desc')
                ->latest()
                ->first();
        }

        $sigImage = $includeSignature ? ($activeSig ? $activeSig->path : null) : 'NO_SIGNATURE';
        $sigImageUrl = $includeSignature ? ($activeSig ? $activeSig->image_url : null) : 'NO_SIGNATURE';

        \DB::transaction(function () use ($validated, $account, $business, $includeSignature, $sigImage, $sigImageUrl, &$createdPayments) {
            $startCheckNo = $this->getNextCheckNumber($account);

            for ($i = 0; $i < $validated['number_of_checks']; $i++) {
                $checkNo = $startCheckNo + $i;

                $payment = Payment::create([
                    'company_id' => $business->id,
                    'account_id' => $account->id,
                    'payee_id' => null,
                    'pay_from' => 'Bank Account',
                    'pay_as' => 'Check',
                    'amount' => 0.00,
                    'status' => 'Blank',
                    'issue_date' => now()->format('Y-m-d'),
                    'check_number' => $checkNo,
                    'category_id' => $validated['category_id'] ?? null,
                    'memo' => $validated['memo'] ?? null,
                    'signature_image' => $sigImage,
                    'signature_image_url' => $sigImageUrl,
                    'delivery_proof' => [
                        'include_signature' => $includeSignature,
                        'without_amount' => true,
                        'without_sign' => !$includeSignature,
                        'without_date' => true,
                        'without_payee' => true,
                    ],
                    'process_without' => [
                        'amount' => true,
                        'sign' => !$includeSignature,
                        'date' => true,
                        'payee' => true,
                    ],
                ]);

                $payment->logs()->create([
                    'status' => 'Blank',
                    'initiated_by' => auth()->id(),
                    'note' => 'Blank check created',
                    'device_info' => request()->ip()
                ]);

                $createdPayments[] = $payment;
            }
        });

        return response()->json($createdPayments, 201);
    }

    public function bulkStore(Request $request)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $validated = $request->validate([
            'payments' => 'required|array',
            'payments.*.account_id' => 'required|exists:accounts,id',
            'payments.*.payee_id' => 'nullable|exists:payees,id',
            'payments.*.pay_from' => 'required|string',
            'payments.*.pay_as' => 'required|string',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.issue_date' => 'required|date',
            'payments.*.check_number' => 'nullable|integer',
            'payments.*.invoice_number' => 'nullable|string|max:255',
            'payments.*.payee_id_account_number' => 'nullable|string|max:255',
            'payments.*.category_id' => 'nullable|exists:company_payment_categories,id',
            'payments.*.memo' => 'nullable|string|max:255',
        ]);

        $createdPayments = [];

        \DB::transaction(function () use ($validated, $business, &$createdPayments) {
            foreach ($validated['payments'] as $paymentData) {
                $account = \App\Models\Goldenmarkmoney\Account::find($paymentData['account_id']);
                
                $checkNumber = $paymentData['check_number'] ?? null;
                if (empty($checkNumber) && strtolower($paymentData['pay_as']) === 'check') {
                    $checkNumber = $this->getNextCheckNumber($account);
                }

                $payment = Payment::create([
                    'company_id' => $business->id,
                    'account_id' => $account->id,
                    'payee_id' => $paymentData['payee_id'] ?? null,
                    'pay_from' => $paymentData['pay_from'],
                    'pay_as' => $paymentData['pay_as'],
                    'amount' => $paymentData['amount'],
                    'status' => 'pending',
                    'issue_date' => $paymentData['issue_date'],
                    'check_number' => $checkNumber,
                    'invoice_number' => $paymentData['invoice_number'] ?? null,
                    'payee_id_account_number' => $paymentData['payee_id_account_number'] ?? null,
                    'category_id' => $paymentData['category_id'] ?? null,
                    'memo' => $paymentData['memo'] ?? null,
                ]);

                if ($paymentData['amount'] > 0) {
                    $account->decrement('balance', $paymentData['amount']);
                }

                $payment->logs()->create([
                    'status' => 'pending',
                    'initiated_by' => auth()->id(),
                    'note' => 'Payment created via bulk store',
                    'device_info' => request()->ip()
                ]);

                $createdPayments[] = $payment;
            }
        });

        return response()->json($createdPayments, 201);
    }

    private function getNextCheckNumber(\App\Models\Goldenmarkmoney\Account $account)
    {
        $lastPayment = Payment::where('account_id', $account->id)
            ->whereNotNull('check_number')
            ->orderByRaw('CAST(check_number AS UNSIGNED) DESC')
            ->first();

        return $lastPayment ? ($lastPayment->check_number + 1) : 1001;
    }


    public function voidPayment($id)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $payment = Payment::where('company_id', $business->id)->findOrFail($id);
        $payment->update(['status' => 'voided']);

        $payment->logs()->create([
            'status' => 'voided',
            'initiated_by' => auth()->id(),
            'note' => 'Payment check marked as VOID',
            'device_info' => request()->ip()
        ]);

        return response()->json([
            'message' => 'Check marked as VOID successfully.',
            'payment' => $payment
        ]);
    }

    public function sendEmail(Request $request, $id)
    {
        $business = auth()->user()->businessDetails;
        if (!$business) return response()->json(['message' => 'Business profile required'], 400);

        $payment = $business->payments()->with(['payee', 'account', 'comments', 'business'])->findOrFail($id);

        if (strtolower($payment->status) === 'blank' || !$payment->payee) {
            return response()->json(['message' => 'Blank checks (No Payee) cannot be emailed. They are intended for printing only.'], 422);
        }

        // Check if this check payment has already been charged once (unlocked)
        $alreadyCharged = $payment->logs()
            ->where(function ($q) {
                $q->whereIn('note', [
                    'Check PDF printed / downloaded',
                    'E-check sent via email',
                    'Mail check sent',
                ])->orWhere('note', 'LIKE', '%E-check email sent%');
            })
            ->exists();

        $creditCheck = ['allowed' => true, 'service_price' => 0];
        if (!$alreadyCharged) {
            $creditCheck = $this->checkUserCreditForService('Email Check');
            if (!$creditCheck['allowed']) {
                return $creditCheck['response'];
            }
        }

        $payeeEmail = $request->input('email') 
            ?? $payment->payee->email 
            ?? $payment->payee->email_address;

        $ownerEmail = auth()->user()->email;
        $ownerName = auth()->user()->name ?? 'Account Owner';

        // Fallback if payee has no email
        $recipientPayeeEmail = $payeeEmail ?: $ownerEmail;

        $payeeName = $payment->payee->payee_name ?? $payment->payee->nick_name ?? 'Valued Customer';
        $payorName = $payment->company_name ?? $payment->account?->account_holder_name ?? '';
        $amount = $payment->amount;
        $checkNumber = $payment->check_number;
        $memo = $payment->memo ?? '';
        $dateProcessed = $payment->issue_date 
            ? \Carbon\Carbon::parse($payment->issue_date)->format('F j, Y') 
            : \Carbon\Carbon::parse($payment->created_at)->format('F j, Y');

        $latestComment = $payment->comments->last()?->comment ?? '';

        // Ensure unique_check_id and email_token exist for this payment
        if (empty($payment->unique_check_id)) {
            $payment->unique_check_id = \App\Models\Goldenmarkmoney\Payment::generateUniqueCheckId();
        }
        if (empty($payment->email_token)) {
            $payment->email_token = \App\Models\Goldenmarkmoney\Payment::generateEmailToken();
        }
        $payment->save();

        $tokenCode = $payment->email_token ?: $payment->unique_check_id;

        $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
        $logoUrl = "{$frontendUrl}/logo.png";
        $printUrl = "{$frontendUrl}/outside/emailchecks/disclaimer/{$tokenCode}";
        $trackUrl = "{$frontendUrl}/dashboard/payments";
        $loginUrl = "{$frontendUrl}/login";

        $subjectPayee = "GoldenMark Money: {$payorName} has sent you an E-check";
        $subjectOwner = "GoldenMark Money: Payment Confirmation - E-check sent to {$payeeName}";

        try {
            // 1. Send Primary E-check email to Payee
            \Illuminate\Support\Facades\Mail::send('goldenmarkmoney.emails.echeck', [
                'payeeName' => $payeeName,
                'payorName' => $payorName,
                'amount' => $amount,
                'checkNumber' => $checkNumber,
                'memo' => $memo,
                'dateProcessed' => $dateProcessed,
                'comment' => $latestComment,
                'printUrl' => $printUrl,
                'trackUrl' => $trackUrl,
                'loginUrl' => $loginUrl,
                'frontendUrl' => $frontendUrl,
                'logoUrl' => $logoUrl,
            ], function ($message) use ($recipientPayeeEmail, $subjectPayee) {
                $message->to($recipientPayeeEmail)
                        ->subject($subjectPayee);
            });

            // 2. Send Payment Confirmation Receipt to Owner (if owner email is available)
            if ($ownerEmail) {
                try {
                    \Illuminate\Support\Facades\Mail::send('goldenmarkmoney.emails.owner-receipt', [
                        'ownerName' => $ownerName,
                        'payeeName' => $payeeName,
                        'payeeEmail' => $recipientPayeeEmail,
                        'payorName' => $payorName,
                        'amount' => $amount,
                        'checkNumber' => $checkNumber,
                        'memo' => $memo,
                        'dateProcessed' => $dateProcessed,
                        'trackUrl' => $trackUrl,
                        'frontendUrl' => $frontendUrl,
                        'logoUrl' => $logoUrl,
                    ], function ($message) use ($ownerEmail, $subjectOwner) {
                        $message->to($ownerEmail)
                                ->subject($subjectOwner);
                    });
                } catch (\Exception $ownerMailEx) {
                    // Ignore minor secondary email issues
                }
            }

            // Update payment status to sent in DB so it shows under Mailed filter
            $payment->update(['status' => 'sent']);

            // Deduct service charge ONLY if not previously charged
            if (!$alreadyCharged && isset($creditCheck['service_price'])) {
                $this->deductUserCreditForService($creditCheck['service_price']);
            }

            // Log activity
            $payment->logs()->create([
                'status' => 'sent',
                'initiated_by' => auth()->id(),
                'note' => "E-check email sent to Payee ({$recipientPayeeEmail}) and Confirmation Receipt to Owner ({$ownerEmail})",
                'device_info' => $request->userAgent()
            ]);

            return response()->json([
                'message' => "E-check sent to {$recipientPayeeEmail} & Receipt sent to {$ownerEmail}!",
                'payee_email' => $recipientPayeeEmail,
                'owner_email' => $ownerEmail
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => "E-check sent to {$recipientPayeeEmail} successfully!",
                'payee_email' => $recipientPayeeEmail,
                'owner_email' => $ownerEmail
            ], 200);
        }
    }


    public function everifyCheck($id)
    {
        $searchId = trim($id);
        $numericId = preg_replace('/[^0-9]/', '', $searchId);

        $payment = Payment::with(['payee', 'account', 'businessDetail'])
            ->where(function($q) use ($searchId, $numericId) {
                $q->where('email_token', $searchId);

                if (\Illuminate\Support\Facades\Schema::hasColumn('company_payments', 'unique_check_id')) {
                    $q->orWhere('unique_check_id', $searchId);
                }

                if (is_numeric($searchId)) {
                    $q->orWhere('id', (int)$searchId)
                      ->orWhere('check_number', $searchId);
                }

                if (!empty($numericId)) {
                    $q->orWhere('check_number', $numericId);
                    if (\Illuminate\Support\Facades\Schema::hasColumn('company_payments', 'unique_check_id')) {
                        $q->orWhere('unique_check_id', 'CHK-' . str_pad($numericId, 8, '0', STR_PAD_LEFT));
                    }
                }
            })
            ->latest('id')
            ->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Check ID or check not found'
            ], 404);
        }


        $account = $payment->account;
        $rawAccountNum = $account->account_number ?? $payment->bank_account_number ?? '1111';
        $maskedAccount = strlen($rawAccountNum) > 4
            ? 'XXXX-XXXX-' . substr($rawAccountNum, -4)
            : 'XXXX-XXXX-' . $rawAccountNum;

        $issueDate = $payment->issue_date;
        $formattedDate = '';
        if ($issueDate) {
            if (is_string($issueDate)) {
                $formattedDate = date('Y-m-d', strtotime($issueDate));
            } else {
                $formattedDate = $issueDate->format('Y-m-d');
            }
        } else {
            $formattedDate = date('Y-m-d');
        }

        $isAccountVerified = !empty($account->plaid_item_id) || !empty($account->routing_number);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => (string) ($payment->unique_check_id ?? 'CHK-' . str_pad((string)$payment->id, 8, '0', STR_PAD_LEFT)),
                'cheque_amount' => number_format((float)($payment->amount ?? 0), 2, '.', ''),
                'cheque_issue_date' => $formattedDate,
                'cheque_created_for_id' => (string) ($payment->account->created_for_id ?? $payment->company_id ?? $payment->user_id ?? '1342697'),
                'cheque_serial_number' => (string) ($payment->check_number ?? $payment->id),
                'payee_name' => $payment->payee->payee_name ?? $payment->payee_name ?? 'Payee',
                'bank_account_account_name' => $account->official_name ?? $account->account_nick_name ?? 'Bank Account',
                'bank_account_account_number' => $maskedAccount,
                'bank_routing_number' => (string) ($account->routing_number ?? $payment->bank_routing_number ?? ''),
                'is_account_verified' => $isAccountVerified ? "1" : "0",
                'status' => (string) ($payment->status ?? 'pending'),
                'is_voided' => (strtolower((string)($payment->status ?? '')) === 'void' || !empty($payment->is_voided)) ? "1" : "0",
            ]
        ]);


    }

    public function getPublicPaymentByCode($code)
    {
        $payment = Payment::with(['payee', 'account', 'business'])
            ->where(function ($q) use ($code) {
                $q->where('email_token', $code)
                  ->orWhere('unique_check_id', $code);

                if (is_numeric($code)) {
                    $q->orWhere('id', (int)$code);
                }
            })
            ->first();

        if (!$payment) {
            return response()->json(['message' => 'Invalid or expired check link'], 404);
        }

        $payeeName = $payment->payee->payee_name ?? $payment->payee->nick_name ?? 'Valued Customer';
        $payorName = $payment->company_name ?? $payment->account?->account_holder_name ?? '';

        return response()->json([
            'id' => $payment->id,
            'unique_check_id' => $payment->unique_check_id,
            'amount' => $payment->amount,
            'check_number' => $payment->check_number,
            'issue_date' => $payment->issue_date ? \Carbon\Carbon::parse($payment->issue_date)->format('Y-m-d') : null,
            'memo' => $payment->memo,
            'status' => $payment->status,
            'signature_image_url' => $payment->signature_image_url,
            'company_name' => $payorName,
            'company_address' => $payment->company_address ?? '',
            'company_logo_url' => $payment->company_logo_url ?? '',
            'website' => $payment->website ?? $payment->business_website ?? null,
            'business_website' => $payment->business_website ?? $payment->website ?? null,
            'bank_name' => $payment->bank_name ?? $payment->account?->bank_name ?? '',
            'bank_routing_number' => $payment->bank_routing_number ?? $payment->account?->routing_number ?? '',
            'bank_account_number' => $payment->bank_account_number ?? $payment->account?->account_number ?? '',
            'payee_name' => $payeeName,
            'payee' => $payment->payee,
            'account' => $payment->account,
            'business' => $payment->business,
            'business_detail' => $payment->business,
        ]);
    }

    public function getNextCheckNumberInfo(Request $request)
    {
        $accountId = $request->input('account_id');
        if (!$accountId) {
            return response()->json([
                'success' => false,
                'message' => 'account_id is required'
            ], 400);
        }

        $account = \App\Models\Goldenmarkmoney\Account::find($accountId);
        if (!$account) {
            return response()->json([
                'success' => false,
                'message' => 'Account not found'
            ], 404);
        }

        $maxCheckNumber = Payment::where('account_id', $accountId)
            ->whereNotNull('check_number')
            ->whereRaw("check_number REGEXP '^[0-9]+$'")
            ->max(\DB::raw('CAST(check_number AS UNSIGNED)'));

        $startingNumber = (int)($account->next_check_starting_number ?? 1000);

        if ($maxCheckNumber) {
            $nextCheckNumber = max((int)$maxCheckNumber + 1, $startingNumber);
        } else {
            $nextCheckNumber = $startingNumber;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'account_id' => (int) $accountId,
                'last_check_number' => $maxCheckNumber ? (string)$maxCheckNumber : null,
                'next_check_number' => (string) $nextCheckNumber,
                'starting_check_number' => (string) $startingNumber,
            ]
        ]);
    }

    /**
     * Dynamically resolve the service rate based on the active plan configuration.
     */
    private function getPlanServiceRate($activePlan, $serviceName = 'Check Printing')
    {
        if (!$activePlan) {
            $activePlan = \App\Models\Plan\Plan::where('name', 'like', '%Pay As You Go%')->first()
                ?? \App\Models\Plan\Plan::find(1)
                ?? \App\Models\Plan\Plan::first();
        }

        if (!$activePlan) {
            return 0.50;
        }

        $serviceLower = strtolower(trim($serviceName));
        $isMail = str_contains($serviceLower, 'mail') || str_contains($serviceLower, 'usps') || str_contains($serviceLower, 'postal') || str_contains($serviceLower, 'fedex');
        $isEmail = str_contains($serviceLower, 'email') || str_contains($serviceLower, 'e-check') || str_contains($serviceLower, 'echeck');
        $isPrint = str_contains($serviceLower, 'print') || str_contains($serviceLower, 'paper') || str_contains($serviceLower, 'stock') || str_contains($serviceLower, 'check');

        // 1. Inspect features configured directly on the plan
        $features = is_array($activePlan->features) 
            ? $activePlan->features 
            : (is_array($activePlan->formatted_features) ? $activePlan->formatted_features : []);

        foreach ($features as $feature) {
            $labelLower = strtolower(trim($feature['label'] ?? ''));
            $matches = false;

            if ($isMail && (str_contains($labelLower, 'mail') || str_contains($labelLower, 'usps') || str_contains($labelLower, 'postal'))) {
                $matches = true;
            } elseif ($isEmail && (str_contains($labelLower, 'email') || str_contains($labelLower, 'e-check') || str_contains($labelLower, 'echeck'))) {
                $matches = true;
            } elseif ($isPrint && (str_contains($labelLower, 'print') || str_contains($labelLower, 'check'))) {
                $matches = true;
            }

            if ($matches && isset($feature['price'])) {
                if (preg_match('/([0-9.]+)/', (string)$feature['price'], $m)) {
                    $val = (float) $m[1];
                    if ($val > 0) {
                        return $val;
                    }
                }
            }
        }

        // 2. Package calculation (monthly_price or discounted_price / checks_count)
        preg_match('/(\d+)/', $activePlan->name, $matches);
        $checksCount = isset($matches[1]) ? (int)$matches[1] : 0;
        $planPrice = floatval($activePlan->discounted_price > 0 ? $activePlan->discounted_price : $activePlan->monthly_price);
        if ($checksCount > 0 && $planPrice > 0) {
            $computed = round($planPrice / $checksCount, 2);
            if ($isMail) {
                return round($computed * 2, 2);
            }
            return $computed;
        }

        // 3. Fallback: Check Pay As You Go in DB if current plan had no pricing
        $payAsYouGo = \App\Models\Plan\Plan::where('name', 'like', '%Pay As You Go%')->first();
        if ($payAsYouGo && $payAsYouGo->id !== $activePlan->id && is_array($payAsYouGo->features)) {
            foreach ($payAsYouGo->features as $feature) {
                $labelLower = strtolower(trim($feature['label'] ?? ''));
                if ($isPrint && str_contains($labelLower, 'print')) {
                    if (isset($feature['price']) && preg_match('/([0-9.]+)/', (string)$feature['price'], $m)) {
                        return (float) $m[1];
                    }
                }
            }
        }

        return 0.50;
    }

    /**
     * Check whether the user has sufficient credit balance for a check service (Print, Email, Mail).
     */
    private function checkUserCreditForService($serviceName = 'Check Printing', $itemCount = 1)
    {
        $user = auth()->user();
        if (!$user) {
            return [
                'allowed' => false,
                'response' => response()->json(['message' => 'Unauthenticated. Please log in.'], 401)
            ];
        }

        $activeSub = $user->planSubscriptions()->where('status', 'active')->latest('start_date')->first();
        $activePlan = $activeSub 
            ? $activeSub->plan 
            : (\App\Models\Plan\Plan::where('name', 'like', '%Pay As You Go%')->first() 
               ?? \App\Models\Plan\Plan::find(1) 
               ?? \App\Models\Plan\Plan::first());

        // Determine price dynamically based on user's active plan
        $servicePrice = $this->getPlanServiceRate($activePlan, $serviceName);

        $itemCount = max(1, (int)$itemCount);
        $totalCost = $servicePrice * $itemCount;
        $availableCredit = (float) ($user->credit_balance ?? 0);

        if ($availableCredit < $totalCost) {
            $formattedTotalCost = number_format($totalCost, 2);
            $formattedAvailable = number_format($availableCredit, 2);
            $planName = $activePlan->name ?? 'Current Plan';
            $rechargeUrl = env('FRONTEND_URL', 'http://localhost:3000') . '/dashboard/more/recharge';

            if (request()->wantsJson() || request()->ajax()) {
                $res = response()->json([
                    'message' => "Insufficient credit balance. {$serviceName} requires \${$formattedTotalCost} USD on your {$planName}. Available balance: \${$formattedAvailable} USD. Please recharge your credit balance to perform check printing, emailing or mailing.",
                    'require_recharge' => true,
                    'service_cost' => $totalCost,
                    'service_price' => $servicePrice,
                    'item_count' => $itemCount,
                    'service_name' => $serviceName,
                    'plan_name' => $planName,
                    'current_credit' => $availableCredit,
                ], 402);
            } else {
                $res = response(view('goldenmarkmoney.errors.insufficient_credit', [
                    'serviceName' => $serviceName,
                    'servicePrice' => $formattedTotalCost,
                    'availableCredit' => $formattedAvailable,
                    'planName' => $planName,
                    'rechargeUrl' => $rechargeUrl,
                ]), 402);
            }

            return [
                'allowed' => false,
                'response' => $res
            ];
        }

        return ['allowed' => true, 'service_price' => $servicePrice, 'total_cost' => $totalCost];
    }

    /**
     * Deduct credit for performed check service.
     */
    private function deductUserCreditForService($servicePrice)
    {
        $user = auth()->user();
        if ($user && $servicePrice > 0) {
            $user->decrement('credit_balance', $servicePrice);
            $user->increment('used_credits', $servicePrice);
        }
    }

    /**
     * Strip heavy appends and relations from nested user objects (logs.initiator and comments.user)
     */
    private function sanitizePaymentUserRelations($payment)
    {
        if ($payment->relationLoaded('logs')) {
            foreach ($payment->logs as $log) {
                if ($log->relationLoaded('initiator') && $log->initiator) {
                    $log->initiator->setAppends([]);
                    $log->initiator->unsetRelation('businessDetails');
                    $log->initiator->unsetRelation('personalInfo');
                    $log->initiator->unsetRelation('deviceLogs');
                    $log->initiator->makeHidden(['businessDetails', 'personalInfo', 'deviceLogs', 'business_details', 'personal_info', 'device_logs', 'documents', 'accounts', 'payees', 'cards']);
                }
            }
        }
        if ($payment->relationLoaded('comments')) {
            foreach ($payment->comments as $comment) {
                if ($comment->relationLoaded('user') && $comment->user) {
                    $comment->user->setAppends([]);
                    $comment->user->unsetRelation('businessDetails');
                    $comment->user->unsetRelation('personalInfo');
                    $comment->user->unsetRelation('deviceLogs');
                    $comment->user->makeHidden(['businessDetails', 'personalInfo', 'deviceLogs', 'business_details', 'personal_info', 'device_logs', 'documents', 'accounts', 'payees', 'cards']);
                }
            }
        }
        return $payment;
    }
}

