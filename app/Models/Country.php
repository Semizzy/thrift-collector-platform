<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Country extends Model
{
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['code', 'name', 'currency_code', 'minor_unit_digits', 'locale', 'timezone', 'is_enabled'];
    protected function casts(): array { return ['minor_unit_digits' => 'integer', 'is_enabled' => 'boolean']; }
}
