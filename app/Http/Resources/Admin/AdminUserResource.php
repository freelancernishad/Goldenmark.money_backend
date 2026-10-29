<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'credit_balance' => (float) ($this->credit_balance ?? 0),
            'used_credits' => (float) ($this->used_credits ?? 0),
            'is_active' => $this->is_active,
            'is_blocked' => $this->is_blocked,
            'email_verified' => !is_null($this->email_verified_at),
            'has_2fa_enabled' => $this->hasTwoFactorEnabled(),
            'has_2fa_secret' => !empty($this->google2fa_secret),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'notes' => $this->notes,
            'profile_picture' => $this->profile_picture, // optional if photo relation exists
        ];
    }
}
