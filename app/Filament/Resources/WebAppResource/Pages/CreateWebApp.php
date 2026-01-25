<?php

namespace App\Filament\Resources\WebAppResource\Pages;

use App\Filament\Concerns\RequiresActiveServer;
use App\Filament\Resources\WebAppResource;
use App\Models\Server;
use App\Models\WebApp;
use App\Services\ConfigGenerator\ApacheConfigGenerator;
use App\Services\ConfigGenerator\NginxConfigGenerator;
use App\Services\ConfigGenerator\NodeNginxConfigGenerator;
use App\Services\ConfigGenerator\PhpFpmConfigGenerator;
use App\Services\DeployKeyGenerator;
use App\Services\PortAllocationService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateWebApp extends CreateRecord
{
    use RequiresActiveServer;

    protected static string $resource = WebAppResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['team_id'] = Filament::getTenant()->id;

        // Generate deploy key pair for Git operations
        $keyGenerator = new DeployKeyGenerator();
        $keyPair = $keyGenerator->generate($data['domain'] ?? 'webapp');

        $data['deploy_private_key'] = $keyPair['private_key'];
        $data['deploy_public_key'] = $keyPair['public_key'];

        // Allocate port for Node.js applications
        if (($data['app_type'] ?? WebApp::APP_TYPE_PHP) === WebApp::APP_TYPE_NODEJS) {
            $portService = app(PortAllocationService::class);
            $server = $this->getActiveServer();
            $data['node_port'] = $portService->allocate($server);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var WebApp $app */
        $app = $this->record;

        // Branch by app_type
        match ($app->app_type) {
            WebApp::APP_TYPE_NODEJS => $this->createNodeJsApp($app),
            WebApp::APP_TYPE_STATIC => $this->createStaticApp($app),
            default => $this->createPhpApp($app),
        };

        // Update status to creating
        $app->update(['status' => WebApp::STATUS_CREATING]);

        Notification::make()
            ->title('Web App Creation Started')
            ->body("Setting up {$app->domain} on your server...")
            ->info()
            ->send();
    }

    /**
     * Create a PHP web application.
     */
    protected function createPhpApp(WebApp $app): void
    {
        $nginxGen = new NginxConfigGenerator();
        $phpGen = new PhpFpmConfigGenerator();

        $nginxConfig = $nginxGen->generate($app);
        $phpPoolConfig = $phpGen->generate($app);

        // Dispatch job to create web app on server
        $app->dispatchJob('create_webapp', [
            'app_id' => $app->id,
            'domain' => $app->domain,
            'aliases' => $app->aliases ?? [],
            'username' => $app->system_user,
            'root_path' => $app->root_path,
            'public_path' => $app->public_path ?? 'public',
            'php_version' => $app->php_version,
            'app_type' => WebApp::APP_TYPE_PHP,
            'nginx_config' => $nginxConfig,
            'fpm_config' => $phpPoolConfig,
            'deploy_public_key' => $app->deploy_public_key,
        ], priority: 1);

        // If using hybrid mode (nginx_apache), also create Apache vhost
        if ($app->web_server === WebApp::WEB_SERVER_NGINX_APACHE) {
            $apacheGen = new ApacheConfigGenerator();
            $app->dispatchJob('create_apache_vhost', [
                'app_id' => $app->id,
                'domain' => $app->domain,
                'config' => $apacheGen->generate($app),
            ], priority: 2);
        }
    }

    /**
     * Create a Node.js web application.
     */
    protected function createNodeJsApp(WebApp $app): void
    {
        $nginxGen = new NodeNginxConfigGenerator();
        $nginxConfig = $nginxGen->generate($app);

        // Build supervisor program config
        $supervisorConfig = $this->buildSupervisorConfig($app);

        // Dispatch job to create Node.js web app on server
        $app->dispatchJob('create_webapp', [
            'app_id' => $app->id,
            'domain' => $app->domain,
            'aliases' => $app->aliases ?? [],
            'username' => $app->system_user,
            'root_path' => $app->root_path,
            'public_path' => $app->public_path ?? '',
            'app_type' => WebApp::APP_TYPE_NODEJS,
            'nginx_config' => $nginxConfig,
            'deploy_public_key' => $app->deploy_public_key,
            // Node.js specific fields
            'node_version' => $app->node_version,
            'node_port' => $app->node_port,
            'package_manager' => $app->package_manager,
            'start_command' => $app->start_command ?? $app->getDefaultStartCommand(),
            'build_command' => $app->build_command ?? $app->getDefaultBuildCommand(),
            'supervisor_config' => $supervisorConfig,
            'node_processes' => $app->node_processes,
            'pre_deploy_script' => $app->pre_deploy_script,
            'post_deploy_script' => $app->post_deploy_script,
        ], priority: 1);
    }

    /**
     * Create a static web application.
     */
    protected function createStaticApp(WebApp $app): void
    {
        $nginxGen = new NginxConfigGenerator();
        // Static apps use standard Nginx config without PHP
        $nginxConfig = $this->generateStaticNginxConfig($app);

        // Dispatch job to create static web app on server
        $app->dispatchJob('create_webapp', [
            'app_id' => $app->id,
            'domain' => $app->domain,
            'aliases' => $app->aliases ?? [],
            'username' => $app->system_user,
            'root_path' => $app->root_path,
            'public_path' => $app->public_path ?? 'dist',
            'app_type' => WebApp::APP_TYPE_STATIC,
            'nginx_config' => $nginxConfig,
            'deploy_public_key' => $app->deploy_public_key,
            'build_command' => $app->build_command ?? $app->getDefaultBuildCommand(),
        ], priority: 1);
    }

    /**
     * Build supervisor program configuration for Node.js app.
     */
    protected function buildSupervisorConfig(WebApp $app): string
    {
        $programName = "nodejs-{$app->id}";
        $startCommand = $app->start_command ?? $app->getDefaultStartCommand();
        $directory = "{$app->root_path}/current";
        $user = $app->system_user;
        $logFile = "{$app->root_path}/logs/node.log";
        $port = $app->node_port;
        $nodeVersion = $app->node_version ?? '22';

        // Build environment variables
        $envVars = [
            "NODE_ENV=production",
            "PORT={$port}",
            "PATH=/home/{$user}/.nvm/versions/node/v{$nodeVersion}.0.0/bin:/usr/local/bin:/usr/bin:/bin",
        ];
        $environment = implode(',', $envVars);

        return <<<SUPERVISOR
[program:{$programName}]
command={$startCommand}
directory={$directory}
user={$user}
autostart=true
autorestart=true
startsecs=10
startretries=3
stopwaitsecs=30
stopsignal=SIGTERM
stdout_logfile={$logFile}
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3
stderr_logfile={$logFile}
stderr_logfile_maxbytes=10MB
stderr_logfile_backups=3
environment={$environment}
SUPERVISOR;
    }

    /**
     * Generate Nginx config for static sites.
     */
    protected function generateStaticNginxConfig(WebApp $app): string
    {
        $domains = collect([$app->domain, ...($app->aliases ?? [])])->implode(' ');
        $documentRoot = $app->document_root;

        return <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$domains};
    root {$documentRoot};

    index index.html index.htm;

    charset utf-8;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_proxied any;
    gzip_comp_level 6;
    gzip_types text/plain text/css text/xml application/json application/javascript application/rss+xml application/atom+xml image/svg+xml;

    location / {
        try_files \$uri \$uri/ /index.html;
    }

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
    protected function getActiveServer(): ?Server
    {
        return Server::query()
            ->where('team_id', Filament::getTenant()?->id)
            ->where('status', 'active')
            ->first();
    }
}
