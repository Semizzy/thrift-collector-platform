<?php

namespace App\Models;

use App\Notifications\ApiPasswordResetNotification;
use Illuminate\Auth\MustVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmailContract
{
    use HasFactory, MustVerifyEmail, Notifiable;

    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token', 'transaction_pin_hash'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'platform_permissions' => 'array', 'transaction_pin_set_at' => 'datetime', 'transaction_pin_locked_until' => 'datetime', 'login_locked_until' => 'datetime'];
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PersonalAccessToken::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ApiPasswordResetNotification($token));
    }
}
