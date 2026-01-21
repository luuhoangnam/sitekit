<?php

namespace App\Filament\Resources\DatabaseResource\Pages;

use App\Filament\Resources\DatabaseResource;
use App\Models\Database;
use App\Models\DatabaseUser;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Infolists\Components\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ViewDatabase extends ViewRecord
{
    protected static string $resource = DatabaseResource::class;

    public function getPollingInterval(): ?string
    {
        if ($this->record && $this->record->status === Database::STATUS_PENDING) {
            return '3s';
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // AI Help Actions
            Actions\ActionGroup::make([
                Actions\Action::make('ai_optimize')
                    ->label('Optimization Tips')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->visible(fn () => config('ai.enabled'))
                    ->extraAttributes(fn (Database $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("Help me optimize my ' . e($record->type) . ' database \'' . e($record->name) . '\' on server \'' . e($record->server?->name) . '\'. The database is ' . ($record->size_mb ? e($record->size_mb) . ' MB' : 'unknown size') . '. Suggest indexing strategies, query optimization, and configuration tuning.")',
                    ]),

                Actions\Action::make('ai_backup')
                    ->label('Backup Strategy')
                    ->icon('heroicon-o-sparkles')
                    ->color('info')
                    ->visible(fn () => config('ai.enabled'))
                    ->extraAttributes(fn (Database $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("What is the best backup strategy for my ' . e($record->type) . ' database? Current backup: ' . ($record->backup_enabled ? 'Enabled (' . e($record->backup_schedule) . ')' : 'Disabled') . '. Last backup: ' . ($record->last_backup_at ? e($record->last_backup_at->diffForHumans()) : 'Never') . '. Recommend backup frequency, retention, and disaster recovery practices.")',
                    ]),

                Actions\Action::make('ai_troubleshoot')
                    ->label('Troubleshoot')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->visible(fn (Database $record) => $record->status === Database::STATUS_FAILED && config('ai.enabled'))
                    ->extraAttributes(fn (Database $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("My ' . e($record->type) . ' database \'' . e($record->name) . '\' has failed. Error: ' . e($record->error_message ?? 'Unknown') . '. How do I diagnose and fix this issue?")',
                    ]),

                Actions\Action::make('ai_security')
                    ->label('Security Audit')
                    ->icon('heroicon-o-sparkles')
                    ->color('danger')
                    ->visible(fn () => config('ai.enabled'))
                    ->extraAttributes(fn (Database $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("Audit the security of my ' . e($record->type) . ' database. I have ' . e($record->users->count()) . ' user(s). ' . ($record->users->where('can_remote', true)->count() > 0 ? 'Some users have remote access enabled.' : 'All users are local only.') . ' What are security best practices for database user permissions, remote access, and data protection?")',
                    ]),
            ])
                ->label('AI Help')
                ->icon('heroicon-o-sparkles')
                ->color('primary')
                ->button()
                ->visible(fn () => config('ai.enabled')),

            Actions\EditAction::make(),

            Actions\Action::make('add_user')
                ->label('Add User')
                ->icon('heroicon-o-user-plus')
                ->form([
                    Forms\Components\TextInput::make('username')
                        ->required()
                        ->alphaDash()
                        ->maxLength(32)
                        ->default(fn () => 'user_' . Str::random(8)),
                    Forms\Components\TextInput::make('password')
                        ->required()
                        ->password()
                        ->revealable()
                        ->default(fn () => Str::random(24)),
                    Forms\Components\Toggle::make('can_remote')
                        ->label('Allow remote connections')
                        ->default(false)
                        ->helperText('Enable if you need to connect from outside the server'),
                ])
                ->action(function (array $data, Database $record) {
                    DatabaseUser::create([
                        'database_id' => $record->id,
                        'server_id' => $record->server_id,
                        'username' => $data['username'],
                        'password' => $data['password'],
                        'can_remote' => $data['can_remote'],
                    ]);

                    $record->dispatchJob('create_database_user', [
                        'db_name' => $record->name,
                        'type' => $record->type,
                        'username' => $data['username'],
                        'password' => $data['password'],
                        'can_remote' => $data['can_remote'],
                    ]);

                    Notification::make()
                        ->title('User creation in progress')
                        ->body("Creating user {$data['username']}...")
                        ->info()
                        ->send();
                }),

            Actions\ActionGroup::make([
                Actions\Action::make('export')
                    ->label('Export Database')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->form([
                        Forms\Components\Toggle::make('include_structure')
                            ->label('Include structure')
                            ->default(true),
                        Forms\Components\Toggle::make('include_data')
                            ->label('Include data')
                            ->default(true),
                        Forms\Components\Toggle::make('compress')
                            ->label('Compress (gzip)')
                            ->default(true),
                    ])
                    ->action(function (array $data, Database $record) {
                        $record->dispatchJob('export_database', [
                            'db_name' => $record->name,
                            'type' => $record->type,
                            'include_structure' => $data['include_structure'],
                            'include_data' => $data['include_data'],
                            'compress' => $data['compress'],
                            'priority' => 3
                        ]);

                        Notification::make()
                            ->title('Export started')
                            ->body('You will be notified when the export is ready for download.')
                            ->info()
                            ->send();
                    }),

                Actions\Action::make('import')
                    ->label('Import Database')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->form([
                        Forms\Components\FileUpload::make('sql_file')
                            ->label('SQL File')
                            ->required()
                            ->acceptedFileTypes(['application/sql', 'application/x-sql', 'text/sql', 'application/gzip', 'application/x-gzip'])
                            ->maxSize(512000) // 500MB
                            ->helperText('Upload a .sql or .sql.gz file'),
                        Forms\Components\Toggle::make('drop_existing')
                            ->label('Drop existing tables')
                            ->default(false)
                            ->helperText('Warning: This will delete all existing data!'),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading('Import Database')
                    ->modalDescription('This will import the SQL file into your database. Existing data may be affected.')
                    ->action(function (array $data, Database $record) {
                        $record->dispatchJob('import_database', [
                            'db_name' => $record->name,
                            'type' => $record->type,
                            'file_path' => $data['sql_file'],
                            'drop_existing' => $data['drop_existing'],
                            'priority' => 2
                        ]);

                        Notification::make()
                            ->title('Import started')
                            ->body('The database import is in progress.')
                            ->info()
                            ->send();
                    }),

                Actions\Action::make('optimize')
                    ->label('Optimize Tables')
                    ->icon('heroicon-o-sparkles')
                    ->requiresConfirmation()
                    ->action(function (Database $record) {
                        $record->dispatchJob('optimize_database', [
                            'db_name' => $record->name,
                            'type' => $record->type,
                            'priority' => 5
                        ]);

                        Notification::make()
                            ->title('Optimization started')
                            ->body('Database tables are being optimized.')
                            ->info()
                            ->send();
                    }),
            ])
                ->label('Actions')
                ->icon('heroicon-m-ellipsis-vertical')
                ->button(),

            Actions\DeleteAction::make()
                ->before(function (Database $record) {
                    $record->dispatchJob('delete_database', [
                        'db_name' => $record->name,
                        'type' => $record->type,
                        'users' => $record->users->pluck('username')->toArray(),
                    ]);
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
                            ->formatStateUsing(fn (Database $record) => $record->getSuggestedActionLabel())
                            ->badge()
                            ->color('warning')
                            ->placeholder('None'),
                        TextEntry::make('error_message')
                            ->label('Technical Details')
                            ->columnSpanFull()
                            ->visible(fn (Database $record) => $record->error_message !== null && $record->error_message !== $record->last_error),
                    ])
                    ->columns(2)
                    ->visible(fn (Database $record) => $record->hasError() || $record->error_message !== null),

                Section::make('Connection Details')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Database Name')
                            ->copyable(),
                        TextEntry::make('type')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                Database::TYPE_MARIADB => 'success',
                                Database::TYPE_MYSQL => 'info',
                                Database::TYPE_POSTGRESQL => 'primary',
                                default => 'gray',
                            }),
                        TextEntry::make('host')
                            ->label('Host')
                            ->copyable(),
                        TextEntry::make('port')
                            ->copyable(),
                        TextEntry::make('server.name')
                            ->label('Server'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                Database::STATUS_PENDING => 'warning',
                                Database::STATUS_ACTIVE => 'success',
                                Database::STATUS_FAILED => 'danger',
                                default => 'gray',
                            }),
                    ])
                    ->columns(3),

                Section::make('Database Users')
                    ->schema([
                        RepeatableEntry::make('users')
                            ->schema([
                                TextEntry::make('username')
                                    ->copyable(),
                                TextEntry::make('password')
                                    ->copyable()
                                    ->formatStateUsing(fn () => '********')
                                    ->copyableState(fn ($record) => $record->password),
                                TextEntry::make('can_remote')
                                    ->label('Remote Access')
                                    ->badge()
                                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                                    ->formatStateUsing(fn (bool $state): string => $state ? 'Enabled' : 'Local Only'),
                            ])
                            ->columns(3)
                            ->contained(false),
                    ])
                    ->description('Use these credentials to connect to your database.'),

                Section::make('How to Connect')
                    ->icon('heroicon-o-question-mark-circle')
                    ->description('Choose the method that works best for your use case.')
                    ->schema([
                        TextEntry::make('ssh_tunnel_info')
                            ->label('SSH Tunnel (Recommended for TablePlus, DBeaver, etc.)')
                            ->getStateUsing(fn (Database $record) => new HtmlString(
                                '<div class="space-y-2 text-sm">' .
                                '<p class="text-gray-600 dark:text-gray-400">Use SSH tunnel for secure connections through your server.</p>' .
                                '<div class="bg-gray-100 dark:bg-gray-800 p-3 rounded-lg font-mono text-xs space-y-1">' .
                                '<div><span class="text-gray-500">SSH Host:</span> <span class="text-gray-900 dark:text-gray-100">' . e($record->server->ip_address) . '</span></div>' .
                                '<div><span class="text-gray-500">SSH Port:</span> <span class="text-gray-900 dark:text-gray-100">' . e($record->server->ssh_port ?? 22) . '</span></div>' .
                                '<div><span class="text-gray-500">SSH User:</span> <span class="text-gray-900 dark:text-gray-100">sitekit</span> (or root)</div>' .
                                '<div><span class="text-gray-500">DB Host:</span> <span class="text-gray-900 dark:text-gray-100">127.0.0.1</span></div>' .
                                '<div><span class="text-gray-500">DB Port:</span> <span class="text-gray-900 dark:text-gray-100">' . e($record->port) . '</span></div>' .
                                '<div><span class="text-gray-500">Database:</span> <span class="text-gray-900 dark:text-gray-100">' . e($record->name) . '</span></div>' .
                                '</div>' .
                                '</div>'
                            ))
                            ->html(),

                        TextEntry::make('laravel_config')
                            ->label('Laravel / PHP Configuration')
                            ->getStateUsing(function (Database $record) {
                                $user = $record->users->first();
                                $driver = $record->type === Database::TYPE_POSTGRESQL ? 'pgsql' : 'mysql';

                                return new HtmlString(
                                    '<div class="space-y-2 text-sm">' .
                                    '<p class="text-gray-600 dark:text-gray-400">Add these to your <code class="text-xs bg-gray-200 dark:bg-gray-700 px-1 rounded">.env</code> file:</p>' .
                                    '<div class="bg-gray-900 text-green-400 p-3 rounded-lg font-mono text-xs overflow-x-auto">' .
                                    '<pre>DB_CONNECTION=' . $driver . "\n" .
                                    'DB_HOST=127.0.0.1' . "\n" .
                                    'DB_PORT=' . e($record->port) . "\n" .
                                    'DB_DATABASE=' . e($record->name) . "\n" .
                                    'DB_USERNAME=' . e($user?->username ?? 'your_username') . "\n" .
                                    'DB_PASSWORD=' . e($user ? '********' : 'your_password') . '</pre>' .
                                    '</div>' .
                                    '</div>'
                                );
                            })
                            ->html(),

                        TextEntry::make('cli_connection')
                            ->label('Command Line')
                            ->getStateUsing(function (Database $record) {
                                $user = $record->users->first();
                                if ($record->type === Database::TYPE_POSTGRESQL) {
                                    $cmd = 'psql -h 127.0.0.1 -U ' . e($user?->username ?? 'username') . ' -d ' . e($record->name);
                                } else {
                                    $cmd = 'mysql -h 127.0.0.1 -u ' . e($user?->username ?? 'username') . ' -p ' . e($record->name);
                                }

                                return new HtmlString(
                                    '<div class="space-y-2 text-sm">' .
                                    '<p class="text-gray-600 dark:text-gray-400">SSH into your server first, then run:</p>' .
                                    '<div class="bg-gray-900 text-green-400 p-3 rounded-lg font-mono text-xs">' .
                                    '<code>' . $cmd . '</code>' .
                                    '</div>' .
                                    '</div>'
                                );
                            })
                            ->html(),
                    ])
                    ->columns(1)
                    ->collapsible()
                    ->collapsed(false),

                Section::make('Linked Web App')
                    ->schema([
                        TextEntry::make('webApp.name')
                            ->label('Application')
                            ->placeholder('No linked application'),
                        TextEntry::make('webApp.domain')
                            ->label('Domain')
                            ->placeholder('-'),
                    ])
                    ->columns(2)
                    ->visible(fn (Database $record) => $record->web_app_id !== null),
            ]);
    }
}
