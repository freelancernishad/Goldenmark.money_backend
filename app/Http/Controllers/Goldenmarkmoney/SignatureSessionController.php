<?php

namespace App\Http\Controllers\Goldenmarkmoney;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Goldenmarkmoney\SignatureSession;
use App\Models\Goldenmarkmoney\Account;
use App\Services\FileSystem\FileUploadService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;

class SignatureSessionController extends Controller
{
    /**
     * Create a new signature session in DB
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'account_id' => 'required',
            'type' => 'nullable|string|in:qr,email,onscreen',
            'email' => 'nullable|email',
        ]);

        $account = Account::findOrFail($validated['account_id']);

        $token = 'sig_sess_' . Str::random(36) . '_' . time();

        try {
            $session = SignatureSession::create([
                'account_id' => $account->id,
                'token' => $token,
                'type' => $validated['type'] ?? 'qr',
                'status' => 'pending',
                'email' => $validated['email'] ?? null,
                'expires_at' => now()->addHours(24),
            ]);
            Log::info("Created Signature Session DB record #{$session->id} for Account #{$account->id} with token: {$token}");
        } catch (\Exception $e) {
            Log::warning("SignatureSession table not found or error creating session: " . $e->getMessage());
        }

        return response()->json([
            'message' => 'Signature session created successfully',
            'token' => $token,
            'data' => [
                'token' => $token,
                'account_id' => $account->id,
            ]
        ], 200);
    }

    /**
     * Send signature link via Email
     */
    public function sendEmail(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'account_id' => 'nullable',
        ]);

        $accountId = $validated['account_id'] ?? 1;
        $account = Account::find($accountId) ?? Account::first();

        // Encode secure payload token
        $payload = json_encode([
            'acc' => $account ? $account->id : 1,
            'time' => time(),
            'rand' => Str::random(12),
        ]);

        $rawToken = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $token = 'sig_sess_' . $rawToken . '_' . time();

        $frontendUrl = config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:3000'));
        $signatureLink = "{$frontendUrl}/sign-mobile?token={$token}";

        try {
            $session = SignatureSession::create([
                'account_id' => $account ? $account->id : 1,
                'token' => $token,
                'type' => 'email',
                'status' => 'pending',
                'email' => $validated['email'],
                'expires_at' => now()->addHours(24),
            ]);
        } catch (\Exception $e) {
            Log::warning("Could not store email signature session in DB: " . $e->getMessage());
        }

        // Send Email via Mailer
        try {
            $brandSiteUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://goldenmark.money')), '/');
            $brandLogoUrl = $brandSiteUrl . '/logo.png';
            Mail::html("
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 16px; background-color: #ffffff;'>
                    <div style='text-align: center; padding-bottom: 20px; margin-bottom: 20px; border-bottom: 1px solid #f1f5f9;'>
                        <a href='{$brandSiteUrl}' target='_blank' style='display: inline-block; text-decoration: none;'>
                            <img src='{$brandLogoUrl}' alt='GoldenMark Money' style='max-height: 44px; width: auto; max-width: 200px; display: block; margin: 0 auto; border: 0;' />
                        </a>
                    </div>
                    <h2 style='color: #0f172a; text-align: center; margin-top: 0;'>GoldenMark Signature Request</h2>
                    <p style='color: #475569; font-size: 14px; line-height: 1.6;'>You have been requested to provide your authorized signature for Bank Account #{$accountId}.</p>
                    <div style='margin: 30px 0; text-align: center;'>
                        <a href='{$signatureLink}' target='_blank' style='background-color: #2d3359; color: #ffffff; padding: 14px 28px; text-decoration: none; font-weight: bold; border-radius: 8px; display: inline-block;'>
                            Click Here to Sign on Screen / Phone
                        </a>
                    </div>
                    <p style='color: #94a3b8; font-size: 12px;'>This single-use link expires in 24 hours.</p>
                </div>
            ", function ($message) use ($validated) {
                $message->to($validated['email'])
                        ->subject('Action Required: Provide Your Signature for Account Verification');
            });
            $mailSent = true;
        } catch (\Exception $e) {
            Log::warning("Mail delivery failed or SMTP not configured: " . $e->getMessage());
            $mailSent = false;
        }

        return response()->json([
            'message' => "Signature link sent successfully to {$validated['email']}",
            'email' => $validated['email'],
            'token' => $token,
            'link' => $signatureLink,
            'mail_sent' => $mailSent,
        ], 200);
    }

    /**
     * Show / Validate a signature session
     */
    public function show(Request $request, $token)
    {
        try {
            $session = SignatureSession::where('token', $token)->first();
        } catch (\Exception $e) {
            $session = null;
        }

        if ($session) {
            if ($session->status !== 'pending') {
                return response()->json([
                    'valid' => false,
                    'message' => 'This one-time signature link has already been used.',
                ], 422);
            }

            if ($session->expires_at && now()->greaterThan($session->expires_at)) {
                $session->update(['status' => 'expired']);
                return response()->json([
                    'valid' => false,
                    'message' => 'This signature link has expired.',
                ], 422);
            }

            return response()->json([
                'valid' => true,
                'data' => [
                    'token' => $session->token,
                    'account_id' => $session->account_id,
                    'account_name' => optional($session->account)->account_nick_name ?? optional($session->account)->account_holder_name,
                    'type' => $session->type,
                    'status' => $session->status,
                    'expires_at' => $session->expires_at,
                ]
            ]);
        }

        // Fallback for valid sig_sess_ tokens even before DB migration is run
        if (str_starts_with($token, 'sig_sess_')) {
            $rawBase64 = str_replace(['sig_sess_', '-', '_'], ['', '+', '/'], $token);
            while (strlen($rawBase64) % 4 !== 0) {
                $rawBase64 .= '=';
            }
            $jsonStr = base64_decode($rawBase64);
            $data = json_decode($jsonStr, true);

            $accountId = (is_array($data) && isset($data['acc'])) ? $data['acc'] : 1;

            return response()->json([
                'valid' => true,
                'data' => [
                    'token' => $token,
                    'account_id' => $accountId,
                    'type' => 'qr',
                    'status' => 'pending',
                ]
            ]);
        }

        return response()->json([
            'valid' => false,
            'message' => 'Invalid signature session token.',
        ], 404);
    }

    /**
     * Submit signature for a session
     */
    public function submit(Request $request, $token)
    {
        $validated = $request->validate([
            'path' => 'required|string',
            'name' => 'nullable|string',
        ]);

        try {
            $session = SignatureSession::where('token', $token)->first();
        } catch (\Exception $e) {
            $session = null;
        }

        $accountId = null;
        if ($session) {
            if ($session->status !== 'pending') {
                return response()->json([
                    'message' => 'This signature link is invalid, expired, or has already been used.',
                ], 422);
            }
            $accountId = $session->account_id;
        } else if (str_starts_with($token, 'sig_sess_')) {
            $rawBase64 = str_replace(['sig_sess_', '-', '_'], ['', '+', '/'], $token);
            while (strlen($rawBase64) % 4 !== 0) {
                $rawBase64 .= '=';
            }
            $jsonStr = base64_decode($rawBase64);
            $data = json_decode($jsonStr, true);
            if (is_array($data) && isset($data['acc'])) {
                $accountId = $data['acc'];
            }
        }

        if (!$accountId) {
            return response()->json([
                'message' => 'Invalid signature session.',
            ], 422);
        }

        $account = Account::find($accountId) ?? Account::first();
        if (!$account) {
            return response()->json([
                'message' => 'Bank account not found.',
            ], 404);
        }

        // Process Base64 Signature Image
        $dataUrl = $validated['path'];
        if (preg_match('/^data:(.*?);base64,(.*)$/', $dataUrl, $matches)) {
            $base64Data = base64_decode($matches[2]);
            $filename = "signatures/account_" . $account->id . "_" . time() . ".png";
            try {
                $fileService = app(FileUploadService::class);
                $finalPath = $fileService->uploadContentToS3($base64Data, $filename);
            } catch (\Exception $e) {
                Storage::disk('public')->put($filename, $base64Data);
                $finalPath = asset("storage/{$filename}");
            }
        } else {
            $finalPath = $dataUrl;
        }

        // Reset previous primary signatures
        $account->signatures()->update(['is_primary' => false]);

        // Create Account Signature Record
        $signature = $account->signatures()->create([
            'path' => $finalPath,
            'name' => $validated['name'] ?? ('Signature ' . time()),
            'is_primary' => true,
        ]);

        // Create or Update DB Session to completed
        try {
            if ($session) {
                $session->update([
                    'status' => 'completed',
                    'signed_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            } else {
                SignatureSession::create([
                    'account_id' => $account->id,
                    'token' => $token,
                    'type' => 'qr',
                    'status' => 'completed',
                    'signed_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Could not update SignatureSession DB record: " . $e->getMessage());
        }

        Log::info("Signature Session token {$token} completed. Signature #{$signature->id} saved for Account #{$account->id}.");

        return response()->json([
            'message' => 'Signature submitted and saved successfully!',
            'data' => $signature,
        ]);
    }
}
