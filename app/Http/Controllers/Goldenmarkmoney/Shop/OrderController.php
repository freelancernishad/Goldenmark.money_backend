<?php

namespace App\Http\Controllers\Goldenmarkmoney\Shop;

use App\Http\Controllers\Controller;
use App\Models\Shop\Order;
use App\Models\Shop\OrderItem;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;
use Exception;

class OrderController extends Controller
{
    private function initStripe()
    {
        $secretKey = SystemSetting::getValue('STRIPE_SECRET', config('services.stripe.secret'));
        if (!$secretKey) {
            throw new Exception('Stripe API key is not configured in System Settings.');
        }
        Stripe::setApiKey($secretKey);
    }

    /**
     * Get paginated orders list with search and status filtering.
     * High performance optimized: excludes heavy longtext custom_check_details payloads.
     * Note: Does NOT generate any payment URLs or trigger external Stripe calls.
     */
    public function index(Request $request)
    {
        $query = Order::select([
            'id',
            'order_number',
            'payment_token',
            'user_id',
            'customer_name',
            'customer_email',
            'customer_phone',
            'total_amount',
            'payment_status',
            'payment_method',
            'payment_link',
            'order_status',
            'created_at',
            'updated_at',
        ])->with([
            'items' => function ($q) {
                $q->select([
                    'id',
                    'order_id',
                    'product_id',
                    'product_title',
                    'item_code',
                    'selected_color',
                    'quantity',
                    'unit_price',
                    'total_price',
                ]);
            }
        ]);

        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhere('customer_name', 'like', "%{$search}%")
                  ->orWhere('customer_email', 'like', "%{$search}%")
                  ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('order_status', $request->input('status'));
        }

        $perPage = min(100, max(1, (int) ($request->input('per_page') ?? $request->input('perPage') ?? 15)));
        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json($orders);
    }

    /**
     * Get details for a single order.
     */
    public function show($id)
    {
        $order = Order::with('items')->where('id', $id)->orWhere('order_number', $id)->firstOrFail();
        return response()->json($order);
    }

    /**
     * Store a newly created order directly (Pending Admin Review & Approval).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:255',
            'customer_email' => 'required|email|max:255',
            'customer_phone' => 'nullable|string|max:50',
            'shipping_address' => 'nullable|array',
            'billing_address' => 'nullable|array',
            'total_amount' => 'nullable|numeric',
            'delivery_price' => 'nullable|numeric',
            'tax_amount' => 'nullable|numeric',
            'tax_rate' => 'nullable|numeric',
            'tax_state' => 'nullable|string',
            'payment_status' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'order_status' => 'nullable|string',
            'custom_check_details' => 'nullable|array',
            'items' => 'required|array|min:1',
        ]);

        $orderNumber = 'ORD-' . rand(10000, 99999);

        // Calculate total if not provided
        $totalAmount = isset($validated['total_amount']) ? (float) $validated['total_amount'] : 0.0;
        if ($totalAmount <= 0) {
            $itemsSum = array_reduce($validated['items'], function ($carry, $it) {
                $itemTotal = $it['total_price'] ?? $it['totalPrice'] ?? (($it['unit_price'] ?? $it['unitPrice'] ?? 0) * ($it['quantity'] ?? 1));
                return $carry + (float) $itemTotal;
            }, 0.0);
            $deliveryPrice = (float) ($validated['delivery_price'] ?? 0);
            $taxAmount = (float) ($validated['tax_amount'] ?? 0);
            $totalAmount = round($itemsSum + $deliveryPrice + $taxAmount, 2);
        }

        $primaryCustomDetails = !empty($validated['items'][0]['customCheckDetails']) && is_array($validated['items'][0]['customCheckDetails'])
            ? $validated['items'][0]['customCheckDetails']
            : (!empty($validated['items'][0]['custom_check_details']) && is_array($validated['items'][0]['custom_check_details']) ? $validated['items'][0]['custom_check_details'] : []);

        $customCheckDetails = array_merge(
            $primaryCustomDetails,
            $validated['custom_check_details'] ?? [],
            [
                'delivery_price' => $validated['delivery_price'] ?? 0,
                'tax_amount' => $validated['tax_amount'] ?? 0,
                'tax_rate' => $validated['tax_rate'] ?? 0,
                'tax_state' => $validated['tax_state'] ?? null,
                'items_raw' => $validated['items'],
            ]
        );

        $order = Order::create([
            'order_number' => $orderNumber,
            'user_id' => auth()->id() ?? null,
            'customer_name' => $validated['customer_name'],
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'] ?? null,
            'shipping_address' => $validated['shipping_address'] ?? null,
            'billing_address' => $validated['billing_address'] ?? ($validated['shipping_address'] ?? null),
            'total_amount' => $totalAmount,
            'payment_status' => $validated['payment_status'] ?? 'unpaid',
            'payment_method' => $validated['payment_method'] ?? 'Awaiting Approval & Payment Link',
            'order_status' => $validated['order_status'] ?? 'pending',
            'custom_check_details' => $customCheckDetails,
        ]);

        foreach ($validated['items'] as $item) {
            $itemCustomDetails = $item['customCheckDetails'] ?? $item['custom_check_details'] ?? null;
            $order->items()->create([
                'product_id' => $item['product_id'] ?? $item['productId'] ?? null,
                'product_title' => $item['product_title'] ?? $item['title'] ?? 'Custom Printed Check Order',
                'item_code' => $item['item_code'] ?? $item['itemCode'] ?? null,
                'selected_color' => $item['selected_color'] ?? $item['colorName'] ?? null,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'unit_price' => (float) ($item['unit_price'] ?? $item['unitPrice'] ?? 0.0),
                'total_price' => (float) ($item['total_price'] ?? $item['totalPrice'] ?? 0.0),
                'custom_check_details' => $itemCustomDetails,
            ]);
        }

        return response()->json([
            'message' => 'Order submitted successfully. Awaiting admin review & approval.',
            'order_number' => $orderNumber,
            'order' => $order->load('items')
        ], 201);
    }

    /**
     * Helper to create Stripe Checkout Session on-demand.
     */
    protected function createStripeCheckoutSessionForOrder(Order $order, string $frontendUrl)
    {
        $this->initStripe();

        $token = $order->payment_token ?: $order->order_number;
        $successUrl = rtrim($frontendUrl, '/') . '/shop/checkout/success?session_id={CHECKOUT_SESSION_ID}&order_number=' . $order->order_number;
        $cancelUrl = rtrim($frontendUrl, '/') . '/pay/' . $token;

        $lineItems = [];
        foreach ($order->items as $item) {
            $itemTotal = (float) $item->total_price;
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $item->product_title . (!empty($item->selected_color) ? ' (' . $item->selected_color . ')' : ''),
                        'description' => 'Approved Check Order (' . $item->quantity . ' checks) - #' . $order->order_number,
                    ],
                    'unit_amount' => (int) round($itemTotal * 100),
                ],
                'quantity' => 1,
            ];
        }

        // Include delivery if stored
        $customDetails = is_array($order->custom_check_details) ? $order->custom_check_details : [];
        $deliveryPrice = (float) ($customDetails['delivery_price'] ?? 0);
        if ($deliveryPrice > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Shipping & Expedited Printing',
                    ],
                    'unit_amount' => (int) round($deliveryPrice * 100),
                ],
                'quantity' => 1,
            ];
        }

        // Include tax if stored
        $taxAmount = (float) ($customDetails['tax_amount'] ?? 0);
        if ($taxAmount > 0) {
            $taxRate = $customDetails['tax_rate'] ?? 0;
            $taxState = $customDetails['tax_state'] ?? null;
            $lineItems[] = [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'State Sales Tax' . ($taxRate > 0 ? " ({$taxRate}%)" : ''),
                        'description' => $taxState ? "Sales tax calculated for {$taxState}" : 'Sales Tax',
                    ],
                    'unit_amount' => (int) round($taxAmount * 100),
                ],
                'quantity' => 1,
            ];
        }

        // Calculate expected sum of line items in cents
        $lineItemsTotalCents = array_reduce($lineItems, function ($carry, $li) {
            return $carry + ($li['price_data']['unit_amount'] * $li['quantity']);
        }, 0);

        $orderTotalCents = (int) round(((float) $order->total_amount) * 100);

        // Safeguard: Ensure Stripe checkout total matches order total exactly
        if (empty($lineItems) || $lineItemsTotalCents !== $orderTotalCents) {
            $lineItems = [
                [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => 'Custom Check Order #' . $order->order_number,
                            'description' => 'Personalized Check Order & Delivery',
                        ],
                        'unit_amount' => max(1, $orderTotalCents),
                    ],
                    'quantity' => 1,
                ]
            ];
        }

        return StripeSession::create([
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'client_reference_id' => (string) $order->order_number,
            'customer_email' => $order->customer_email,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'order_id' => (string) $order->id,
                'order_number' => (string) $order->order_number,
                'payment_token' => (string) ($order->payment_token ?? ''),
                'customer_name' => (string) ($order->customer_name ?? ''),
                'customer_email' => (string) ($order->customer_email ?? ''),
            ],
        ]);
    }

    /**
     * Admin approves an order, generates a system public payment URL with unique token, and emails it to the customer.
     * Direct Stripe link is NOT generated here; it will be generated on demand when customer visits the unique URL.
     */
    public function approveOrder(Request $request, $id)
    {
        $order = Order::with('items')->where('id', $id)->orWhere('order_number', $id)->firstOrFail();

        if (empty($order->payment_token)) {
            $order->payment_token = (string) \Illuminate\Support\Str::uuid();
        }

        $frontendUrl = $request->input('frontend_url')
            ?: $request->header('origin')
            ?: config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));

        $publicPaymentUrl = rtrim($frontendUrl, '/') . '/pay/' . $order->payment_token;

        // Update Order
        $order->order_status = 'awaiting_payment';
        $order->payment_method = 'Stripe Credit Card';
        $order->payment_link = $publicPaymentUrl;

        $customDetails = is_array($order->custom_check_details) ? $order->custom_check_details : [];
        $customDetails['payment_link'] = $publicPaymentUrl;
        $order->custom_check_details = $customDetails;
        $order->save();

        // Send Email to Customer with System's Public Payment URL
        $emailSent = false;
        try {
            Mail::send('goldenmarkmoney.emails.shop-order-approved', [
                'order' => $order,
                'paymentLink' => $publicPaymentUrl,
                'frontendUrl' => $frontendUrl,
            ], function ($message) use ($order) {
                $message->to($order->customer_email)
                        ->subject("Your Check Order #{$order->order_number} has been Approved - Complete Payment");
            });
            $emailSent = true;
        } catch (Exception $mailEx) {
            Log::error("Failed to send approval email to {$order->customer_email}: " . $mailEx->getMessage());
        }

        return response()->json([
            'message' => 'Order approved successfully and payment link sent to customer.',
            'payment_link' => $publicPaymentUrl,
            'payment_token' => $order->payment_token,
            'email_sent' => $emailSent,
            'order' => $order->load('items'),
        ]);
    }

    /**
     * Resend Payment Link Email to customer with system's unique public payment URL.
     */
    public function resendPaymentLink(Request $request, $id)
    {
        $order = Order::with('items')->where('id', $id)->orWhere('order_number', $id)->firstOrFail();

        if ($order->payment_status === 'paid') {
            return response()->json([
                'message' => 'This order has already been paid. Payment link cannot be resent.',
                'payment_status' => 'paid',
                'order' => $order,
            ], 400);
        }

        if (empty($order->payment_token)) {
            $order->payment_token = (string) \Illuminate\Support\Str::uuid();
        }

        $frontendUrl = $request->input('frontend_url')
            ?: $request->header('origin')
            ?: config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));

        $publicPaymentUrl = rtrim($frontendUrl, '/') . '/pay/' . $order->payment_token;

        $order->payment_link = $publicPaymentUrl;
        $customDetails = is_array($order->custom_check_details) ? $order->custom_check_details : [];
        $customDetails['payment_link'] = $publicPaymentUrl;
        $order->custom_check_details = $customDetails;
        $order->save();

        try {
            Mail::send('goldenmarkmoney.emails.shop-order-approved', [
                'order' => $order,
                'paymentLink' => $publicPaymentUrl,
                'frontendUrl' => $frontendUrl,
            ], function ($message) use ($order) {
                $message->to($order->customer_email)
                        ->subject("Payment Reminder: Your Check Order #{$order->order_number} is Ready for Payment");
            });
            return response()->json([
                'message' => 'Payment link email resent successfully.',
                'payment_link' => $publicPaymentUrl,
                'payment_token' => $order->payment_token,
            ]);
        } catch (Exception $mailEx) {
            Log::error("Resend Payment Link Error: " . $mailEx->getMessage());
            return response()->json([
                'message' => 'Failed to send email: ' . $mailEx->getMessage(),
                'payment_link' => $publicPaymentUrl,
            ], 500);
        }
    }

    /**
     * Get public payment info by unique token (or order_number/id fallback).
     */
    /**
     * Get public payment info by unique token (or order_number/id fallback).
     */
    public function getPayInfo(Request $request, $token)
    {
        $orderQuery = Order::with('items')
            ->where('payment_token', $token)
            ->orWhere('order_number', $token);

        if (is_numeric($token)) {
            $orderQuery = $orderQuery->orWhere('id', (int) $token);
        }

        $order = $orderQuery->first();

        if (!$order) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Invalid or expired payment link.',
            ], 404);
        }

        return response()->json([
            'token' => $token,
            'is_paid' => ($order->payment_status === 'paid'),
            'is_cancelled' => ($order->order_status === 'cancelled'),
            'order' => $order,
        ]);
    }

    /**
     * Initiate payment on demand from the public payment page using unique token.
     * Generates a Stripe checkout link dynamically.
     * Once payment is completed (or if already paid), this will reject further payment.
     */
    public function initiatePayment(Request $request, $token)
    {
        $orderQuery = Order::with('items')
            ->where('payment_token', $token)
            ->orWhere('order_number', $token);

        if (is_numeric($token)) {
            $orderQuery = $orderQuery->orWhere('id', (int) $token);
        }

        $order = $orderQuery->first();

        if (!$order) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Order not found.',
            ], 404);
        }

        // Check if already paid
        if ($order->payment_status === 'paid') {
            return response()->json([
                'token' => $token,
                'is_paid' => true,
                'status' => 'paid',
                'message' => 'This order has already been paid and cannot be paid again.',
                'order' => $order,
            ], 200);
        }

        // Check if cancelled
        if ($order->order_status === 'cancelled') {
            return response()->json([
                'token' => $token,
                'is_paid' => false,
                'is_cancelled' => true,
                'status' => 'cancelled',
                'message' => 'This order has been cancelled and cannot be paid.',
                'order' => $order,
            ], 400);
        }

        $frontendUrl = $request->input('frontend_url')
            ?: $request->header('origin')
            ?: config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));

        try {
            $stripeSession = $this->createStripeCheckoutSessionForOrder($order, $frontendUrl);
            $checkoutUrl = $stripeSession->url;
            $sessionId = $stripeSession->id;

            $customDetails = is_array($order->custom_check_details) ? $order->custom_check_details : [];
            $customDetails['stripe_session_id'] = $sessionId;
            $order->custom_check_details = $customDetails;
            $order->save();

            return response()->json([
                'token' => $token,
                'is_paid' => false,
                'url' => $checkoutUrl,
                'session_id' => $sessionId,
                'order' => $order,
            ]);
        } catch (Exception $e) {
            Log::warning("Stripe Checkout generation fallback for order #{$order->order_number}: " . $e->getMessage());

            $mockSessionId = 'mock_session_' . rand(10000, 99999);
            $mockUrl = rtrim($frontendUrl, '/') . '/shop/checkout/success?session_id=' . $mockSessionId . '&order_number=' . $order->order_number;

            $customDetails = is_array($order->custom_check_details) ? $order->custom_check_details : [];
            $customDetails['stripe_session_id'] = $mockSessionId;
            $order->custom_check_details = $customDetails;
            $order->save();

            return response()->json([
                'token' => $token,
                'is_paid' => false,
                'url' => $mockUrl,
                'session_id' => $mockSessionId,
                'order' => $order,
                'warning' => 'Stripe fallback mode: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Browser redirect endpoint using unique token:
     * If visited directly in browser, redirects to frontend pay page or Stripe checkout.
     */
    public function payRedirect(Request $request, $token)
    {
        $orderQuery = Order::with('items')
            ->where('payment_token', $token)
            ->orWhere('order_number', $token);

        if (is_numeric($token)) {
            $orderQuery = $orderQuery->orWhere('id', (int) $token);
        }

        $order = $orderQuery->firstOrFail();

        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        $payToken = $order->payment_token ?: $order->order_number;

        if ($order->payment_status === 'paid' || $order->order_status === 'cancelled') {
            return redirect(rtrim($frontendUrl, '/') . '/pay/' . $payToken);
        }

        try {
            $stripeSession = $this->createStripeCheckoutSessionForOrder($order, $frontendUrl);

            $customDetails = is_array($order->custom_check_details) ? $order->custom_check_details : [];
            $customDetails['stripe_session_id'] = $stripeSession->id;
            $order->custom_check_details = $customDetails;
            $order->payment_method = 'Stripe Credit Card (' . $stripeSession->id . ')';
            $order->save();

            return redirect()->away($stripeSession->url);
        } catch (Exception $e) {
            Log::warning("Stripe payRedirect fallback: " . $e->getMessage());
            return redirect(rtrim($frontendUrl, '/') . '/pay/' . $payToken);
        }
    }

    /**
     * Update order status and tracking number.
     */
    public function updateStatus(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'order_status' => 'required|string|in:pending,awaiting_payment,processing,printed_shipped,delivered,cancelled',
            'tracking_number' => 'nullable|string|max:255',
        ]);

        $order->order_status = $validated['order_status'];
        if ($request->has('tracking_number')) {
            $order->tracking_number = $validated['tracking_number'];
        }
        $order->save();

        return response()->json([
            'message' => 'Order status updated successfully',
            'order' => $order->load('items')
        ]);
    }

    /**
     * Update order custom check design details and layout configuration.
     */
    public function updateCheckDetails(Request $request, $id)
    {
        $order = Order::findOrFail($id);

        $validated = $request->validate([
            'custom_check_details' => 'required|array',
        ]);

        $existingDetails = is_array($order->custom_check_details) ? $order->custom_check_details : [];
        $mergedDetails = array_merge($existingDetails, $validated['custom_check_details']);

        $order->custom_check_details = $mergedDetails;
        $order->save();

        // Also update individual shop_order_items if items_raw is updated
        if (isset($mergedDetails['items_raw']) && is_array($mergedDetails['items_raw'])) {
            $orderItems = $order->items()->orderBy('id', 'asc')->get();
            foreach ($mergedDetails['items_raw'] as $idx => $rawItem) {
                if (isset($orderItems[$idx])) {
                    $itemCustom = $rawItem['customCheckDetails'] ?? $rawItem['custom_check_details'] ?? null;
                    if ($itemCustom) {
                        $orderItems[$idx]->custom_check_details = $itemCustom;
                        $orderItems[$idx]->save();
                    }
                }
            }
        }

        return response()->json([
            'message' => 'Order check design configuration updated successfully',
            'order' => $order->load('items')
        ]);
    }

    /**
     * Delete an order.
     */
    public function destroy($id)
    {
        $order = Order::findOrFail($id);
        $order->delete();

        return response()->json(['message' => 'Order deleted successfully']);
    }
}
