<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\RequiresServerForNavigation;
use App\Filament\Resources\ServiceResource\Pages;
use App\Models\Service;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ServiceResource extends Resource
{
    use RequiresServerForNavigation;

    protected static ?string $model = Service::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Infrastructure';
    protected static ?string $tenantRelationshipName = null;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Services';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Service Configuration')
                    ->schema([
                        Forms\Components\Select::make('server_id')
                            ->relationship('server', 'name', fn (Builder $query) =>
                                $query->where('team_id', Filament::getTenant()?->id)
                                    ->where('status', 'active'))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live(),
                        Forms\Components\Select::make('type')
                            ->options(Service::getAvailableTypes())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state) {
                                if ($state) {
                                    $versions = Service::getVersionsForType($state);
                                    $set('version', $versions[0] ?? 'latest');
                                }
                            }),
                        Forms\Components\Select::make('version')
                            ->options(function (Get $get) {
                                $type = $get('type');
                                if (!$type) {
                                    return [];
                                }
                                $versions = Service::getVersionsForType($type);
                                return array_combine($versions, $versions);
                            })
                            ->required()
                            ->live(),
                        Forms\Components\Toggle::make('is_default')
                            ->label('Set as default version')
                            ->helperText('Used when no version is specified')
                            ->default(false),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('PHP Extensions')
                    ->schema([
                        Forms\Components\CheckboxList::make('configuration.extensions')
                            ->options([
                                'cli' => 'CLI',
                                'fpm' => 'FPM',
                                'mysql' => 'MySQL',
                                'pgsql' => 'PostgreSQL',
                                'sqlite3' => 'SQLite',
                                'gd' => 'GD',
                                'imagick' => 'ImageMagick',
                                'curl' => 'cURL',
                                'mbstring' => 'Mbstring',
                                'xml' => 'XML',
                                'zip' => 'Zip',
                                'bcmath' => 'BCMath',
                                'intl' => 'Intl',
                                'readline' => 'Readline',
                                'opcache' => 'OPcache',
                                'redis' => 'Redis',
                                'memcached' => 'Memcached',
                                'soap' => 'SOAP',
                                'xdebug' => 'Xdebug',
                            ])
                            ->columns(4)
                            ->default(['cli', 'fpm', 'mysql', 'pgsql', 'sqlite3', 'gd', 'curl', 'mbstring', 'xml', 'zip', 'bcmath', 'intl', 'readline', 'opcache', 'redis']),
                    ])
                    ->visible(fn (Get $get) => $get('type') === Service::TYPE_PHP),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('server.name')
                    ->label('Server')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Service')
                    ->searchable(['type', 'version'])
                    ->description(function (Service $record) {
                        if ($record->type !== Service::TYPE_PHP) {
                            return null;
                        }
                        $apps = $record->getWebAppsUsingVersion();
                        if ($apps->isEmpty()) {
                            return null;
                        }
                        $names = $apps->take(3)->pluck('domain')->implode(', ');
                        $more = $apps->count() > 3 ? ' +' . ($apps->count() - 3) . ' more' : '';
                        return "Used by: {$names}{$more}";
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => Service::STATUS_ACTIVE,
                        'warning' => Service::STATUS_STOPPED,
                        'danger' => Service::STATUS_FAILED,
                        'gray' => 'default',
                    ]),
                Tables\Columns\IconColumn::make('health_status')
                    ->label('Health')
                    ->icon(fn ($state) => match ($state) {
                        'healthy' => 'heroicon-o-check-circle',
                        'degraded' => 'heroicon-o-exclamation-triangle',
                        'unhealthy' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-question-mark-circle',
                    })
                    ->color(fn ($state) => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'unhealthy' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(function (Service $record) {
                        $status = $record->health_status ?? 'Unknown';
                        $tooltip = ucfirst($status);

                        if ($record->isDatabaseEngine()) {
                            if ($record->database_health_response_ms) {
                                $tooltip .= " ({$record->database_health_response_ms}ms)";
                            }
                            if ($record->database_health_error) {
                                $tooltip .= "\n" . $record->database_health_error;
                            }
                        }

                        return $tooltip;
                    }),
                Tables\Columns\TextColumn::make('memory_mb')
                    ->label('Memory')
                    ->formatStateUsing(fn ($state) => $state ? "{$state} MB" : null)
                    ->placeholder('-')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('installed_at')
                    ->label('Installed')
                    ->since()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->label('Error')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->error_message)
                    ->visible(fn ($record) => $record?->status === Service::STATUS_FAILED),
            ])
            ->defaultSort('type')
            // ->groups([
            //     Tables\Grouping\Group::make('category')
            //         ->label('Category')
            //         ->getTitleFromRecordUsing(fn (Service $record) => $record->category)
            //         ->collapsible(),
            // ])
            // ->defaultGroup('category')
            ->filters([
                Tables\Filters\SelectFilter::make('server_id')
                    ->relationship('server', 'name')
                    ->label('Server'),
                Tables\Filters\SelectFilter::make('type')
                    ->options(Service::getAvailableTypes()),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        Service::STATUS_ACTIVE => 'Active',
                        Service::STATUS_STOPPED => 'Stopped',
                        Service::STATUS_FAILED => 'Failed',
                    ]),
            ])
            ->actions([
                // AI Diagnose for failed services
                Tables\Actions\Action::make('ai_diagnose')
                    ->label('AI Diagnose')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(fn (Service $record) => $record->status === Service::STATUS_FAILED && config('ai.enabled'))
                    ->url('#')
                    ->extraAttributes(fn (Service $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("My ' . e($record->display_name) . ' service on server \'' . e($record->server?->name) . '\' has failed. Error: ' . e($record->error_message ?? 'Unknown error') . '. Help me diagnose and fix this issue.")',
                    ]),

                // AI Explain why stopped
                Tables\Actions\Action::make('ai_why_stopped')
                    ->label('Why stopped?')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->visible(fn (Service $record) => $record->status === Service::STATUS_STOPPED && config('ai.enabled'))
                    ->url('#')
                    ->extraAttributes(fn (Service $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("My ' . e($record->display_name) . ' service on server \'' . e($record->server?->name) . '\' is stopped. What could be the reasons for this and how do I safely start it again?")',
                    ]),

                // AI Optimize for database services
                Tables\Actions\Action::make('ai_optimize')
                    ->label('AI Optimize')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->visible(fn (Service $record) => $record->isActive() && in_array($record->type, [Service::TYPE_MYSQL, Service::TYPE_MARIADB, Service::TYPE_POSTGRESQL]) && config('ai.enabled'))
                    ->url('#')
                    ->extraAttributes(fn (Service $record) => [
                        'x-data' => '',
                                'x-on:click.prevent' => 'openAiChat("Help me optimize ' . e($record->display_name) . ' on my server \'' . e($record->server?->name) . '\' which has ' . ($record->server?->cpu_count ?? 'unknown') . ' CPU cores and ' . ($record->server?->memory_mb ?? 'unknown') . ' MB RAM. Current memory usage: ' . ($record->memory_mb ?? 'unknown') . ' MB. Suggest optimal configuration settings.")',
                    ]),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('restart')
                    ->label('Restart')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Service $record) => $record->isActive())
                    ->requiresConfirmation()
                    ->action(function (Service $record) {
                        $record->dispatchRestart();
                        Notification::make()
                            ->title('Service restart queued')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('reload')
                    ->label('Reload')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('info')
                    ->visible(fn (Service $record) => $record->isActive())
                    ->action(function (Service $record) {
                        $record->dispatchReload();
                        Notification::make()
                            ->title('Service reload queued')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('stop')
                    ->label('Stop')
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    // Core services (nginx, supervisor) cannot be stopped
                    ->visible(fn (Service $record) => $record->isActive() && $record->canBeStopped())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Service $record) => "Stop {$record->display_name}")
                    ->modalDescription(function (Service $record) {
                        if ($record->isDatabaseEngine() && $record->hasDependentDatabases()) {
                            $count = $record->getDependentDatabases()->count();
                            return "Warning: {$count} database(s) depend on this engine.";
                        }
                        return "Are you sure?";
                    })
                    ->action(function (Service $record) {
                        $record->dispatchStop();
                        Notification::make()
                            ->title('Service stop queued')
                            ->warning()
                            ->send();
                    }),
                Tables\Actions\Action::make('start')
                    ->label('Start')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (Service $record) => $record->status === Service::STATUS_STOPPED)
                    ->requiresConfirmation(fn (Service $record) => $record->getConflictingServices()->isNotEmpty())
                    ->modalHeading(fn (Service $record) => $record->getConflictingServices()->isNotEmpty()
                        ? 'Stop conflicting service?'
                        : 'Start service')
                    ->modalDescription(function (Service $record) {
                        $conflicts = $record->getConflictingServices();
                        if ($conflicts->isEmpty()) {
                            return null;
                        }
                        $names = $conflicts->pluck('display_name')->join(', ');
                        return "Starting {$record->display_name} will stop {$names} because they cannot run simultaneously.";
                    })
                    ->action(function (Service $record) {
                        // Stop conflicting services first
                        $conflicts = $record->getConflictingServices();
                        foreach ($conflicts as $conflict) {
                            $conflict->dispatchStop();
                            $conflict->update(['status' => Service::STATUS_STOPPING]);
                        }

                        $record->dispatchStart();

                        $message = 'Service start queued';
                        if ($conflicts->isNotEmpty()) {
                            $names = $conflicts->pluck('display_name')->join(', ');
                            $message .= ". Stopping {$names} first.";
                        }

                        Notification::make()
                            ->title($message)
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('repair')
                    ->label('Repair')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->visible(fn (Service $record) => $record->canBeRepaired())
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-wrench-screwdriver')
                    ->modalHeading(fn (Service $record) => "Repair {$record->display_name}")
                    ->modalDescription(function (Service $record) {
                        $desc = "This will re-run the provisioning for {$record->display_name}. ";
                        if ($record->isDatabaseEngine()) {
                            $desc .= "Database credentials will be regenerated. Your existing databases will NOT be deleted.";
                        } else {
                            $desc .= "Configuration will be reset to defaults.";
                        }
                        return $desc;
                    })
                    ->action(function (Service $record) {
                        $record->dispatchRepair();

                        Notification::make()
                            ->title('Repair Job Queued')
                            ->body("Re-provisioning {$record->display_name}. This may take a few minutes.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('logs')
                    ->label('Logs')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->visible(fn (Service $record) => $record->isActive())
                    ->modalHeading(fn (Service $record) => "Logs: {$record->display_name}")
                    ->modalContent(fn (Service $record) => new HtmlString(
                        '<div class="bg-gray-900 text-green-400 p-4 rounded-lg font-mono text-xs max-h-96 overflow-y-auto">' .
                        '<pre>' . e($record->last_log_output ?? 'No logs available. Logs are captured during service operations.') . '</pre>' .
                        '</div>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        // Services are synced from heartbeat, not created manually
        return [
            'index' => Pages\ListServices::route('/'),
            'view' => Pages\ViewService::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Skip parent's tenant check - we handle it via server relationship
        return Service::query()
            ->whereHas('server', fn (Builder $query) =>
                $query->where('team_id', Filament::getTenant()?->id));
    }
    public static function isScopedToTenant(): bool
    {
        return false;
    }
}
