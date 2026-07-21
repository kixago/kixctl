<?php

namespace App\Filament\Resources\Clusters;

use App\Filament\Resources\Clusters\Pages\CreateCluster;
use App\Filament\Resources\Clusters\Pages\EditCluster;
use App\Filament\Resources\Clusters\Pages\ListClusters;
use App\Filament\Resources\Clusters\Schemas\ClusterForm;
use App\Filament\Resources\Clusters\Tables\ClustersTable;
use App\Models\Cluster;
use BackedEnum;
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
