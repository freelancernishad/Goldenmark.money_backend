<?php

namespace App\Models\Goldenmarkmoney;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'account_holder_name',
        'account_nick_name',
        'account_number',
        'routing_number',
        'type',
        'balance',
        'plaid_item_id',
        'plaid_account_id',
        'mask',
        'is_tokenized',
        'verification_status',
        'official_name',
        'institution_name',
        'institution_logo',
        'bank_address_line1',
        'bank_city',
        'bank_state',
        'bank_postal_code',
        'email',
        'phone_number',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'company_logo_url',
        'website',
        'ach_auth_form',
        'next_check_starting_number',
    ];

    protected $casts = [
        'ach_auth_form' => 'array',
    ];

    /**
     * Ensure institution logo resolves to a crisp brand SVG/PNG asset if raw base64 or unlinked.
     */
    public function getInstitutionLogoAttribute($value)
    {
        if (!empty($value) && !str_starts_with($value, 'data:')) {
            return $value;
        }

        $bankName = $this->institution_name;
        if (!empty($bankName)) {
            $brandLogo = \App\Services\Goldenmarkmoney\PlaidService::resolveFullBankLogo($bankName, null, null, null);
            if (!empty($brandLogo) && !str_starts_with($brandLogo, 'data:')) {
                return $brandLogo;
            }
        }

        return $value;
    }

    public function company()
    {
        return $this->belongsTo(BusinessDetail::class, 'company_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
    public function plaidItem()
    {
        return $this->belongsTo(PlaidItem::class, 'plaid_item_id');
    }

    public function signatures()
    {
        return $this->hasMany(AccountSignature::class);
    }

    public function activeSignature()
    {
        return $this->hasOne(AccountSignature::class)->where('is_primary', true);
    }

    public function checkDesigns()
    {
        return $this->hasMany(CheckDesign::class);
    }

    public function activeCheckDesign()
    {
        return $this->hasOne(CheckDesign::class)->where('is_active', true);
    }
}
