<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'has_dashboard_access',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'is_admin' => 'boolean',
            'has_dashboard_access' => 'boolean',
        ];
    }

    /**
     * The employee Contact record linked to this login user,
     * auto-created by UserObserver on registration.
     */
    public function contact(): HasOne
    {
        return $this->hasOne(Contact::class);
    }

    /**
     * Per-app (and optionally per-section) permission rows for this user.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Resolve the effective access level ('none' | 'user' | 'admin') for a
     * given app and optional section. Falls back from a specific section to
     * the whole-app ('*') permission if no specific row exists.
     * Global admins (is_admin = true) always get 'admin' everywhere.
     */
    public function accessLevelFor(string $app, string $section = '*'): string
    {
        if ($this->is_admin) {
            return 'admin';
        }

        if ($section !== '*') {
            $specific = $this->permissions()
                ->where('app', $app)
                ->where('section', $section)
                ->value('access_level');

            if ($specific) {
                return $specific;
            }
        }

        return $this->permissions()
            ->where('app', $app)
            ->where('section', '*')
            ->value('access_level') ?? 'none';
    }

    public function canAccess(string $app, string $section = '*'): bool
    {
        return $this->accessLevelFor($app, $section) !== 'none';
    }

    public function isAdminOf(string $app, string $section = '*'): bool
    {
        return $this->accessLevelFor($app, $section) === 'admin';
    }
}