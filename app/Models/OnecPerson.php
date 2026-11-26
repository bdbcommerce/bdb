<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A private person from the 1C client list (no STIR), loaded with "Load companies from 1C"; matched to contacts by phone. */
class OnecPerson extends Model
{
    protected $table = 'onec_people';

    protected $fillable = ['onec_id', 'code', 'name', 'phone', 'address'];

    protected function casts(): array
    {
        return ['code' => 'integer'];
    }

    /** The 1C private client with this contact's phone (shown on the contact page; never sets prices). */
    public static function forContact(TelegramUser $user): ?self
    {
        $phone = TelegramUser::normalizePhone($user->phone);

        return $phone ? static::where('phone', $phone)->orderBy('id')->first() : null;
    }
}
