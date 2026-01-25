<?php

namespace App\Models;

use App\Models\Concerns\HasErrorTracking;
use App\Models\Concerns\LogsActivity;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebApp extends Model
{
    use HasFactory;
    use HasUuids;
    use LogsActivity;
    use HasErrorTracking;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CREATING = 'creating';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_DELETING = 'deleting';

    public const SSL_NONE = 'none';
    public const SSL_PENDING = 'pending';
    public const SSL_ACTIVE = 'active';
    public const SSL_FAILED = 'failed';

    public const WEB_SERVER_NGINX = 'nginx';
    public const WEB_SERVER_NGINX_APACHE = 'nginx_apache';

    // Application types
    public const APP_TYPE_PHP = 'php';
    public const APP_TYPE_NODEJS = 'nodejs';
    public const APP_TYPE_STATIC = 'static';

    // Package managers for Node.js
    public const PACKAGE_MANAGER_NPM = 'npm';
    public const PACKAGE_MANAGER_YARN = 'yarn';
    public const PACKAGE_MANAGER_PNPM = 'pnpm';

    protected $fillable = [
        'server_id',
        'team_id',
        'source_provider_id',
        'supervisor_program_id',
        'name',
        'domain',
        'aliases',
        'system_user',
        'public_path',
        'app_type',
        'web_server',
        'php_version',
        'node_version',
        'node_port',
        'package_manager',
        'start_command',
        'build_command',
        'node_processes',
        'proxy_routes',
        'pre_deploy_script',
        'post_deploy_script',
        'static_assets_path',
        'health_check_path',
        'cache_node_modules',
        'ssl_status',
        'ssl_expires_at',
        'status',
        'settings',
        'environment_variables',
        'repository',
        'branch',
        'deploy_script',
        'shared_files',
        'shared_directories',
        'auto_deploy',
        'webhook_secret',
        'deploy_private_key',
        'deploy_public_key',
        'error_message',
        'last_error',
        'last_error_at',
        'suggested_action'
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'settings' => 'array',
            'shared_files' => 'array',
            'shared_directories' => 'array',
            'ssl_expires_at' => 'datetime',
            'auto_deploy' => 'boolean',
            'environment_variables' => 'encrypted:array',
            'last_error_at' => 'datetime',
            // Node.js JSON fields
            'node_processes' => 'array',
            'proxy_routes' => 'array',
            'cache_node_modules' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WebApp $app) {
            if (empty($app->system_user)) {
                $app->system_user = $app->generateSystemUser();
            }
            if (empty($app->webhook_secret)) {
                $app->webhook_secret = Str::random(40);
            }
        });
    }

    public function generateSystemUser(): string
    {
        // All web apps run under the single 'sitekit' user
        return 'sitekit';
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function sourceProvider(): BelongsTo
    {
        return $this->belongsTo(SourceProvider::class);
    }

    public function supervisorProgram(): BelongsTo
    {
        return $this->belongsTo(SupervisorProgram::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class)->orderByDesc('created_at');
    }

    public function sslCertificates(): HasMany
    {
        return $this->hasMany(SslCertificate::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    public function activeSslCertificate(): ?SslCertificate
    {
        return $this->sslCertificates()
            ->where('status', SslCertificate::STATUS_ACTIVE)
            ->where('domain', $this->domain)
            ->first();
    }

    /**
     * Get the active deployment relationship for Filament.
     */
    public function activeDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)
            ->where('status', Deployment::STATUS_ACTIVE)
            ->latestOfMany();
    }

    /**
     * Get the active deployment model (for non-relationship use).
     */
    public function getActiveDeployment(): ?Deployment
    {
        return $this->activeDeployment()->first();
    }

    public function latestDeployment(): HasOne
    {
        return $this->hasOne(Deployment::class)->latestOfMany();
    }

    public function deploy(string $trigger = 'manual', ?string $commitHash = null): Deployment
    {
        $deployment = Deployment::create([
            'web_app_id' => $this->id,
            'team_id' => $this->team_id,
            'commit_hash' => $commitHash ?? 'pending',
            'commit_message' => 'Deployment triggered',
            'branch' => $this->branch ?? 'main',
            'trigger' => $trigger,
            'status' => Deployment::STATUS_PENDING,
        ]);

        // Use custom deploy script or default build script
        $buildScript = $this->deploy_script ?: $this->getDefaultBuildScript();

        $this->dispatchJob('deploy', [
            'deployment_id' => $deployment->id,
            'app_path' => $this->root_path,
            'username' => $this->system_user,
            'repository' => $this->repository,
            'branch' => $this->branch ?? 'main',
            'commit_hash' => $commitHash,
            'ssh_url' => $this->getGitSshUrl(),
            'deploy_key' => $this->deploy_private_key,
            'shared_files' => $this->shared_files ?? [],
            'shared_directories' => $this->shared_directories ?? [],
            'build_script' => $buildScript,
            'php_version' => $this->php_version,
            'env_content' => $this->getEnvFileContent(),
            // Node.js specific fields
            'app_type' => $this->app_type ?? self::APP_TYPE_PHP,
            'node_version' => $this->node_version,
            'package_manager' => $this->package_manager ?? self::PACKAGE_MANAGER_NPM,
            'cache_node_modules' => $this->cache_node_modules ?? true,
            'pre_deploy_script' => $this->pre_deploy_script,
            'post_deploy_script' => $this->post_deploy_script,
        ], 1);

        return $deployment;
    }

    /**
     * Get the default build script for deployments.
     * Returns appropriate script based on app type.
     */
    public function getDefaultBuildScript(): string
    {
        return match ($this->app_type) {
            self::APP_TYPE_NODEJS => $this->getNodeJsBuildScript(),
            self::APP_TYPE_STATIC => $this->getStaticBuildScript(),
            default => $this->getPhpBuildScript(),
        };
    }

    /**
     * Get the default build script for PHP applications.
     */
    protected function getPhpBuildScript(): string
    {
        return <<<'SCRIPT'
# Install PHP dependencies
if [ -f "composer.json" ]; then
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
fi

# Install Node dependencies and build assets
if [ -f "package.json" ]; then
    if [ -f "package-lock.json" ]; then
        npm ci
    else
        npm install
    fi

    # Build assets if build script exists
    if grep -q '"build"' package.json; then
        npm run build
    fi
fi

# Laravel-specific tasks
if [ -f "artisan" ]; then
    # Generate key if not set
    if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
        php artisan key:generate --force
    fi

    # Run migrations (optional - uncomment if needed)
    # php artisan migrate --force

    # Clear and cache config
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Set proper permissions
chmod -R 755 storage bootstrap/cache 2>/dev/null || true
SCRIPT;
    }

    /**
     * Get the default build script for Node.js applications.
     */
    protected function getNodeJsBuildScript(): string
    {
        $installCmd = $this->getInstallCommand();
        $buildCmd = $this->build_command ?: $this->getDefaultBuildCommand();

        return <<<SCRIPT
# Install Node.js dependencies
{$installCmd}

# Run build command
{$buildCmd}

# Run pre-deploy script if defined
if [ -n "\$PRE_DEPLOY_SCRIPT" ]; then
    eval "\$PRE_DEPLOY_SCRIPT"
fi
SCRIPT;
    }

    /**
     * Get the default build script for static applications.
     */
    protected function getStaticBuildScript(): string
    {
        $installCmd = $this->getInstallCommand();
        $buildCmd = $this->build_command ?: $this->getDefaultBuildCommand();

        return <<<SCRIPT
# Install dependencies if package.json exists
if [ -f "package.json" ]; then
    {$installCmd}

    # Build static assets
    {$buildCmd}
fi
SCRIPT;
    }

    public function rollbackTo(Deployment $deployment): Deployment
    {
        return $this->deploy('rollback', $deployment->commit_hash);
    }

    public function getRootPathAttribute(): string
    {
        // Structure: /home/sitekit/web/{domain}
        return "/home/sitekit/web/{$this->domain}";
    }

    public function getDocumentRootAttribute(): string
    {
        $publicPath = $this->public_path ?: '';
        return rtrim($this->root_path . '/current/' . $publicPath, '/');
    }

    public function getNginxConfigPathAttribute(): string
    {
        return "/etc/nginx/sites-available/{$this->domain}.conf";
    }

    public function getPhpPoolConfigPathAttribute(): string
    {
        return "/etc/php/{$this->php_version}/fpm/pool.d/{$this->id}.conf";
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasSSL(): bool
    {
        return $this->ssl_status === self::SSL_ACTIVE;
    }

    public function isPhp(): bool
    {
        return $this->app_type === self::APP_TYPE_PHP;
    }

    public function isNodeJs(): bool
    {
        return $this->app_type === self::APP_TYPE_NODEJS;
    }

    public function isStatic(): bool
    {
        return $this->app_type === self::APP_TYPE_STATIC;
    }

    /**
     * Get available Node.js versions.
     */
    public static function getNodeVersionOptions(): array
    {
        return [
            '24' => 'Node.js 24 (Latest)',
            '22' => 'Node.js 22 LTS',
            '20' => 'Node.js 20 LTS',
            '18' => 'Node.js 18 LTS',
        ];
    }

    /**
     * Get the default start command based on package manager.
     */
    public function getDefaultStartCommand(): string
    {
        return match ($this->package_manager) {
            self::PACKAGE_MANAGER_YARN => 'yarn start',
            self::PACKAGE_MANAGER_PNPM => 'pnpm start',
            default => 'npm start',
        };
    }

    /**
     * Get the default build command based on package manager.
     */
    public function getDefaultBuildCommand(): string
    {
        return match ($this->package_manager) {
            self::PACKAGE_MANAGER_YARN => 'yarn build',
            self::PACKAGE_MANAGER_PNPM => 'pnpm build',
            default => 'npm run build',
        };
    }

    /**
     * Get the install command based on package manager.
     */
    public function getInstallCommand(): string
    {
        return match ($this->package_manager) {
            self::PACKAGE_MANAGER_YARN => 'yarn install --frozen-lockfile',
            self::PACKAGE_MANAGER_PNPM => 'pnpm install --frozen-lockfile',
            default => 'npm ci',
        };
    }

    public function getEnvFileContent(): string
    {
        $envVars = $this->environment_variables;
        if (empty($envVars)) {
            return '';
        }

        if (is_string($envVars)) {
            $envVars = json_decode($envVars, true) ?? [];
        }

        $lines = ['# Generated by SiteKit - Do not edit directly'];

        foreach ($envVars as $key => $value) {
            if (preg_match('/[\s"\'#]/', $value)) {
                $value = '"' . addslashes($value) . '"';
            }
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines) . "\n";
    }

    public function getGitSshUrl(): ?string
    {
        if (!$this->repository) {
            return null;
        }

        // Convert HTTPS URL to SSH URL if needed
        // https://github.com/user/repo.git -> git@github.com:user/repo.git
        $repo = $this->repository;

        if (str_starts_with($repo, 'https://github.com/')) {
            $path = str_replace('https://github.com/', '', $repo);
            return 'git@github.com:' . $path;
        }

        if (str_starts_with($repo, 'https://gitlab.com/')) {
            $path = str_replace('https://gitlab.com/', '', $repo);
            return 'git@gitlab.com:' . $path;
        }

        if (str_starts_with($repo, 'https://bitbucket.org/')) {
            $path = str_replace('https://bitbucket.org/', '', $repo);
            return 'git@bitbucket.org:' . $path;
        }

        // Already SSH format or custom
        return $repo;
    }

    /**
     * Reload PHP-FPM for this web app's PHP version.
     */
    public function reloadPhpFpm(): AgentJob
    {
        return $this->dispatchJob('service_reload', [
            'service_type' => 'php',
            'version' => $this->php_version,
        ], 3);
    }

    /**
     * Restart PHP-FPM for this web app's PHP version.
     */
    public function restartPhpFpm(): AgentJob
    {
        return $this->dispatchJob('service_restart', [
            'service_type' => 'php',
            'version' => $this->php_version,
        ], 3);
    }

    /**
     * Get the PHP-FPM service for this web app.
     */
    public function getPhpService(): ?Service
    {
        return Service::where('server_id', $this->server_id)
            ->where('type', Service::TYPE_PHP)
            ->where('version', $this->php_version)
            ->first();
    }

    /**
     * Get log files for this web app.
     */
    public function getLogFiles(): array
    {
        return [
            "/var/log/nginx/{$this->domain}.access.log" => 'Nginx Access Log',
            "/var/log/nginx/{$this->domain}.error.log" => 'Nginx Error Log',
            "/var/log/php{$this->php_version}-fpm/{$this->id}.error.log" => 'PHP-FPM Error Log',
            "{$this->root_path}/shared/storage/logs/laravel.log" => 'Laravel Log',
        ];
    }

    /**
     * Dispatch a job to read log file contents (tail).
     */
    public function dispatchReadLog(string $filePath, int $lines = 100): AgentJob
    {
        return AgentJob::create([
            'server_id' => $this->server_id,
            'team_id' => $this->team_id,
            'type' => 'tail_log',
            'payload' => [
                'app_id' => $this->id,
                'path' => $filePath,
                'lines' => $lines,
            ],
        ]);
    }

    public function dispatchJob(string $type, array $payload = [], int $priority = 5): AgentJob
    {
        return AgentJob::create([
            'server_id' => $this->server_id,
            'team_id' => $this->team_id,
            'type' => $type,
            'payload' => array_merge(['app_id' => $this->id], $payload),
            'priority' => $priority,
        ]);
    }

    protected function getLoggableAttributes(): array
    {
        return ['name', 'domain', 'status', 'php_version', 'ssl_status'];
    }
}
