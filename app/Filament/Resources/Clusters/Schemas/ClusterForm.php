<?php

namespace App\Filament\Resources\Clusters\Schemas;

use App\Models\Cluster as ClusterRecord;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ClusterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label(__('clusters.form.key'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state) => $state ? strtolower(trim($state)) : $state)
                    ->helperText(__('clusters.form.key_helper')),

                TextInput::make('label')
                    ->label(__('clusters.form.label'))
                    ->required()
                    ->helperText(__('clusters.form.label_helper')),

                Select::make('driver')
                    ->label(__('clusters.form.driver'))
                    ->required()
                    ->live()
                    ->default('https')
                    ->options([
                        'https' => __('clusters.form.driver_https'),
                        'socket' => __('clusters.form.driver_socket'),
                    ])
                    ->helperText(__('clusters.form.driver_helper')),

                TextInput::make('url')
                    ->label(__('clusters.form.url'))
                    ->url()
                    ->visible(fn (Get $get) => $get('driver') === 'https')
                    ->required(fn (Get $get) => $get('driver') === 'https')
                    ->placeholder(__('clusters.form.url_placeholder'))
                    ->helperText(__('clusters.form.url_helper')),

                TextInput::make('socket')
                    ->label(__('clusters.form.socket'))
                    ->visible(fn (Get $get) => $get('driver') === 'socket')
                    ->required(fn (Get $get) => $get('driver') === 'socket')
                    ->placeholder(__('clusters.form.socket_placeholder')),

                Textarea::make('client_cert')
                    ->label(__('clusters.form.client_cert'))
                    ->rows(6)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => $get('driver') === 'https')
                    ->required(fn (string $operation, Get $get) => $operation === 'create' && $get('driver') === 'https')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->afterStateHydrated(fn (Textarea $component) => $component->state(''))
                    ->placeholder(fn (string $operation) => $operation === 'edit'
                        ? __('clusters.form.client_cert_keep')
                        : null)
                    ->helperText(fn (string $operation, ?ClusterRecord $record) => $operation === 'edit'
                        ? __('clusters.form.client_cert_replace', ['summary' => self::storedCertSummary($record)])
                        : __('clusters.form.client_cert_paste')),

                Textarea::make('client_key')
                    ->label(__('clusters.form.client_key'))
                    ->rows(6)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => $get('driver') === 'https')
                    ->required(fn (string $operation, Get $get) => $operation === 'create' && $get('driver') === 'https')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->afterStateHydrated(fn (Textarea $component) => $component->state(''))
                    ->placeholder(fn (string $operation) => $operation === 'edit'
                        ? __('clusters.form.client_key_placeholder')
                        : null)
                    ->helperText(fn (string $operation) => $operation === 'edit'
                        ? __('clusters.form.client_key_replace')
                        : __('clusters.form.client_key_paste')),

                Toggle::make('verify')
                    ->label(__('clusters.form.verify'))
                    ->default(false)
                    ->visible(fn (Get $get) => $get('driver') === 'https')
                    ->helperText(__('clusters.form.verify_helper')),

                Toggle::make('is_active')
                    ->label(__('clusters.form.is_active'))
                    ->default(true)
                    ->helperText(__('clusters.form.is_active_helper')),

                TextInput::make('sort')
                    ->label(__('clusters.form.sort'))
                    ->numeric()
                    ->default(0)
                    ->helperText(__('clusters.form.sort_helper')),
            ]);
    }

    private static function storedCertSummary(?ClusterRecord $record): ?string
    {
        $pem = $record?->client_cert;

        if (! $pem) {
            return null;
        }

        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');

        if ($fingerprint === false) {
            return __('clusters.form.cert_summary_encrypted');
        }

        $parsed = @openssl_x509_parse($pem);
        $expires = isset($parsed['validTo_time_t'])
            ? gmdate('Y-m-d', $parsed['validTo_time_t'])
            : null;

        $msg = __('clusters.form.cert_summary_parsed', ['fingerprint' => substr($fingerprint, -8)]);
        if ($expires) {
            $msg .= __('clusters.form.cert_summary_expires', ['expires' => $expires]);
        } else {
            $msg .= '.';
        }

        return $msg;
    }
}
