<?php

namespace App\Models\Goldenmarkmoney;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $table = 'company_payments'; // Maps to company_payments

    protected $fillable = [
        'email_token',
        'unique_check_id',
        'company_id',
        'account_id',
        'payee_id',
        'pay_from',
        'pay_as',
        'amount',
        'status',
        'issue_date',
        'check_number',
        'invoice_number',
        'payee_id_account_number',
        'category_id',
        'memo',
        'remittance_info',
        'delivery_proof',
        'process_without',
        'signature_image',
        'signature_image_url',
        'company_name',
        'company_address',
        'company_logo_url',
        'bank_name',
        'bank_routing_number',
        'bank_account_number',
        'check_design_config',
        'website',
        'business_website',
    ];

    protected $casts = [
        'issue_date' => 'date:Y-m-d',
        'remittance_info' => 'array',
        'delivery_proof' => 'array',
        'process_without' => 'array',
        'check_design_config' => 'array',
    ];

    protected $appends = [
        'unique_check_id',
        'payee_name',
        'signature_image_url',
        'company_logo_url',
        'company_name',
        'is_charged',
        'website',
        'business_website',
    ];

    public function getIsChargedAttribute()
    {
        if (in_array(strtolower($this->status ?? ''), ['printed', 'sent', 'mailed', 'completed'])) {
            return true;
        }
        return $this->logs()
            ->where(function ($q) {
                $q->whereIn('note', [
                    'Check PDF printed / downloaded',
                    'E-check sent via email',
                    'Mail check sent',
                ])->orWhere('note', 'LIKE', '%E-check email sent%');
            })
            ->exists();
    }

    public static function generateEmailToken()
    {
        do {
            $token = bin2hex(random_bytes(16));
        } while (self::where('email_token', $token)->exists());

        return $token;
    }

    public static function generateUniqueCheckId()
    {
        do {
            $code = 'CHK-' . mt_rand(10000000, 99999999);
        } while (self::where('unique_check_id', $code)->exists());

        return $code;
    }

    public function getUniqueCheckIdAttribute($value)
    {
        if (!empty($value)) {
            return $value;
        }
        return 'CHK-' . str_pad((string)$this->id, 8, '0', STR_PAD_LEFT);
    }



    public function businessDetail()
    {
        return $this->belongsTo(BusinessDetail::class, 'company_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function payee()
    {
        return $this->belongsTo(Payee::class);
    }

    public function logs()
    {
        return $this->hasMany(Log::class, 'company_payment_id')->orderBy('id', 'desc');
    }

    public function comments()
    {
        return $this->hasMany(Comment::class, 'company_payment_id');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'company_payment_id');
    }

    public function receipts()
    {
        return $this->hasMany(Receipt::class, 'company_payment_id');
    }

    public function deliveryProofs()
    {
        return $this->hasMany(DeliveryProof::class, 'company_payment_id');
    }

    public function remittances()
    {
        return $this->hasMany(Remittance::class, 'company_payment_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    // Alias for standardized access
    public function business()
    {
        return $this->belongsTo(BusinessDetail::class, 'company_id');
    }

    // Bank comes from the account
    public function getBankAttribute()
    {
        return $this->account; // The Account model has fields like bank_name, routing_number, etc.
    }

    // Standardized payee name
    public function getPayeeNameAttribute()
    {
        return $this->payee ? $this->payee->payee_name : '';
    }

    // Active signature image path/url for DomPDF (Absolute Path)
    public function getSignatureImageAttribute()
    {
        // 1. Prefer payment's own saved raw column snapshot
        $rawPath = $this->getRawOriginal('signature_image');
        if (!empty($rawPath)) {
            if (filter_var($rawPath, FILTER_VALIDATE_URL)) return $rawPath;
            if (file_exists(storage_path('app/public/' . $rawPath))) return storage_path('app/public/' . $rawPath);
            if (file_exists(public_path($rawPath))) return public_path($rawPath);
            return storage_path('app/public/' . $rawPath);
        }

        // 2. Fallback to current account activeSignature
        $signature = $this->account ? $this->account->activeSignature : null;
        if ($signature && $signature->path) {
            if (filter_var($signature->path, FILTER_VALIDATE_URL)) {
                return $signature->path;
            }
            if (file_exists(storage_path('app/public/' . $signature->path))) {
                return storage_path('app/public/' . $signature->path);
            }
            if (file_exists(public_path($signature->path))) {
                return public_path($signature->path);
            }
            return storage_path('app/public/' . $signature->path);
        }
        return null;
    }

    // Active signature image URL for React Frontend (HTTP URL)
    public function getSignatureImageUrlAttribute()
    {
        // 0. Check if delivery_proof explicitly turned off signature
        $deliveryProof = $this->delivery_proof;
        if (is_string($deliveryProof)) {
            $deliveryProof = json_decode($deliveryProof, true);
        }
        if (is_array($deliveryProof)) {
            if (isset($deliveryProof['include_signature']) && ($deliveryProof['include_signature'] === false || $deliveryProof['include_signature'] === 'No' || $deliveryProof['include_signature'] === 0 || $deliveryProof['include_signature'] === '0')) {
                return null;
            }
        }

        // 1. Prefer payment's own saved raw column snapshot URL
        $rawUrl = $this->getRawOriginal('signature_image_url');
        if ($rawUrl === 'none' || $rawUrl === '0' || $rawUrl === 'false' || $rawUrl === '') {
            return null;
        }
        if (!empty($rawUrl)) {
            return $rawUrl;
        }

        $rawPath = $this->getRawOriginal('signature_image');
        if (!empty($rawPath)) {
            if (filter_var($rawPath, FILTER_VALIDATE_URL)) return $rawPath;
            return asset('storage/' . $rawPath);
        }

        // 2. Fallback to current account activeSignature
        if ($this->account) {
            $signature = $this->account->activeSignature;
            if ($signature) {
                return $signature->image_url;
            }
        }
        return null;
    }

    public function getCompanyLogoUrlAttribute()
    {
        return $this->getRawOriginal('company_logo_url') ?: null;
    }

    public function getWebsiteAttribute()
    {
        return $this->getRawOriginal('website') ?: ($this->getRawOriginal('business_website') ?: null);
    }

    public function getBusinessWebsiteAttribute()
    {
        return $this->getRawOriginal('business_website') ?: ($this->getRawOriginal('website') ?: null);
    }

    public function getCompanyNameAttribute()
    {
        $rawName = $this->getRawOriginal('company_name');
        if (!empty($rawName)) {
            return $rawName;
        }
        return $this->account ? ($this->account->account_holder_name ?? null) : null;
    }

    public function getBusinessDetailAttribute()
    {
        $rawName = $this->getRawOriginal('company_name') ?: ($this->account?->account_holder_name ?? null);
        $rawAddr = $this->getRawOriginal('company_address');

        return [
            'id' => $this->company_id,
            'company_name' => $rawName,
            'legal_business_name' => $rawName,
            'verification_photo_id' => null,
            'company_logo_url' => $this->getRawOriginal('company_logo_url') ?: null,
            'address_line1' => $rawAddr,
            'physical_address' => $rawAddr,
        ];
    }

    protected static function booted()
    {
        static::creating(function ($payment) {
            if (empty($payment->unique_check_id)) {
                $payment->unique_check_id = static::generateUniqueCheckId();
            }

            $account = $payment->account ?: $payment->account()->first();
            if ($account) {
                if (empty($payment->bank_routing_number)) {
                    $payment->bank_routing_number = $account->routing_number;
                }
                if (empty($payment->bank_account_number)) {
                    $payment->bank_account_number = $account->account_number;
                }
                if (empty($payment->bank_name)) {
                    $payment->bank_name = $account->bank_name ?: $account->institution_name;
                }
                if (empty($payment->company_name)) {
                    $payment->company_name = $account->account_holder_name;
                }
                if (empty($payment->company_logo_url)) {
                    $payment->company_logo_url = $account->company_logo_url;
                }
                if (empty($payment->website)) {
                    $payment->website = $account->website;
                }
                if (empty($payment->business_website)) {
                    $payment->business_website = $payment->website ?: $account->website;
                }
                if (empty($payment->company_address)) {
                    $accountAddrParts = array_filter([
                        $account->address_line1 ?? '',
                        $account->address_line2 ?? '',
                        $account->city ?? '',
                        isset($account->state) ? $account->state . " " . ($account->postal_code ?? '') : ($account->postal_code ?? ''),
                        $account->country ?? ''
                    ]);
                    $payment->company_address = !empty($accountAddrParts) ? implode(', ', $accountAddrParts) : null;
                }
            }

            if (empty($payment->check_design_config) && $account) {
                $activeDesign = $account->activeCheckDesign;
                if ($activeDesign) {
                    $payment->check_design_config = [
                        'id' => $activeDesign->id,
                        'name' => $activeDesign->name,
                        'customBgUrl' => $activeDesign->custom_bg_url,
                        'positions' => $activeDesign->positions,
                    ];
                }
            }
        });


        static::created(function ($payment) {
            $payment->logs()->create([
                'status' => 'Created', // or $payment->status if preferred
                'initiated_by' => auth()->id(),
                'note' => 'Payment created',
                'device_info' => request()->ip(),
            ]);
        });

        static::updated(function ($payment) {
            $changes = [];
            $logStatus = 'updated';

            foreach ($payment->getDirty() as $attribute => $newValue) {
                if ($attribute === 'updated_at' || $attribute === 'created_at') continue;

                $oldValue = $payment->getOriginal($attribute);

                if ($attribute === 'amount') {
                    $changes[] = "Amount is changed {$oldValue} To {$newValue}";
                } elseif ($attribute === 'payee_id') {
                    $oldPayee = \App\Models\Goldenmarkmoney\Payee::find($oldValue);
                    $newPayee = \App\Models\Goldenmarkmoney\Payee::find($newValue);
                    $oldName = $oldPayee ? $oldPayee->payee_name : '';
                    $newName = $newPayee ? $newPayee->payee_name : '';
                    if ($oldName || $newName) {
                        $changes[] = "Payee is changed {$oldName} to {$newName}";
                    } else {
                        $changes[] = "Payee changed";
                    }
                } elseif ($attribute === 'memo') {
                    if (empty($newValue) && !empty($oldValue)) {
                        $changes[] = "Note is removed";
                    } elseif (empty($oldValue) && !empty($newValue)) {
                        $changes[] = "Note is added";
                    } else {
                        $changes[] = "Note is changed";
                    }
                } elseif ($attribute === 'status') {
                    $logStatus = $newValue;
                    if ($newValue === 'Printed' || $newValue === 'Re-Printed') {
                        $changes[] = "Check paper {$payment->check_number} has been printed";
                    } else {
                        $changes[] = "Status is changed to {$newValue}";
                    }
                } elseif ($attribute === 'check_number') {
                    $changes[] = "Check number is changed {$oldValue} to {$newValue}";
                } else {
                    $attrName = ucwords(str_replace('_', ' ', $attribute));
                    if (!is_array($oldValue) && !is_array($newValue)) {
                        $changes[] = "{$attrName} is changed {$oldValue} to {$newValue}";
                    } else {
                        $changes[] = "{$attrName} is updated";
                    }
                }
            }

            $note = implode(', ', $changes);
            if (empty($note)) {
                $note = 'No changes';
            }

            $payment->logs()->create([
                'status' => $logStatus,
                'initiated_by' => auth()->id(),
                'note' => $note,
                'device_info' => request()->ip(),
            ]);
        });
    }
}
