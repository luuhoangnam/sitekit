<?php

namespace App\Filament\Resources\WebAppResource\Pages;

use App\Filament\Resources\WebAppResource;
use App\Models\AgentJob;
use App\Models\SourceProvider;
use App\Models\WebApp;
use App\Services\ConfigGenerator\NginxConfigGenerator;
use App\Services\ConfigGenerator\PhpFpmConfigGenerator;
use App\Services\GitProviders\GitProviderFactory;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Infolists\Components\Actions as InfolistActions;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

class ViewWebApp extends ViewRecord
{
    protected static string $resource = WebAppResource::class;

    protected static string $view = 'filament.resources.web-app-resource.pages.view-web-app';

    public function getPollingInterval(): ?string
    {
        // Poll when app is in a transitional state
        if ($this->record && in_array($this->record->status, [
            WebApp::STATUS_CREATING,
            WebApp::STATUS_DELETING,
        ])) {
            return '3s';
        }

        if ($this->record && $this->record->ssl_status === WebApp::SSL_PENDING) {
            return '5s';
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // AI Help Actions
            Actions\ActionGroup::make([
                Actions\Action::make('ai_deployment_help')
                    ->label('Deployment Help')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(fn () => config('ai.enabled'))
                    ->extraAttributes(fn (WebApp $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("Help me set up deployment for my ' . e($record->name) . ' application. It\'s a Laravel/PHP app on ' . e($record->server?->name) . '. What deploy script should I use? What are the best practices for zero-downtime deployment?")',
                    ]),

                Actions\Action::make('ai_ssl_help')
                    ->label('SSL Troubleshoot')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->visible(fn (WebApp $record) => $record->ssl_status === WebApp::SSL_FAILED && config('ai.enabled'))
                    ->extraAttributes(fn (WebApp $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("My SSL certificate failed to issue for domain ' . e($record->domain) . '. Error: ' . e($record->sslCertificates()->where('status', 'failed')->latest()->first()?->error_message ?? 'Unknown') . '. How do I fix this? What DNS records do I need?")',
                    ]),

                Actions\Action::make('ai_performance')
                    ->label('Performance Tips')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->visible(fn (WebApp $record) => $record->isActive() && config('ai.enabled'))
                    ->extraAttributes(fn (WebApp $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("Optimize my ' . e($record->name) . ' Laravel application for performance. Current settings: PHP ' . e($record->php_version) . ', Web server: ' . e($record->web_server) . '. PHP settings: ' . e(json_encode($record->settings ?? [])) . '. Suggest PHP-FPM, OPcache, and Nginx optimizations.")',
                    ]),

                Actions\Action::make('ai_debug')
                    ->label('Debug Issues')
                    ->icon('heroicon-o-sparkles')
                    ->color('danger')
                    ->visible(fn () => config('ai.enabled'))
                    ->extraAttributes(fn (WebApp $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("My ' . e($record->name) . ' application at ' . e($record->domain) . ' is having issues. Help me debug common problems like 502/504 errors, white screen, or slow performance. What logs should I check?")',
                    ]),
            ])
                ->label('AI Help')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->button()
                ->visible(fn () => config('ai.enabled')),

            Actions\EditAction::make(),

            // Connect Repository - for setting up git deployment
            Actions\Action::make('connect_repository')
                ->label('Connect Repository')
                ->icon('heroicon-o-link')
                ->color('primary')
                ->visible(fn (WebApp $record) => $record->isActive() && !$record->repository)
                ->modalHeading('Connect Git Repository')
                ->modalDescription(new HtmlString(
                    '<div class="text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-3 mb-4">' .
                    '<strong>⚠️ Important:</strong> Deploying from Git will replace files in your app directory. ' .
                    'If you uploaded files via FTP/SFTP, make sure they are committed to your repository first, ' .
                    'or they will be overwritten. The <code>shared/</code> folder (storage, .env) is preserved.' .
                    '</div>'
                ))
                ->form([
                    Forms\Components\Select::make('source_provider_id')
                        ->label('Source Provider')
                        ->options(function () {
                            return SourceProvider::where('team_id', Filament::getTenant()?->id)
                                ->get()
                                ->mapWithKeys(fn ($provider) => [
                                    $provider->id => ucfirst($provider->provider) . ' - ' . $provider->provider_username,
                                ]);
                        })
                        ->required()
                        ->live()
                        ->placeholder('Select a connected provider')
                        ->helperText(function () {
                            $count = SourceProvider::where('team_id', Filament::getTenant()?->id)->count();
                            if ($count === 0) {
                                return new HtmlString(
                                    'No providers connected. <a href="' . route('filament.app.pages.source-providers', ['tenant' => Filament::getTenant()]) . '" class="text-primary-600 hover:underline">Connect GitHub, GitLab, or Bitbucket first →</a>'
                                );
                            }
                            return null;
                        }),

                    Forms\Components\Select::make('repository')
                        ->label('Repository')
                        ->options(function (Forms\Get $get) {
                            $providerId = $get('source_provider_id');
                            if (!$providerId) {
                                return [];
                            }

                            try {
                                $provider = SourceProvider::find($providerId);
                                if (!$provider) {
                                    return [];
                                }

                                $gitService = GitProviderFactory::make($provider);
                                $repos = $gitService->repositories();

                                return $repos->mapWithKeys(fn ($repo) => [
                                    $repo['full_name'] => $repo['full_name'] . ($repo['private'] ? ' 🔒' : ''),
                                ])->toArray();
                            } catch (\Exception $e) {
                                return [];
                            }
                        })
                        ->required()
                        ->searchable()
                        ->live()
                        ->visible(fn (Forms\Get $get) => filled($get('source_provider_id')))
                        ->placeholder('Select a repository')
                        ->helperText('Select the repository to deploy from'),

                    Forms\Components\Select::make('branch')
                        ->label('Branch')
                        ->options(function (Forms\Get $get) {
                            $providerId = $get('source_provider_id');
                            $repository = $get('repository');

                            if (!$providerId || !$repository) {
                                return [];
                            }

                            try {
                                $provider = SourceProvider::find($providerId);
                                if (!$provider) {
                                    return [];
                                }

                                $gitService = GitProviderFactory::make($provider);
                                $branches = $gitService->branches($repository);

                                return $branches->mapWithKeys(fn ($branch) => [$branch => $branch])->toArray();
                            } catch (\Exception $e) {
                                return ['main' => 'main', 'master' => 'master'];
                            }
                        })
                        ->required()
                        ->default('main')
                        ->visible(fn (Forms\Get $get) => filled($get('repository')))
                        ->helperText('Branch to deploy from'),

                    Forms\Components\Textarea::make('deploy_script')
                        ->label('Deploy Script')
                        ->placeholder("# Example Laravel deploy script\ncomposer install --no-interaction --prefer-dist --optimize-autoloader --no-dev\nnpm ci && npm run build\nphp artisan config:cache\nphp artisan route:cache\nphp artisan view:cache\nphp artisan migrate --force")
                        ->rows(8)
                        ->visible(fn (Forms\Get $get) => filled($get('repository')))
                        ->helperText('Commands to run after pulling code (composer install, npm build, etc.)'),

                    Forms\Components\Toggle::make('auto_deploy')
                        ->label('Auto Deploy')
                        ->helperText('Automatically deploy when you push to the selected branch')
                        ->visible(fn (Forms\Get $get) => filled($get('repository')))
                        ->default(false),
                ])
                ->action(function (WebApp $record, array $data) {
                    $record->update([
                        'source_provider_id' => $data['source_provider_id'],
                        'repository' => $data['repository'],
                        'branch' => $data['branch'],
                        'deploy_script' => $data['deploy_script'] ?? null,
                        'auto_deploy' => $data['auto_deploy'] ?? false,
                    ]);

                    // If auto_deploy is enabled, set up the webhook
                    if ($data['auto_deploy'] ?? false) {
                        try {
                            $provider = SourceProvider::find($data['source_provider_id']);
                            $gitService = GitProviderFactory::make($provider);
                            $webhookUrl = route('webhooks.deploy', $record);
                            $gitService->createWebhook($data['repository'], $webhookUrl, $record->webhook_secret);
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Webhook Setup Failed')
                                ->body('Repository connected, but webhook could not be created: ' . $e->getMessage())
                                ->warning()
                                ->send();
                        }
                    }

                    Notification::make()
                        ->title('Repository Connected')
                        ->body("Connected to {$data['repository']}. Click 'Deploy' to deploy your code.")
                        ->success()
                        ->send();
                }),

            // Disconnect Repository
            Actions\Action::make('disconnect_repository')
                ->label('Disconnect Repository')
                ->icon('heroicon-o-link-slash')
                ->color('danger')
                ->visible(fn (WebApp $record) => $record->isActive() && $record->repository)
                ->requiresConfirmation()
                ->modalHeading('Disconnect Repository')
                ->modalDescription('This will remove the git configuration. Your deployed files will remain on the server, but you won\'t be able to deploy from git until you reconnect.')
                ->action(function (WebApp $record) {
                    $record->update([
                        'source_provider_id' => null,
                        'repository' => null,
                        'branch' => 'main',
                        'deploy_script' => null,
                        'auto_deploy' => false,
                    ]);

                    Notification::make()
                        ->title('Repository Disconnected')
                        ->body('Git deployment has been disabled. You can still upload files via SFTP.')
                        ->success()
                        ->send();
                }),

            // Clear Cache - standalone button for FTP users
            Actions\Action::make('clear_cache')
                ->label('Clear Cache')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn (WebApp $record) => $record->isActive())
                ->requiresConfirmation()
                ->modalHeading('Clear Cache')
                ->modalDescription('This will clear the PHP cache so any files you uploaded via FTP/SFTP are served immediately.')
                ->modalSubmitActionLabel('Clear Cache')
                ->action(function (WebApp $record) {
                    $record->restartPhpFpm();

                    Notification::make()
                        ->title('Cache Cleared')
                        ->body("PHP {$record->php_version} cache cleared. Your updated files are now live.")
                        ->success()
                        ->send();
                }),

            Actions\ActionGroup::make([
                // SSL Actions
                Actions\Action::make('issue_ssl')
                    ->label('Issue SSL Certificate')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->visible(fn (WebApp $record) => in_array($record->ssl_status, [WebApp::SSL_NONE, WebApp::SSL_FAILED]) && $record->isActive())
                    ->requiresConfirmation()
                    ->modalHeading('Issue SSL Certificate')
                    ->modalDescription('This will request a free Let\'s Encrypt SSL certificate for your domain. Make sure your DNS is properly configured.')
                    ->action(function (WebApp $record) {
                        // Create or get SSL certificate record
                        $certificate = \App\Models\SslCertificate::firstOrCreate(
                            ['web_app_id' => $record->id, 'domain' => $record->domain],
                            ['type' => \App\Models\SslCertificate::TYPE_LETSENCRYPT, 'status' => \App\Models\SslCertificate::STATUS_PENDING]
                        );

                        $record->update(['ssl_status' => WebApp::SSL_PENDING]);
                        $certificate->dispatchIssue();

                        Notification::make()
                            ->title('SSL Certificate Requested')
                            ->body('Let\'s Encrypt certificate is being issued...')
                            ->info()
                            ->send();
                    }),

                Actions\Action::make('renew_ssl')
                    ->label('Renew SSL Certificate')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (WebApp $record) => $record->ssl_status === WebApp::SSL_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (WebApp $record) {
                        $certificate = $record->activeSslCertificate();
                        if (!$certificate) {
                            Notification::make()
                                ->title('No Certificate Found')
                                ->body('No active SSL certificate found to renew.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $record->update(['ssl_status' => WebApp::SSL_PENDING]);
                        $certificate->dispatchRenew();

                        Notification::make()
                            ->title('SSL Renewal Started')
                            ->body('Certificate renewal in progress...')
                            ->info()
                            ->send();
                    }),

                // Environment Sync
                Actions\Action::make('sync_env')
                    ->label('Sync Environment')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->action(function (WebApp $record) {
                        $record->dispatchJob('update_env_file', [
                            'app_path' => $record->root_path . '/current',
                            'content' => $record->getEnvFileContent(),
                        ]);

                        Notification::make()
                            ->title('Environment Sync Started')
                            ->body('.env file is being updated on the server.')
                            ->success()
                            ->send();
                    }),

                // Reload Configs
                Actions\Action::make('reload_configs')
                    ->label('Reload PHP & Nginx')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->requiresConfirmation()
                    ->action(function (WebApp $record) {
                        $nginxGen = new NginxConfigGenerator();
                        $phpGen = new PhpFpmConfigGenerator();

                        $record->dispatchJob('update_webapp_config', [
                            'app_id' => $record->id,
                            'domain' => $record->domain,
                            'username' => $record->system_user,
                            'php_version' => $record->php_version,
                            'nginx_config' => $record->hasSSL()
                                ? $nginxGen->generateSSL($record)
                                : $nginxGen->generate($record),
                            'fpm_config' => $phpGen->generate($record),
                        ]);

                        Notification::make()
                            ->title('Configuration Reload Started')
                            ->body('Nginx and PHP-FPM are being reloaded.')
                            ->success()
                            ->send();
                    }),

                // PHP-FPM specific actions
                Actions\Action::make('reload_php')
                    ->label('Reload PHP-FPM')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('info')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->action(function (WebApp $record) {
                        $record->reloadPhpFpm();

                        Notification::make()
                            ->title('PHP-FPM Reload Queued')
                            ->body("Reloading PHP {$record->php_version} FPM...")
                            ->info()
                            ->send();
                    }),

                Actions\Action::make('restart_php')
                    ->label('Restart PHP-FPM')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(fn (WebApp $record) => "This will restart PHP {$record->php_version} FPM. Active requests may be interrupted briefly.")
                    ->action(function (WebApp $record) {
                        $record->restartPhpFpm();

                        Notification::make()
                            ->title('PHP-FPM Restart Queued')
                            ->body("Restarting PHP {$record->php_version} FPM...")
                            ->warning()
                            ->send();
                    }),
            ])
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button(),

            // Deployment Action
            Actions\Action::make('deploy')
                ->label('Deploy')
                ->icon('heroicon-o-rocket-launch')
                ->color('primary')
                ->visible(fn (WebApp $record) => $record->isActive() && filled($record->repository))
                ->form([
                    Forms\Components\Select::make('branch')
                        ->label('Branch')
                        ->options(fn (WebApp $record) => [
                            $record->branch => $record->branch,
                        ])
                        ->default(fn (WebApp $record) => $record->branch)
                        ->required(),
                    Forms\Components\Textarea::make('custom_script')
                        ->label('Additional Commands (Optional)')
                        ->placeholder("# Commands to run after deployment\nphp artisan cache:clear")
                        ->rows(3),
                ])
                ->action(function (WebApp $record, array $data) {
                    // Get latest commit from repository
                    $commitHash = null;
                    $commitMessage = 'Manual deployment';

                    if ($record->sourceProvider) {
                        try {
                            $gitService = \App\Services\GitProviders\GitProviderFactory::make($record->sourceProvider);
                            $commit = $gitService->getLatestCommit($record->repository, $data['branch']);
                            if ($commit) {
                                $commitHash = $commit['sha'];
                                $commitMessage = $commit['message'] ?? 'Manual deployment';
                            }
                        } catch (\Exception $e) {
                            // Use timestamp as fallback
                        }
                    }

                    $commitHash = $commitHash ?? 'manual-' . now()->format('YmdHis');

                    // Create deployment record
                    $deployment = \App\Models\Deployment::create([
                        'web_app_id' => $record->id,
                        'team_id' => $record->team_id,
                        'user_id' => auth()->id(),
                        'source_provider_id' => $record->source_provider_id,
                        'repository' => $record->repository,
                        'branch' => $data['branch'],
                        'commit_hash' => $commitHash,
                        'commit_message' => \Illuminate\Support\Str::limit($commitMessage, 255),
                        'trigger' => \App\Models\Deployment::TRIGGER_MANUAL,
                    ]);

                    // Dispatch job
                    $deployment->dispatchJob();

                    Notification::make()
                        ->title('Deployment Started')
                        ->body("Deploying {$data['branch']} branch...")
                        ->info()
                        ->send();
                }),

            // Logs
            Actions\ActionGroup::make([
                Actions\Action::make('view_logs')
                    ->label('View Logs')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->form(function (WebApp $record) {
                        return [
                            Forms\Components\Select::make('log_file')
                                ->label('Log File')
                                ->options($record->getLogFiles())
                                ->default(array_key_first($record->getLogFiles()))
                                ->required(),
                            Forms\Components\Select::make('lines')
                                ->label('Number of Lines')
                                ->options([
                                    50 => 'Last 50 lines',
                                    100 => 'Last 100 lines',
                                    200 => 'Last 200 lines',
                                    500 => 'Last 500 lines',
                                ])
                                ->default(100)
                                ->required(),
                        ];
                    })
                    ->modalHeading(fn (WebApp $record) => "{$record->name} Logs")
                    ->modalDescription('Request to fetch the latest log entries from the server.')
                    ->action(function (array $data, WebApp $record) {
                        $record->dispatchReadLog($data['log_file'], $data['lines']);

                        Notification::make()
                            ->title('Log Request Queued')
                            ->body("Fetching last {$data['lines']} lines from " . basename($data['log_file']) . "...")
                            ->info()
                            ->send();
                    }),

                Actions\Action::make('quick_error_log')
                    ->label('View Error Log')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->action(function (WebApp $record) {
                        $record->dispatchReadLog("/var/log/nginx/{$record->domain}.error.log", 100);

                        Notification::make()
                            ->title('Error Log Request Queued')
                            ->body("Fetching last 100 lines from Nginx error log...")
                            ->warning()
                            ->send();
                    }),

                Actions\Action::make('view_laravel_log')
                    ->label('View Laravel Log')
                    ->icon('heroicon-o-code-bracket')
                    ->color('info')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->action(function (WebApp $record) {
                        $record->dispatchReadLog("{$record->root_path}/shared/storage/logs/laravel.log", 100);

                        Notification::make()
                            ->title('Laravel Log Request Queued')
                            ->body("Fetching last 100 lines from Laravel log...")
                            ->info()
                            ->send();
                    }),

                Actions\Action::make('clear_log')
                    ->label('Clear Log')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->form(function (WebApp $record) {
                        return [
                            Forms\Components\Select::make('log_file')
                                ->label('Log File to Clear')
                                ->options($record->getLogFiles())
                                ->required()
                                ->helperText('This will truncate the log file on the server'),
                        ];
                    })
                    ->modalHeading('Clear Log File')
                    ->modalDescription('Warning: This will permanently delete the log contents.')
                    ->requiresConfirmation()
                    ->action(function (array $data, WebApp $record) {
                        AgentJob::create([
                            'server_id' => $record->server_id,
                            'team_id' => $record->team_id,
                            'type' => 'clear_log',
                            'payload' => [
                                'app_id' => $record->id,
                                'file_path' => $data['log_file'],
                            ],
                        ]);

                        Notification::make()
                            ->title('Log Clear Queued')
                            ->body("Clearing " . basename($data['log_file']) . "...")
                            ->danger()
                            ->send();
                    }),
            ])
                ->label('Logs')
                ->icon('heroicon-o-document-text')
                ->button(),

            // Delete Action
            Actions\DeleteAction::make()
                ->before(function (WebApp $record) {
                    $record->update(['status' => WebApp::STATUS_DELETING]);
                    $record->dispatchJob('delete_webapp', [
                        'app_id' => $record->id,
                        'domain' => $record->domain,
                        'username' => $record->system_user,
                        'php_version' => $record->php_version,
                        'root_path' => $record->root_path,
                        'delete_files' => true,
                    ], priority: 1);
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Error Information')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->iconColor('danger')
                    ->schema([
                        TextEntry::make('last_error')
                            ->label('Error')
                            ->columnSpanFull()
                            ->color('danger'),
                        TextEntry::make('error_age')
                            ->label('Occurred')
                            ->placeholder('Unknown'),
                        TextEntry::make('suggested_action')
                            ->label('Suggested Action')
                            ->formatStateUsing(fn (WebApp $record) => $record->getSuggestedActionLabel())
                            ->badge()
                            ->color('warning')
                            ->placeholder('None'),
                        TextEntry::make('error_message')
                            ->label('Technical Details')
                            ->columnSpanFull()
                            ->visible(fn (WebApp $record) => $record->error_message !== null && $record->error_message !== $record->last_error),
                    ])
                    ->columns(2)
                    ->visible(fn (WebApp $record) => $record->hasError()),

                Section::make('Application Details')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('domain')
                            ->copyable()
                            ->url(fn (WebApp $record) => $record->hasSSL()
                                ? "https://{$record->domain}"
                                : "http://{$record->domain}")
                            ->openUrlInNewTab(),
                        TextEntry::make('server.name')
                            ->label('Server'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                WebApp::STATUS_PENDING => 'warning',
                                WebApp::STATUS_CREATING => 'info',
                                WebApp::STATUS_ACTIVE => 'success',
                                WebApp::STATUS_SUSPENDED => 'danger',
                                WebApp::STATUS_DELETING => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('ssl_status')
                            ->label('SSL Status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                WebApp::SSL_NONE => 'gray',
                                WebApp::SSL_PENDING => 'warning',
                                WebApp::SSL_ACTIVE => 'success',
                                WebApp::SSL_FAILED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('ssl_expires_at')
                            ->label('SSL Expires')
                            ->dateTime()
                            ->visible(fn (WebApp $record) => $record->ssl_status === WebApp::SSL_ACTIVE),
                    ])
                    ->columns(3),

                Section::make('SSL Error')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        TextEntry::make('ssl_error')
                            ->label('')
                            ->getStateUsing(function (WebApp $record) {
                                // Check certificate error first
                                $cert = $record->sslCertificates()
                                    ->where('status', \App\Models\SslCertificate::STATUS_FAILED)
                                    ->latest()
                                    ->first();
                                if ($cert && $cert->error_message) {
                                    return $cert->error_message;
                                }
                                // Fall back to webapp error_message if it's SSL related
                                if ($record->error_message && str_starts_with($record->error_message, 'SSL:')) {
                                    return substr($record->error_message, 5);
                                }
                                return 'SSL certificate issuance failed. Check your DNS settings and try again.';
                            })
                            ->color('danger'),
                        TextEntry::make('retry_ssl')
                            ->label('')
                            ->getStateUsing(fn () => 'You can retry by clicking Actions → Issue SSL Certificate')
                            ->color('gray'),
                    ])
                    ->visible(fn (WebApp $record) => $record->ssl_status === WebApp::SSL_FAILED)
                    ->collapsed(false),

                Section::make('Git Repository')
                    ->icon('heroicon-o-code-bracket')
                    ->description(fn (WebApp $record) => $record->repository
                        ? 'Deploy code from your git repository'
                        : 'Connect a repository to enable git deployments')
                    ->schema([
                        // Show when NO repository is connected
                        TextEntry::make('no_repo_message')
                            ->label('')
                            ->getStateUsing(fn () => 'No repository connected. Click "Connect Repository" above to set up git deployment, or continue using SFTP to upload files.')
                            ->columnSpanFull()
                            ->visible(fn (WebApp $record) => !$record->repository),

                        // Show when repository IS connected
                        TextEntry::make('sourceProvider.provider')
                            ->label('Provider')
                            ->badge()
                            ->formatStateUsing(fn ($state) => ucfirst($state))
                            ->color(fn ($state) => match ($state) {
                                'github' => 'gray',
                                'gitlab' => 'warning',
                                'bitbucket' => 'info',
                                default => 'gray',
                            })
                            ->visible(fn (WebApp $record) => $record->repository),
                        TextEntry::make('repository')
                            ->label('Repository')
                            ->copyable()
                            ->url(fn (WebApp $record) => match ($record->sourceProvider?->provider) {
                                'github' => "https://github.com/{$record->repository}",
                                'gitlab' => "https://gitlab.com/{$record->repository}",
                                'bitbucket' => "https://bitbucket.org/{$record->repository}",
                                default => null,
                            })
                            ->openUrlInNewTab()
                            ->visible(fn (WebApp $record) => $record->repository),
                        TextEntry::make('branch')
                            ->label('Branch')
                            ->badge()
                            ->color('info')
                            ->visible(fn (WebApp $record) => $record->repository),
                        TextEntry::make('auto_deploy')
                            ->label('Auto Deploy')
                            ->badge()
                            ->formatStateUsing(fn ($state) => $state ? 'Enabled' : 'Disabled')
                            ->color(fn ($state) => $state ? 'success' : 'gray')
                            ->visible(fn (WebApp $record) => $record->repository),
                        TextEntry::make('webhook_url')
                            ->label('Webhook URL')
                            ->getStateUsing(fn (WebApp $record) => route('webhooks.deploy', $record))
                            ->copyable()
                            ->columnSpanFull()
                            ->helperText('Add this URL to your repository\'s webhooks to trigger auto-deploy on push')
                            ->visible(fn (WebApp $record) => $record->repository && $record->auto_deploy),
                        TextEntry::make('deploy_script')
                            ->label('Deploy Script')
                            ->fontFamily('mono')
                            ->columnSpanFull()
                            ->placeholder('No deploy script configured')
                            ->visible(fn (WebApp $record) => $record->repository && $record->deploy_script),
                    ])
                    ->columns(2),

                Section::make('Deploy Key')
                    ->schema([
                        TextEntry::make('deploy_public_key')
                            ->label('Public Key')
                            ->copyable()
                            ->fontFamily('mono')
                            ->helperText('Add this key to your repository\'s deploy keys for read-only access.'),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (WebApp $record) => $record->deploy_public_key),

                Section::make('Configuration')
                    ->schema([
                        TextEntry::make('web_server')
                            ->label('Web Server')
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'nginx' => 'Nginx',
                                'nginx_apache' => 'Nginx + Apache',
                                default => $state,
                            }),
                        TextEntry::make('php_version')
                            ->label('PHP Version')
                            ->formatStateUsing(fn ($state) => "PHP {$state}"),
                        TextEntry::make('public_path')
                            ->label('Public Path')
                            ->placeholder('/'),
                        TextEntry::make('system_user')
                            ->label('System User')
                            ->copyable(),
                    ])
                    ->columns(4),

                Section::make('PHP Settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->schema([
                        TextEntry::make('settings')
                            ->label('')
                            ->formatStateUsing(function ($state) {
                                if (empty($state)) {
                                    return 'Using default settings';
                                }
                                // Handle case where state is JSON string instead of array
                                if (is_string($state)) {
                                    $state = json_decode($state, true) ?? [];
                                }
                                if (!is_array($state) || empty($state)) {
                                    return 'Using default settings';
                                }
                                $lines = [];
                                foreach ($state as $key => $value) {
                                    $lines[] = "{$key} = {$value}";
                                }
                                return implode("\n", $lines);
                            })
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Section::make('Environment Variables')
                    ->icon('heroicon-o-key')
                    ->description('These variables are written to the .env file on your server.')
                    ->schema([
                        TextEntry::make('environment_variables')
                            ->label('')
                            ->formatStateUsing(function ($state) {
                                if (empty($state)) {
                                    return 'No environment variables configured';
                                }
                                // Handle case where state is JSON string instead of array
                                if (is_string($state)) {
                                    $state = json_decode($state, true) ?? [];
                                }
                                if (!is_array($state) || empty($state)) {
                                    return 'No environment variables configured';
                                }
                                $lines = [];
                                foreach ($state as $key => $value) {
                                    // Mask sensitive values
                                    $maskedValue = in_array(strtoupper($key), ['PASSWORD', 'SECRET', 'KEY', 'TOKEN', 'API_KEY', 'APP_KEY', 'DB_PASSWORD'])
                                        || str_contains(strtoupper($key), 'PASSWORD')
                                        || str_contains(strtoupper($key), 'SECRET')
                                        ? '••••••••'
                                        : $value;
                                    $lines[] = "{$key}={$maskedValue}";
                                }
                                return implode("\n", $lines);
                            })
                            ->fontFamily('mono')
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn (WebApp $record) => !empty($record->environment_variables)),

                Section::make('SFTP / SSH Access')
                    ->icon('heroicon-o-server')
                    ->schema([
                        TextEntry::make('sftp_host')
                            ->label('Host')
                            ->getStateUsing(fn (WebApp $record) => $record->server->ip_address)
                            ->copyable(),
                        TextEntry::make('sftp_port')
                            ->label('Port')
                            ->getStateUsing(fn () => '22')
                            ->copyable(),
                        TextEntry::make('sftp_user')
                            ->label('Username')
                            ->getStateUsing(fn (WebApp $record) => $record->system_user)
                            ->copyable(),
                        TextEntry::make('sftp_path')
                            ->label('Path')
                            ->getStateUsing(fn (WebApp $record) => $record->root_path . '/current')
                            ->copyable(),
                    ])
                    ->columns(2)
                    ->description('Use these credentials to connect via SFTP or SSH.')
                    ->collapsible(),

                Section::make('Latest Deployment')
                    ->schema([
                        TextEntry::make('activeDeployment.status')
                            ->label('Status')
                            ->badge()
                            ->default('No deployments'),
                        TextEntry::make('activeDeployment.commit_hash')
                            ->label('Commit')
                            ->limit(8)
                            ->placeholder('-'),
                        TextEntry::make('activeDeployment.created_at')
                            ->label('Deployed')
                            ->since()
                            ->placeholder('-'),
                    ])
                    ->columns(3)
                    ->visible(fn (WebApp $record) => $record->repository),
            ]);
    }

    #[On('echo-private:server.{record.server_id},agent.job')]
    public function handleAgentJob(): void
    {
        $this->record->refresh();
    }
}
