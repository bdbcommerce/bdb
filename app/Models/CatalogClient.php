<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A partner's or dealer's phone from the catalog load (one row per phone). Everyone not listed is an end user.
 * Only a phone Telegram verified (shared with the contact button) counts: a typed phone could be anyone's.
 * A company a manager linked to the contact (Companies → price type) counts too; the higher group wins.
 */
class CatalogClient extends Model
{
    /** Higher = sees more prices. */
    public const RANK = ['end_user' => 0, 'dealer' => 1, 'partner' => 2];

    protected $fillable = ['phone', 'price_group', 'company', 'inn'];

    /** The customer's price group: partner | dealer | end_user. */
    public static function priceGroupFor(?TelegramUser $user): string
    {
        if (! $user) {
            return 'end_user';
        }
        $groups = ['end_user'];
        if ($user->hasVerifiedPhone() && ($phone = TelegramUser::normalizePhone($user->phone))) {
            $groups[] = static::where('phone', $phone)->value('price_group');
        }
        // Companies a manager linked or confirmed (an INN the customer typed never unlocks prices by itself).
        if ($user->exists) {
            array_push($groups, ...CompanyLink::where('telegram_user_id', $user->id)->where('linked_by', 'manager')
                ->join('clients', 'clients.id', '=', 'company_links.client_id')->pluck('clients.price_type')->all());
        }

        return collect($groups)->filter(fn ($g) => isset(self::RANK[$g]))->sortByDesc(fn ($g) => self::RANK[$g])->first();
    }
}
