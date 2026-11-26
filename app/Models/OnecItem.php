<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An item from 1C (crm/item), loaded on demand by an admin (Companies → Load items from 1C). company_onec_id = the
 * client company the item was made for (e.g. labels printed for them); category_id is 1C's own number.
 */
class OnecItem extends Model
{
    protected $fillable = ['onec_id', 'code', 'name', 'full_name', 'short_name', 'mxik', 'barcode', 'unit', 'category_id', 'company_onec_id', 'description'];

    protected function casts(): array
    {
        return ['code' => 'integer', 'category_id' => 'integer'];
    }

    public function label(): string
    {
        return $this->code.' — '.$this->name;
    }
}
