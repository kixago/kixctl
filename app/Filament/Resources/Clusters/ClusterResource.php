<?php

namespace App\Filament\Resources\Clusters;

use App\Filament\Resources\Clusters\Pages\CreateCluster;
use App\Filament\Resources\Clusters\Pages\EditCluster;
use App\Filament\Resources\Clusters\Pages\ListClusters;
use App\Filament\Resources\Clusters\Schemas\ClusterForm;
use App\Filament\Resources\Clusters\Tables\ClustersTable;
use App\Models\Cluster;
use App\Services\Incus\IncusClient;
use App\Services\Licensing\Entitlements;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ClusterResource extends Resource
{
    protected static ?string $model = Cluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $recordTitleAttribute = 'label';

    public static function getNavigationGroup(): ?string
    {
        return __('common.labels.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('clusters.plural');
    }

    public static function getModelLabel(): string
    {
        return __('clusters.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('clusters.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return ClusterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClustersTable::configure($table);
    }

    public static function notifyClusterLimit(Entitlements $entitlements): void
    {
        $cap = $entitlements->maxClusters();

        Notification::make()
            ->warning()
            ->title(__('licensing.gate.cap_reached_title'))
            ->body(trans_choice('licensing.gate.cap_reached_body', $cap, [
                'tier' => $entitlements->tierLabel(),
                'cap' => $cap,
            ]))
            ->persistent()
            ->actions([
                Action::make('upgrade')
                    ->label(__('licensing.gate.upgrade_action'))
                    ->button()
                    ->url('https://kixctl.com/pricing', shouldOpenInNewTab: true),
            ])
            ->send();
    }

    /**
     * Productizes the read-suite probe to verify connection scope on save.
     * Returns true if all checks passed, false if any failed.
     */
    public static function runScopeCheck(Cluster $record): bool
    {
        $incus = app(IncusClient::class);
        $cluster = $record->toEndpoint();

        $checks = [
            'serverInfo' => __('clusters.scope.server_info'),
            'members' => __('clusters.scope.members'),
            'instances' => __('clusters.scope.instances'),
            'storagePools' => __('clusters.scope.storage'),
            'networks' => __('clusters.scope.networks'),
            'profilesFull' => __('clusters.scope.profiles'),
        ];

        $results = [];
        $allPassed = true;
        $firstError = null;
        $version = null;

        foreach ($checks as $method => $label) {
            try {
                $res = $incus->{$method}($cluster);
                if ($method === 'serverInfo') {
                    $version = $res['server_version'] ?? null;
                }
                $results[$method] = ['label' => $label, 'ok' => true];
            } catch (\Throwable $e) {
                $allPassed = false;
                $message = $e->getMessage();
                $reason = preg_match('/"error"\s*:\s*"([^"]+)"/', $message, $m) ? $m[1] : Str::limit(strtok($message, "\n"), 120);

                if (! $firstError) {
                    $firstError = $reason;
                }

                $results[$method] = ['label' => $label, 'ok' => false, 'error' => $reason];
            }
        }

        if ($allPassed) {
            Notification::make()
                ->title(__('clusters.scope.success_title'))
                ->body(__('clusters.scope.success_body'))
                ->success()
                ->send();

            return true;
        }

        $html = '<div style="margin-bottom: 1rem;">';
        $html .= '<ul style="margin: 0; padding: 0; list-style: none;">';
        foreach ($results as $method => $res) {
            $icon = $res['ok'] ? '<span style="color: #22c55e; margin-right: 0.5rem; font-weight: bold;">✓</span>' : '<span style="color: #ef4444; margin-right: 0.5rem; font-weight: bold;">✗</span>';
            $text = $res['ok'] ? '' : ' <span style="opacity: 0.75; font-size: 0.9em; margin-left: 0.25rem;">— '.htmlspecialchars($res['error']).'</span>';
            $html .= "<li style=\"margin-bottom: 0.35rem;\">{$icon} <strong style=\"font-weight: 600;\">{$res['label']}</strong>{$text}</li>";
        }
        $html .= '</ul></div>';

        $fp = null;
        $pem = $cluster->connection['client_cert'] ?? null;
        if (is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE')) {
            $hash = @openssl_x509_fingerprint($pem, 'sha256');
            if ($hash) {
                $fp = substr($hash, 0, 12).'…';
            }
        }

        $remediation = __('resources.notice.restricted_cert_cause', [
            'reason' => lcfirst($firstError ?? 'access denied'),
            'version' => $version ?? __('common.labels.unknown_version'),
            'fingerprint' => $fp ? " (fingerprint {$fp})" : '',
        ]);

        $html .= '<div style="font-size: 0.9em; line-height: 1.5;">'.$remediation.'</div>';

        Notification::make()
            ->warning()
            ->title(__('clusters.scope.warning_title'))
            ->body(new HtmlString($html))
            ->persistent()
            ->send();

        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClusters::route('/'),
            'create' => CreateCluster::route('/create'),
            'edit' => EditCluster::route('/{record}/edit'),
        ];
    }
}
