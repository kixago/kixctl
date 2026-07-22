<?php

namespace App\Filament\Resources\Clusters;

use App\Filament\Resources\Clusters\Pages\CreateCluster;
use App\Filament\Resources\Clusters\Pages\EditCluster;
use App\Filament\Resources\Clusters\Pages\ListClusters;
use App\Filament\Resources\Clusters\Schemas\ClusterForm;
use App\Filament\Resources\Clusters\Tables\ClustersTable;
use App\Models\Cluster;
use App\Services\Licensing\Entitlements;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ClusterResource extends Resource
{
    protected static ?string $model = Cluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Clusters';

    protected static ?string $recordTitleAttribute = 'label';

    public static function form(Schema $schema): Schema
    {
        return ClusterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClustersTable::configure($table);
    }

    /**
     * The one upsell message for the free-cap gate — sent by the locked
     * list button, the /create mount guard, and the beforeCreate()
     * backstop, so the copy never drifts between the three doors.
     */
    public static function notifyClusterLimit(Entitlements $entitlements): void
    {
        $cap = $entitlements->maxClusters();

        Notification::make()
            ->warning()
            ->title('Cluster limit reached')
            ->body(
                "The {$entitlements->tierLabel()} tier manages up to {$cap} ".
                str('cluster')->plural($cap)." from this panel — every feature, no limits, on each of them. ".
                'To manage more clusters from one pane, add a fleet license.'
            )
            ->persistent()
            ->actions([
                Action::make('upgrade')
                    ->label('Get a fleet license')
                    ->button()
                    ->url('https://kixctl.com/pricing', shouldOpenInNewTab: true),
            ])
            ->send();
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
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
