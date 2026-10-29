<?php

namespace App\Http\Controllers\Admin\UserManagement;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class AdminUserAccessController extends Controller
{
    /**
     * Auto-login (Impersonate) into user dashboard as admin.
     * Generates a valid JWT token for the user guard.
     */
    public function impersonate($id)
    {
        $user = User::findOrFail($id);

        if ($user->is_blocked) {
            return response()->json([
                'message' => 'Cannot login: User account is currently blocked.'
            ], 403);
        }

        // Generate user JWT token targeting the 'user' guard
        $token = JWTAuth::fromUser($user, ['guard' => 'user']);

        return response()->json([
            'message' => 'Impersonation session established successfully',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role ?? 'user',
                'email_verified' => !is_null($user->email_verified_at),
                'credit_balance' => (float) ($user->credit_balance ?? 0),
                'has_2fa_enabled' => $user->hasTwoFactorEnabled(),
            ]
        ]);
    }

    /**
     * Get 2FA QR code and secret details for a specific user (Admin view).
     */
    public function getTwoFactor($id)
    {
        $user = User::findOrFail($id);
        $secret = $user->google2fa_secret;
        $enabled = $user->hasTwoFactorEnabled();

        $qrCodeUrl = null;
        $qrCodeSvg = null;
        $currentOtp = null;

        if (!empty($secret)) {
            $google2fa = new Google2FA();
            $appName = config('app.name', 'goldenmark.money');
            if ($appName === 'Laravel') {
                $appName = 'goldenmark.money';
            }
            $qrCodeUrl = $google2fa->getQRCodeUrl($appName, $user->email, $secret);

            try {
                $renderer = new ImageRenderer(
                    new RendererStyle(220),
                    new SvgImageBackEnd()
                );
                $writer = new Writer($renderer);
                $svgContent = $writer->writeString($qrCodeUrl);
                $qrCodeSvg = 'data:image/svg+xml;base64,' . base64_encode($svgContent);
            } catch (\Exception $e) {
                $qrCodeSvg = null;
            }

            try {
                $currentOtp = $google2fa->getCurrentOtp($secret);
            } catch (\Exception $e) {
                $currentOtp = null;
            }
        }

        return response()->json([
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'enabled' => $enabled,
            'has_secret' => !empty($secret),
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'qr_code_svg' => $qrCodeSvg,
            'qr_code_image_url' => $qrCodeUrl ? 'https://quickchart.io/qr?text=' . urlencode($qrCodeUrl) . '&size=220' : null,
            'current_otp' => $currentOtp,
            'recovery_codes' => $user->two_factor_recovery_codes ?? [],
            'confirmed_at' => $user->two_factor_confirmed_at,
        ]);
    }

    /**
     * Generate or regenerate 2FA credentials & QR code for a user.
     */
    public function generateTwoFactor(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $google2fa = new Google2FA();

        $secret = $google2fa->generateSecretKey();
        $appName = config('app.name', 'goldenmark.money');
        if ($appName === 'Laravel') {
            $appName = 'goldenmark.money';
        }
        $qrCodeUrl = $google2fa->getQRCodeUrl($appName, $user->email, $secret);

        try {
            $renderer = new ImageRenderer(
                new RendererStyle(220),
                new SvgImageBackEnd()
            );
            $writer = new Writer($renderer);
            $svgContent = $writer->writeString($qrCodeUrl);
            $qrCodeSvg = 'data:image/svg+xml;base64,' . base64_encode($svgContent);
        } catch (\Exception $e) {
            $qrCodeSvg = null;
        }

        // Generate 8 backup recovery codes
        $recoveryCodes = collect(range(1, 8))->map(function () {
            return Str::random(5) . '-' . Str::random(5);
        })->toArray();

        $user->google2fa_secret = $secret;
        $user->two_factor_recovery_codes = $recoveryCodes;
        $user->two_factor_confirmed_at = now();
        $user->two_factor_verification = true;
        $user->save();

        try {
            $currentOtp = $google2fa->getCurrentOtp($secret);
        } catch (\Exception $e) {
            $currentOtp = null;
        }

        return response()->json([
            'message' => 'Two-factor authentication generated and activated successfully.',
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'enabled' => true,
            'has_secret' => true,
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'qr_code_svg' => $qrCodeSvg,
            'qr_code_image_url' => 'https://quickchart.io/qr?text=' . urlencode($qrCodeUrl) . '&size=220',
            'current_otp' => $currentOtp,
            'recovery_codes' => $recoveryCodes,
            'confirmed_at' => $user->two_factor_confirmed_at,
        ]);
    }

    /**
     * Disable 2FA for a user (Admin recovery).
     */
    public function disableTwoFactor($id)
    {
        $user = User::findOrFail($id);
        $user->google2fa_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_verification = false;
        $user->two_factor_recovery_codes = null;
        $user->save();

        return response()->json([
            'message' => 'Two-factor authentication has been disabled for this user.',
            'user_id' => $user->id,
            'enabled' => false,
            'has_secret' => false,
        ]);
    }
}

