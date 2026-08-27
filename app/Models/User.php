<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'show_global_exercises', 'show_extra_weight', 'prefill_suggested_values', 'weight_unit'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'show_global_exercises',
        'show_extra_weight',
        'prefill_suggested_values',
        'weight_unit',
        'profile_photo_path',
        'connection_token',
        'connection_token_expires_at',
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
            'show_global_exercises' => 'boolean',
            'show_extra_weight' => 'boolean',
            'prefill_suggested_values' => 'boolean',
            'weight_unit' => 'string',
            'deleted_at' => 'datetime',
            'connection_token_expires_at' => 'datetime',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($user) {
            // Soft delete all associated records when user is soft-deleted
            
            // Soft delete exercise aliases
            $user->exerciseAliases()->each(function ($alias) {
                $alias->delete();
            });
            
            // Soft delete user's exercises (not global exercises)
            $user->exercises()->each(function ($exercise) {
                $exercise->delete();
            });
            
            // Soft delete lift logs
            $user->liftLogs()->each(function ($liftLog) {
                $liftLog->delete();
            });
            
            // Soft delete other user-specific data
            $user->measurementTypes()->each(function ($measurementType) {
                $measurementType->delete();
            });
            
            $user->ingredients()->each(function ($ingredient) {
                $ingredient->delete();
            });
            
            $user->meals()->each(function ($meal) {
                $meal->delete();
            });
            
            $user->foodLogs()->each(function ($foodLog) {
                $foodLog->delete();
            });
            
            $user->bodyLogs()->each(function ($bodyLog) {
                $bodyLog->delete();
            });
        });
        
        static::restoring(function ($user) {
            // When restoring a user, also restore their associated data
            
            // Restore exercise aliases
            \App\Models\ExerciseAlias::onlyTrashed()
                ->where('user_id', $user->id)
                ->each(function ($alias) {
                    $alias->restore();
                });
            
            // Restore user's exercises
            \App\Models\Exercise::onlyTrashed()
                ->where('user_id', $user->id)
                ->each(function ($exercise) {
                    $exercise->restore();
                });
            
            // Restore lift logs
            \App\Models\LiftLog::onlyTrashed()
                ->where('user_id', $user->id)
                ->each(function ($liftLog) {
                    $liftLog->restore();
                });
            
            // Restore other user-specific data
            if (class_exists('\App\Models\MeasurementType')) {
                \App\Models\MeasurementType::onlyTrashed()
                    ->where('user_id', $user->id)
                    ->each(function ($measurementType) {
                        $measurementType->restore();
                    });
            }
            
            if (class_exists('\App\Models\Ingredient')) {
                \App\Models\Ingredient::onlyTrashed()
                    ->where('user_id', $user->id)
                    ->each(function ($ingredient) {
                        $ingredient->restore();
                    });
            }
            
            if (class_exists('\App\Models\Meal')) {
                \App\Models\Meal::onlyTrashed()
                    ->where('user_id', $user->id)
                    ->each(function ($meal) {
                        $meal->restore();
                    });
            }
            
            if (class_exists('\App\Models\FoodLog')) {
                \App\Models\FoodLog::onlyTrashed()
                    ->where('user_id', $user->id)
                    ->each(function ($foodLog) {
                        $foodLog->restore();
                    });
            }
            
            if (class_exists('\App\Models\BodyLog')) {
                \App\Models\BodyLog::onlyTrashed()
                    ->where('user_id', $user->id)
                    ->each(function ($bodyLog) {
                        $bodyLog->restore();
                    });
            }
        });
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasRole(string $role): bool
    {
        // Use preloaded roles if available to avoid N+1 queries
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains('name', $role);
        }
        
        return $this->roles()->where('name', $role)->exists();
    }

    public function shouldShowGlobalExercises(): bool
    {
        return $this->show_global_exercises ?? true;
    }

    public function shouldShowExtraWeight(): bool
    {
        return $this->show_extra_weight ?? false;
    }

    public function shouldPrefillSuggestedValues(): bool
    {
        return $this->prefill_suggested_values ?? true;
    }



    /**
     * @deprecated This method is deprecated and will be removed. The metrics-first logging flow has been removed.
     */
    public function shouldUseMetricsFirstLoggingFlow(): bool
    {
        return false; // Always return false as this feature is deprecated
    }

    public function exercises()
    {
        return $this->hasMany(Exercise::class);
    }

    public function exerciseAliases()
    {
        return $this->hasMany(ExerciseAlias::class);
    }




    public function measurementTypes()
    {
        return $this->hasMany(MeasurementType::class);
    }

    public function ingredients()
    {
        return $this->hasMany(Ingredient::class);
    }

    public function meals()
    {
        return $this->hasMany(Meal::class);
    }

    public function foodLogs()
    {
        return $this->hasMany(FoodLog::class);
    }

    public function bodyLogs()
    {
        return $this->hasMany(BodyLog::class);
    }

    public function liftLogs()
    {
        return $this->hasMany(LiftLog::class);
    }

    public function personalRecords()
    {
        return $this->hasMany(PersonalRecord::class);
    }

    public function readPersonalRecords()
    {
        return $this->belongsToMany(PersonalRecord::class, 'personal_record_reads')
            ->withPivot('read_at');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    // Follow relationships
    public function following()
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'following_id')
            ->withTimestamps();
    }

    public function followers()
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id')
            ->withTimestamps();
    }

    public function isFollowing(User $user): bool
    {
        return $this->following()->where('following_id', $user->id)->exists();
    }

    public function follow(User $user): void
    {
        if (!$this->isFollowing($user) && $this->id !== $user->id) {
            $this->following()->attach($user->id);
        }
    }

    public function unfollow(User $user): void
    {
        $this->following()->detach($user->id);
    }

    public function highFivePR(PersonalRecord $pr): void
    {
        PRHighFive::firstOrCreate([
            'user_id' => $this->id,
            'personal_record_id' => $pr->id,
        ]);
    }

    /**
     * Get the URL for the user's profile photo
     */
    public function getProfilePhotoUrlAttribute(): ?string
    {
        if ($this->profile_photo_path) {
            return asset('storage/' . $this->profile_photo_path);
        }
        
        return null;
    }

    /**
     * Generate a new connection token for this user
     */
    public function generateConnectionToken(): string
    {
        $token = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->update([
            'connection_token' => $token,
            'connection_token_expires_at' => now()->addHour(),
        ]);

        return $token;
    }

    /**
     * Get or generate a valid connection token
     */
    public function getValidConnectionToken(): string
    {
        if ($this->connection_token && $this->connection_token_expires_at && $this->connection_token_expires_at->isFuture()) {
            return $this->connection_token;
        }

        return $this->generateConnectionToken();
    }

    /**
     * Check if a connection token is valid
     */
    public function hasValidConnectionToken(): bool
    {
        return $this->connection_token
            && $this->connection_token_expires_at
            && $this->connection_token_expires_at->isFuture();
    }

    /**
     * Clear the connection token
     */
    public function clearConnectionToken(): void
    {
        $this->update([
            'connection_token' => null,
            'connection_token_expires_at' => null,
        ]);
    }

    /**
     * Find a user by their connection token
     */
    public static function findByConnectionToken(string $token): ?User
    {
        return static::where('connection_token', $token)
            ->where('connection_token_expires_at', '>', now())
            ->first();
    }
}

