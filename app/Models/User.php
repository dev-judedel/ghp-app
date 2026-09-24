<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

#[Fillable(['name', 'email', 'password', 'role', 'profile_photo_path', 'user_code', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, LogsActivity;

    /**
     * Prefix for the system-generated User Management code (ALSC-######).
     * See generateUniqueUserCode() below.
     */
    public const CODE_PREFIX = 'ALSC-';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * Generates a unique ALSC-###### code for a new user account. The
     * Administrator never types this in (there's no user_code field on
     * StoreUserRequest at all) — it's always system-assigned.
     */
    public static function generateUniqueUserCode(): string
    {
        return \App\Support\UniqueCodeGenerator::generate('users', 'user_code', self::CODE_PREFIX);
    }

    /**
     * Public URL for the uploaded profile photo, or null if the user hasn't
     * set one — the profile page falls back to the initials() avatar below.
     */
    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::get(
            fn () => $this->profile_photo_path
                ? Storage::disk('public')->url($this->profile_photo_path)
                : null
        );
    }

    /**
     * Up to two letters for the fallback avatar, e.g. "Juan Dela Cruz" -> "JD".
     */
    protected function initials(): Attribute
    {
        return Attribute::get(function () {
            $parts = collect(preg_split('/\s+/', trim($this->name)))->filter();

            return $parts->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode('');
        });
    }

    /**
     * Deliberately excludes 'password' — logging password changes (even as
     * a hash) has no audit value and is an unnecessary risk to carry in a
     * log table. A password change still shows up as an activity entry
     * (the row exists), just without the value itself.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logOnly(['name', 'email', 'role', 'profile_photo_path', 'is_active', 'user_code'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
