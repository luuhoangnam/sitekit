<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\SourceProviders;
use App\Filament\Resources\ServerResource;
use App\Models\Server;
use App\Models\SourceProvider;
use App\Models\WebApp;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class OnboardingWidget extends Widget
{
    protected static string $view = 'filament.widgets.onboarding-widget';

    protected static ?int $sort = 0;

    protected int | string | array $columnSpan = 'full';

    public function getSteps(): array
    {
        $teamId = Filament::getTenant()?->id;

        if (!$teamId) {
            return [];
        }

        $hasServer = Server::where('team_id', $teamId)->where('status', 'active')->exists();
        $hasSourceProvider = SourceProvider::where('provider_user_id', auth()?->id())->exists();
        $hasWebApp = WebApp::whereHas('server', fn ($q) => $q->where('team_id', $teamId))
            ->where('status', 'active')
            ->exists();

        return [
            [
                'title' => 'Connect a Server',
                'description' => 'Connect your first server to start deploying applications.',
                'completed' => $hasServer,
                'url' => $hasServer ? null : ServerResource::getUrl('create'),
                'icon' => 'heroicon-o-server-stack',
            ],
            [
                'title' => 'Connect Git Provider',
                'description' => 'Link your GitHub, GitLab, or Bitbucket account for deployments.',
                'completed' => $hasSourceProvider,
                'url' => $hasSourceProvider ? null : SourceProviders::getUrl(),
                'icon' => 'heroicon-o-code-bracket',
            ],
            [
                'title' => 'Create Web App',
                'description' => 'Deploy your first application to your server.',
                'completed' => $hasWebApp,
                'url' => $hasWebApp || !$hasServer ? null : route('filament.app.resources.web-apps.create', ['tenant' => $teamId]),
                'icon' => 'heroicon-o-globe-alt',
                'disabled' => !$hasServer,
            ],
        ];
    }

    public function getCompletedCount(): int
    {
        return collect($this->getSteps())->filter(fn ($step) => $step['completed'])->count();
    }

    public function getTotalCount(): int
    {
        return count($this->getSteps());
    }

    public function isComplete(): bool
    {
        return $this->getCompletedCount() === $this->getTotalCount();
    }

    public static function canView(): bool
    {
        $teamId = Filament::getTenant()?->id;

        if (!$teamId) {
            return false;
        }

        // Only show if user hasn't completed all steps
        $hasServer = Server::where('team_id', $teamId)->where('status', 'active')->exists();
        $hasWebApp = WebApp::whereHas('server', fn ($q) => $q->where('team_id', $teamId))
            ->where('status', 'active')
            ->exists();

        return !$hasServer || !$hasWebApp;
    }
}
