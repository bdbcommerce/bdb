<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A contact's confirmed orders older than the last OrderBook::KEEP, added up per month (orders, products, quoted sum). */
class OrderMonth extends Model
{
    protected $fillable = ['telegram_user_id', 'month', 'orders', 'items', 'quoted_sum'];

    protected function casts(): array
    {
        return ['items' => 'array', 'orders' => 'integer', 'quoted_sum' => 'integer'];
    }
}
