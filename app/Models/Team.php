<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Notifications\Notifiable;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<\Database\Factories\TeamFactory> */
    use HasFactory;
    use HasUuids;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
        'slack_webhook_url',
        'discord_webhook_url',
        'notification_settings',
        'backup_storage_driver',
        'backup_s3_endpoint',
        'backup_s3_region',
        'backup_s3_bucket',
        'backup_s3_key',
        'backup_s3_secret',
        'metrics_interval_seconds',
        'metrics_retention_days',
        // AI settings
        'ai_enabled',
        'ai_provider',
        'ai_openai_key',
        'ai_anthropic_key',
        'ai_gemini_key',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'notification_settings' => 'array',
            'backup_s3_key' => 'encrypted',
            'backup_s3_secret' => 'encrypted',
            'metrics_interval_seconds' => 'integer',
            'metrics_retention_days' => 'integer',
            // AI settings
            'ai_enabled' => 'boolean',
            'ai_openai_key' => 'encrypted',
            'ai_anthropic_key' => 'encrypted',
            'ai_gemini_key' => 'encrypted',
        ];
    }

    public function hasCloudBackupStorage(): bool
    {
        return in_array($this->backup_storage_driver, ['s3', 'r2'])
            && $this->backup_s3_bucket
            && $this->backup_s3_key
            && $this->backup_s3_secret;
    }

    public function getBackupDisk(): ?\Illuminate\Contracts\Filesystem\Filesystem
    {
        if (!$this->hasCloudBackupStorage()) {
            return null;
        }

        $config = [
            'driver' => 's3',
            'key' => $this->backup_s3_key,
            'secret' => $this->backup_s3_secret,
            'region' => $this->backup_s3_region ?? 'auto',
            'bucket' => $this->backup_s3_bucket,
            'throw' => true,
        ];

        // Add endpoint for R2 or custom S3-compatible storage
        if ($this->backup_s3_endpoint) {
            $config['endpoint'] = $this->backup_s3_endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        return \Illuminate\Support\Facades\Storage::build($config);
    }

    public function routeNotificationForSlack(): ?string
    {
        return $this->slack_webhook_url;
    }

    public function routeNotificationForDiscord(): ?string
    {
        return $this->discord_webhook_url;
    }

    public function shouldNotifyVia(string $channel): bool
    {
        $settings = $this->notification_settings ?? [];

        return match ($channel) {
            'slack' => !empty($this->slack_webhook_url) && ($settings['slack_enabled'] ?? true),
            'discord' => !empty($this->discord_webhook_url) && ($settings['discord_enabled'] ?? true),
            'mail' => $settings['email_enabled'] ?? true,
            default => true,
        };
    }

    public function sourceProviders(): HasMany
    {
        return $this->hasMany(SourceProvider::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function webApps(): HasMany
    {
        return $this->hasMany(WebApp::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    public function sshKeys(): HasMany
    {
        return $this->hasMany(SshKey::class);
    }

    // public function services():HasManyThrough
    // {
    //     return $this->hasManyThrough(Service::class, Server::class);
    // }
    public function services(): HasManyThrough
    {
        return $this->hasManyThrough(
            Service::class,
            Server::class,
            'team_id',   // Foreign key on servers table
            'server_id', // Foreign key on services table
            'id',        // Local key on teams table
            'id'         // Local key on servers table
        );
    }
    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }
    public function supervisorPrograms(): HasMany
    {
        return $this->hasMany(SupervisorProgram::class);
    }
    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class);
    }
    public function healthMonitors(): HasMany
    {
        return $this->hasMany(HealthMonitor::class);
    }
}