<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresServerForNavigation;
use App\Filament\Resources\WebAppResource\Pages;
use App\Filament\Resources\WebAppResource\RelationManagers;
use App\Models\WebApp;
use App\Services\ConfigGenerator\NodeNginxConfigGenerator;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class WebAppResource extends Resource
{
    use RequiresServerForNavigation;

    protected static ?string $model = WebApp::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Applications';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Web Apps';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('team_id', Filament::getTenant()?->id)
            ->where('status', WebApp::STATUS_ACTIVE)
            ->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Application Details')
                    ->description('Configure your web application\'s domain and server. Make sure your DNS points to the server before issuing SSL.')
                    ->icon('heroicon-o-globe-alt')
                    ->schema([
                        
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('My Laravel App')
                            ->helperText('A friendly name for this application'),
                        Forms\Components\TextInput::make('domain')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->regex('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i')
                            ->placeholder('app.example.com')
                            ->helperText('Primary domain - ensure DNS A record points to server IP'),
                        Forms\Components\TagsInput::make('aliases')
                            ->placeholder('Add alias domain')
                            ->helperText('Additional domains (e.g., www.example.com). Press Enter after each.'),
                        Forms\Components\Select::make('server_id')
                            ->relationship('server', 'name', fn (Builder $query) =>
                                $query->where('team_id', Filament::getTenant()?->id)
                                    ->where('status', 'active'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->helperText('Only active servers are shown'),
                        Forms\Components\Select::make('app_type')
                            ->label('Application Type')
                            ->options([
                                WebApp::APP_TYPE_PHP => 'PHP (Laravel, WordPress, etc.)',
                                WebApp::APP_TYPE_NODEJS => 'Node.js (Next.js, NestJS, etc.)',
                                WebApp::APP_TYPE_STATIC => 'Static (HTML, SPA, etc.)',
                            ])
                            ->default(WebApp::APP_TYPE_PHP)
                            ->required()
                            ->live()
                            ->helperText('Choose the runtime for your application'),
                    ])
                    ->columns(2),

                // PHP Configuration Section
                Forms\Components\Section::make('PHP Configuration')
                    ->description('Choose your web server stack and PHP version. These settings apply immediately after saving.')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->visible(fn (Get $get) => $get('app_type') === WebApp::APP_TYPE_PHP)
                    ->headerActions([
                        Forms\Components\Actions\Action::make('ai_which_stack')
                            ->label('Which stack?')
                            ->icon('heroicon-m-sparkles')
                            ->color('primary')
                            ->size('sm')
                            ->visible(fn () => config('ai.enabled'))
                            ->extraAttributes([
                                'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat(' . json_encode("Should I use Nginx only or Nginx + Apache for my Laravel application? When would I need Apache and .htaccess support? What are the performance implications?") . ')',
                            ]),
                        Forms\Components\Actions\Action::make('ai_php_version')
                            ->label('PHP version?')
                            ->icon('heroicon-m-sparkles')
                            ->color('gray')
                            ->size('sm')
                            ->visible(fn () => config('ai.enabled'))
                            ->extraAttributes([
                                'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat(' . json_encode("Which PHP version should I use for my Laravel application? Compare PHP 8.1, 8.2, 8.3, 8.4, and 8.5. What are the new features and performance improvements in each?") . ')',
                            ]),
                    ])
                    ->schema([
                        Forms\Components\Select::make('web_server')
                            ->options([
                                WebApp::WEB_SERVER_NGINX => 'Nginx (Pure) - Best performance',
                                WebApp::WEB_SERVER_NGINX_APACHE => 'Nginx + Apache - .htaccess support',
                            ])
                            ->default(WebApp::WEB_SERVER_NGINX)
                            ->helperText('Nginx+Apache required for WordPress or apps using .htaccess'),
                        Forms\Components\Select::make('php_version')
                            ->options([
                                '8.5' => 'PHP 8.5 (Latest)',
                                '8.4' => 'PHP 8.4',
                                '8.3' => 'PHP 8.3 (LTS)',
                                '8.2' => 'PHP 8.2',
                                '8.1' => 'PHP 8.1',
                            ])
                            ->default('8.5')
                            ->helperText('Select the PHP version your application requires'),
                        Forms\Components\TextInput::make('public_path')
                            ->default('public')
                            ->placeholder('public')
                            ->helperText('Laravel: "public" | WordPress: leave empty'),
                    ])
                    ->columns(3),

                // Node.js Configuration Section
                Forms\Components\Section::make('Node.js Configuration')
                    ->description('Configure your Node.js application runtime and build settings.')
                    ->icon('heroicon-o-code-bracket')
                    ->visible(fn (Get $get) => $get('app_type') === WebApp::APP_TYPE_NODEJS)
                    ->headerActions([
                        Forms\Components\Actions\Action::make('ai_nodejs_framework')
                            ->label('Which framework?')
                            ->icon('heroicon-m-sparkles')
                            ->color('primary')
                            ->size('sm')
                            ->visible(fn () => config('ai.enabled'))
                            ->extraAttributes([
                                'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat(' . json_encode("Help me choose a Node.js framework for my project. Compare Next.js, Nuxt.js, NestJS, Express, Remix, and Astro. What are the use cases and trade-offs for each?") . ')',
                            ]),
                    ])
                    ->schema([
                        Forms\Components\Select::make('node_version')
                            ->label('Node.js Version')
                            ->options(WebApp::getNodeVersionOptions())
                            ->default('22')
                            ->required()
                            ->helperText('LTS versions recommended for production'),
                        Forms\Components\Select::make('package_manager')
                            ->options([
                                WebApp::PACKAGE_MANAGER_NPM => 'npm (Default)',
                                WebApp::PACKAGE_MANAGER_YARN => 'Yarn',
                                WebApp::PACKAGE_MANAGER_PNPM => 'pnpm (Fastest)',
                            ])
                            ->default(WebApp::PACKAGE_MANAGER_NPM)
                            ->helperText('Choose based on your project lockfile'),
                        Forms\Components\Select::make('static_assets_path')
                            ->label('Framework / Static Assets')
                            ->options(NodeNginxConfigGenerator::getFrameworkOptions())
                            ->placeholder('Select framework or custom')
                            ->helperText('Sets static asset caching and default commands')
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state && $config = NodeNginxConfigGenerator::getFrameworkConfig($state)) {
                                    if ($config['static_path']) {
                                        $set('static_assets_path', $config['static_path']);
                                    }
                                    if ($config['health_check']) {
                                        $set('health_check_path', $config['health_check']);
                                    }
                                    if ($config['start_command']) {
                                        $set('start_command', $config['start_command']);
                                    }
                                    if ($config['build_command']) {
                                        $set('build_command', $config['build_command']);
                                    }
                                }
                            }),
                        Forms\Components\TextInput::make('start_command')
                            ->label('Start Command')
                            ->placeholder('npm start')
                            ->helperText('Command to start your application'),
                        Forms\Components\TextInput::make('build_command')
                            ->label('Build Command')
                            ->placeholder('npm run build')
                            ->helperText('Command to build your application'),
                        Forms\Components\TextInput::make('health_check_path')
                            ->label('Health Check Path')
                            ->placeholder('/api/health')
                            ->helperText('Endpoint for health monitoring'),
                    ])
                    ->columns(3),

                // Static Site Configuration
                Forms\Components\Section::make('Static Site Configuration')
                    ->description('Configure your static site build settings.')
                    ->icon('heroicon-o-document')
                    ->visible(fn (Get $get) => $get('app_type') === WebApp::APP_TYPE_STATIC)
                    ->schema([
                        Forms\Components\Select::make('package_manager')
                            ->options([
                                WebApp::PACKAGE_MANAGER_NPM => 'npm',
                                WebApp::PACKAGE_MANAGER_YARN => 'Yarn',
                                WebApp::PACKAGE_MANAGER_PNPM => 'pnpm',
                            ])
                            ->default(WebApp::PACKAGE_MANAGER_NPM)
                            ->helperText('Used for building assets'),
                        Forms\Components\TextInput::make('build_command')
                            ->label('Build Command')
                            ->placeholder('npm run build')
                            ->helperText('Command to build your static site'),
                        Forms\Components\TextInput::make('public_path')
                            ->default('dist')
                            ->placeholder('dist')
                            ->helperText('Output directory: Vite/Vue: "dist" | Next.js: "out"'),
                    ])
                    ->columns(3),

                // Deploy Hooks Section
                Forms\Components\Section::make('Deploy Hooks')
                    ->description('Run custom scripts before or after deployment. Useful for database migrations, cache clearing, etc.')
                    ->icon('heroicon-o-command-line')
                    ->visible(fn (Get $get) => in_array($get('app_type'), [WebApp::APP_TYPE_NODEJS, WebApp::APP_TYPE_PHP]))
                    ->schema([
                        Forms\Components\Textarea::make('pre_deploy_script')
                            ->label('Pre-Deploy Script')
                            ->placeholder("# Example for Prisma:\nnpx prisma migrate deploy\nnpx prisma generate")
                            ->helperText('Runs after dependencies install, before build. Common uses: database migrations.')
                            ->rows(3),
                        Forms\Components\Textarea::make('post_deploy_script')
                            ->label('Post-Deploy Script')
                            ->placeholder("# Example for Laravel:\nphp artisan cache:clear\nphp artisan config:cache")
                            ->helperText('Runs after symlink swap. Common uses: cache clearing, queue restart.')
                            ->rows(3),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('PHP Settings')
                    ->description('Override PHP configuration values. Common settings like upload size and memory limits.')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn (Get $get) => $get('app_type') === WebApp::APP_TYPE_PHP)
                    ->headerActions([
                        Forms\Components\Actions\Action::make('ai_php_settings')
                            ->label('Recommended settings')
                            ->icon('heroicon-m-sparkles')
                            ->color('primary')
                            ->size('sm')
                            ->visible(fn () => config('ai.enabled'))
                            ->extraAttributes([
                                'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat(' . json_encode("What PHP settings should I use for a Laravel application? Explain common directives like memory_limit, max_execution_time, upload_max_filesize, post_max_size, and OPcache settings. What values work best for production?") . ')',
                            ]),
                    ])
                    ->schema([
                        Forms\Components\KeyValue::make('settings')
                            ->keyLabel('Directive')
                            ->valueLabel('Value')
                            ->addButtonLabel('Add PHP Setting')
                            ->default([
                                'upload_max_filesize' => '64M',
                                'post_max_size' => '64M',
                                'max_execution_time' => '300',
                                'memory_limit' => '256M',
                            ])
                            ->helperText('These override the default PHP settings for this app'),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Forms\Components\Section::make('Environment Variables')
                    ->description('Define environment variables for your application. These are written to the .env file.')
                    ->icon('heroicon-o-variable')
                    ->headerActions([
                        Forms\Components\Actions\Action::make('ai_env_help')
                            ->label('Essential variables')
                            ->icon('heroicon-m-sparkles')
                            ->color('primary')
                            ->size('sm')
                            ->visible(fn () => config('ai.enabled'))
                            ->extraAttributes([
                                'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat(' . json_encode("What environment variables are essential for a Laravel application in production? Explain APP_KEY, APP_ENV, APP_DEBUG, DB_*, CACHE_DRIVER, SESSION_DRIVER, QUEUE_CONNECTION, and MAIL_* variables. What are the best practices for each?") . ')',
                            ]),
                        Forms\Components\Actions\Action::make('ai_secure_env')
                            ->label('Security tips')
                            ->icon('heroicon-m-sparkles')
                            ->color('gray')
                            ->size('sm')
                            ->visible(fn () => config('ai.enabled'))
                            ->extraAttributes([
                                'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat(' . json_encode("How do I securely manage environment variables for my Laravel app? What are best practices for secrets management? Should I commit .env to git? How do I rotate API keys safely?") . ')',
                            ]),
                    ])
                    ->schema([
                        Forms\Components\KeyValue::make('environment_variables')
                            ->label('Environment Variables')
                            ->keyLabel('Variable Name')
                            ->valueLabel('Value')
                            ->addButtonLabel('Add Variable')
                            ->keyPlaceholder('APP_KEY')
                            ->valuePlaceholder('base64:...')
                            ->helperText(new HtmlString('Tip: Sensitive values like API keys should be added here. <a href="/app/documentation?topic=web-apps" class="text-primary-600 hover:underline" wire:navigate>Learn more</a>')),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Application Details')
                    ->description('Basic information about your web application.')
                    ->icon('heroicon-o-globe-alt')
                    ->schema([
                        Infolists\Components\TextEntry::make('name'),
                        Infolists\Components\TextEntry::make('domain')
                            ->copyable()
                            ->url(fn ($state) => "https://{$state}")
                            ->openUrlInNewTab(),
                        Infolists\Components\TextEntry::make('aliases')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('None'),
                        Infolists\Components\TextEntry::make('server.name')
                            ->label('Server'),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'creating' => 'info',
                                'active' => 'success',
                                'suspended', 'deleting' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('ssl_status')
                            ->label('SSL')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'active' => 'success',
                                'pending' => 'warning',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Configuration')
                    ->description('Runtime configuration for this application.')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->schema([
                        Infolists\Components\TextEntry::make('app_type')
                            ->label('Type')
                            ->badge()
                            ->formatStateUsing(fn ($state) => match ($state) {
                                WebApp::APP_TYPE_PHP => 'PHP',
                                WebApp::APP_TYPE_NODEJS => 'Node.js',
                                WebApp::APP_TYPE_STATIC => 'Static',
                                default => $state,
                            })
                            ->color(fn ($state) => match ($state) {
                                WebApp::APP_TYPE_PHP => 'info',
                                WebApp::APP_TYPE_NODEJS => 'success',
                                WebApp::APP_TYPE_STATIC => 'gray',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('web_server')
                            ->label('Web Server')
                            ->visible(fn ($record) => $record->app_type === WebApp::APP_TYPE_PHP)
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'nginx' => 'Nginx',
                                'nginx-apache' => 'Nginx + Apache',
                                default => $state,
                            }),
                        Infolists\Components\TextEntry::make('php_version')
                            ->label('PHP Version')
                            ->prefix('PHP ')
                            ->visible(fn ($record) => $record->app_type === WebApp::APP_TYPE_PHP),
                        Infolists\Components\TextEntry::make('node_version')
                            ->label('Node.js Version')
                            ->prefix('v')
                            ->visible(fn ($record) => $record->app_type === WebApp::APP_TYPE_NODEJS),
                        Infolists\Components\TextEntry::make('node_port')
                            ->label('Port')
                            ->visible(fn ($record) => $record->app_type === WebApp::APP_TYPE_NODEJS),
                        Infolists\Components\TextEntry::make('package_manager')
                            ->label('Package Manager')
                            ->visible(fn ($record) => $record->app_type !== WebApp::APP_TYPE_PHP),
                        Infolists\Components\TextEntry::make('public_path')
                            ->label('Public Path')
                            ->placeholder('/'),
                        Infolists\Components\TextEntry::make('document_root')
                            ->label('Document Root')
                            ->copyable()
                            ->helperText('The full path to your public files'),
                    ])
                    ->columns(4),

                Infolists\Components\Section::make('Node.js Commands')
                    ->description('Build and start commands for your Node.js application.')
                    ->icon('heroicon-o-command-line')
                    ->visible(fn ($record) => $record->app_type === WebApp::APP_TYPE_NODEJS)
                    ->schema([
                        Infolists\Components\TextEntry::make('start_command')
                            ->label('Start Command')
                            ->copyable()
                            ->placeholder('npm start'),
                        Infolists\Components\TextEntry::make('build_command')
                            ->label('Build Command')
                            ->copyable()
                            ->placeholder('npm run build'),
                        Infolists\Components\TextEntry::make('health_check_path')
                            ->label('Health Check')
                            ->placeholder('Not configured'),
                        Infolists\Components\TextEntry::make('static_assets_path')
                            ->label('Static Assets Path')
                            ->placeholder('Not configured'),
                    ])
                    ->columns(4)
                    ->collapsible(),

                Infolists\Components\Section::make('Git Repository')
                    ->description('Configure Git deployment to automatically deploy code from your repository.')
                    ->icon('heroicon-o-code-bracket')
                    ->schema([
                        Infolists\Components\TextEntry::make('repository_url')
                            ->label('Repository')
                            ->copyable()
                            ->placeholder('Not configured - Edit to add repository'),
                        Infolists\Components\TextEntry::make('branch')
                            ->badge()
                            ->color('info')
                            ->placeholder('main'),
                        Infolists\Components\TextEntry::make('deploy_script')
                            ->label('Deploy Script')
                            ->markdown()
                            ->columnSpanFull()
                            ->placeholder('No deploy script - Add commands like "composer install" or "npm run build"'),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Infolists\Components\Section::make('Latest Deployment')
                    ->description('Status of your most recent code deployment.')
                    ->icon('heroicon-o-rocket-launch')
                    ->schema([
                        Infolists\Components\TextEntry::make('latestDeployment.commit_hash')
                            ->label('Commit')
                            ->copyable()
                            ->placeholder('No deployments yet'),
                        Infolists\Components\TextEntry::make('latestDeployment.status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'pending' => 'warning',
                                'running' => 'info',
                                'active' => 'success',
                                'failed' => 'danger',
                                default => 'gray',
                            }),
                        Infolists\Components\TextEntry::make('latestDeployment.created_at')
                            ->label('Deployed At')
                            ->dateTime(),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Error')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->schema([
                        Infolists\Components\TextEntry::make('error_message')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => !empty($record->error_message)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('domain')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('server.name')
                    ->label('Server')
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => WebApp::STATUS_PENDING,
                        'info' => WebApp::STATUS_CREATING,
                        'success' => WebApp::STATUS_ACTIVE,
                        'danger' => [WebApp::STATUS_SUSPENDED, WebApp::STATUS_DELETING],
                    ]),
                Tables\Columns\TextColumn::make('app_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        WebApp::APP_TYPE_PHP => 'PHP',
                        WebApp::APP_TYPE_NODEJS => 'Node.js',
                        WebApp::APP_TYPE_STATIC => 'Static',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        WebApp::APP_TYPE_PHP => 'info',
                        WebApp::APP_TYPE_NODEJS => 'success',
                        WebApp::APP_TYPE_STATIC => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('php_version')
                    ->label('PHP')
                    ->badge()
                    ->visible(function()
                    {
                        return WebApp::where('app_type', WebApp::APP_TYPE_PHP);
                    })
                    ->color(fn ($state) => match ($state) {
                        '7.4', '8.0' => 'danger',  // EOL
                        '8.1' => 'warning',        // Security only
                        default => 'success',
                    })
                    ->tooltip(fn ($state) => match ($state) {
                        '7.4' => '⚠️ PHP 7.4 reached EOL in November 2022',
                        '8.0' => '⚠️ PHP 8.0 reached EOL in November 2023',
                        '8.1' => 'Security updates only until December 2025',
                        default => null,
                    }),
                Tables\Columns\TextColumn::make('node_version')
                    ->label('Node')
                    ->badge()
                    ->color('success')
                    ->prefix('v')
                    ->visible(function() {
                        return WebApp::where('app_type', WebApp::APP_TYPE_NODEJS);
                    }),
                Tables\Columns\BadgeColumn::make('ssl_status')
                    ->label('SSL')
                    ->colors([
                        'gray' => WebApp::SSL_NONE,
                        'warning' => WebApp::SSL_PENDING,
                        'success' => WebApp::SSL_ACTIVE,
                        'danger' => WebApp::SSL_FAILED,
                    ]),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        WebApp::STATUS_PENDING => 'Pending',
                        WebApp::STATUS_CREATING => 'Creating',
                        WebApp::STATUS_ACTIVE => 'Active',
                        WebApp::STATUS_SUSPENDED => 'Suspended',
                    ]),
                Tables\Filters\SelectFilter::make('app_type')
                    ->label('Type')
                    ->options([
                        WebApp::APP_TYPE_PHP => 'PHP',
                        WebApp::APP_TYPE_NODEJS => 'Node.js',
                        WebApp::APP_TYPE_STATIC => 'Static',
                    ]),
                Tables\Filters\SelectFilter::make('server_id')
                    ->relationship('server', 'name')
                    ->label('Server'),
            ])
            ->actions([
                // AI Troubleshoot for apps with issues
                Tables\Actions\Action::make('ai_troubleshoot')
                    ->label('AI Help')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(fn (WebApp $record) => config('ai.enabled') && ($record->status !== WebApp::STATUS_ACTIVE || $record->ssl_status === WebApp::SSL_FAILED))
                    ->extraAttributes(fn (WebApp $record) => [
                        'x-data' => '',
                        'x-on:click.prevent' => 'openAiChat(' . json_encode(
                            "Help me troubleshoot my web app '{$record->name}' at {$record->domain}. Status: {$record->status}, SSL: {$record->ssl_status}. " .
                            ($record->error_message ? "Error: {$record->error_message} " : "") .
                            "What could be wrong and how do I fix it?"
                        ) . ')',
                    ]),

                Tables\Actions\Action::make('visit')
                    ->label('Visit')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (WebApp $record) => "https://{$record->domain}")
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('clone')
                    ->label('Clone')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->visible(fn (WebApp $record) => $record->isActive())
                    ->form([
                        Forms\Components\TextInput::make('name')
                            ->label('New App Name')
                            ->required()
                            ->placeholder('My Cloned App'),
                        Forms\Components\TextInput::make('domain')
                            ->label('New Domain')
                            ->required()
                            ->regex('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i')
                            ->placeholder('staging.example.com'),
                    ])
                    ->action(function (WebApp $record, array $data) {
                        $cloned = $record->replicate([
                            'ssl_status', 'status', 'error_message', 'created_at', 'updated_at',
                        ]);
                        $cloned->name = $data['name'];
                        $cloned->domain = $data['domain'];
                        $cloned->status = WebApp::STATUS_PENDING;
                        $cloned->ssl_status = WebApp::SSL_NONE;
                        $cloned->save();

                        \Filament\Notifications\Notification::make()
                            ->title('App Cloned')
                            ->body("Created {$cloned->name}. Configure and deploy when ready.")
                            ->success()
                            ->send();

                        return redirect(WebAppResource::getUrl('edit', ['record' => $cloned]));
                    }),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('issue_ssl')
                    ->label('Issue SSL')
                    ->icon('heroicon-o-lock-closed')
                    ->color('success')
                    ->visible(fn (WebApp $record) => in_array($record->ssl_status, [WebApp::SSL_NONE, WebApp::SSL_FAILED]))
                    ->requiresConfirmation()
                    ->action(function (WebApp $record) {
                        // Create or get SSL certificate record
                        $certificate = \App\Models\SslCertificate::firstOrCreate(
                            ['web_app_id' => $record->id, 'domain' => $record->domain],
                            ['type' => \App\Models\SslCertificate::TYPE_LETSENCRYPT, 'status' => \App\Models\SslCertificate::STATUS_PENDING]
                        );

                        $record->update(['ssl_status' => WebApp::SSL_PENDING]);
                        $certificate->dispatchIssue();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-globe-alt')
            ->emptyStateHeading('No web applications yet')
            ->emptyStateDescription('Create a web application to host your website or API. Each app gets its own domain, SSL certificate, and deployment pipeline.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make()
                    ->label('Create Web App')
                    ->icon('heroicon-o-plus'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DeploymentsRelationManager::class,
            RelationManagers\SslCertificatesRelationManager::class,
            RelationManagers\CronJobsRelationManager::class,
            RelationManagers\HealthChecksRelationManager::class,
            RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebApps::route('/'),
            'create' => Pages\CreateWebApp::route('/create'),
            'view' => Pages\ViewWebApp::route('/{record}'),
            'edit' => Pages\EditWebApp::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('team_id', Filament::getTenant()?->id);
    }
}
