<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A partner's or dealer's phone from the catalog load (one row per phone). Everyone not listed is an end user.
 * Only a phone Telegram verified (shared with the contact button) counts: a typed phone could be anyone's.
 */
class CatalogClient extends Model
{
    protected $fillable = ['phone', 'price_group', 'company', 'inn'];

    /** The customer's price group: partner | dealer | end_user. */
    public static function priceGroupFor(?TelegramUser $user): string
    {
        if (! $user || ! $user->hasVerifiedPhone()) {
            return 'end_user';
        }

        $phone = TelegramUser::normalizePhone($user->phone);

        return ($phone ? static::where('phone', $phone)->value('price_group') : null) ?? 'end_user';
    }
}
