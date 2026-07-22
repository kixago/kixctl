<?php

namespace App\Filament\Resources\Clusters\Schemas;

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
                    ->label('Key')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state) => $state ? strtolower(trim($state)) : $state)
                    ->helperText('Stable lowercase identifier, e.g. "acme". Used in URLs and broadcast channels — avoid changing later.'),

                TextInput::make('label')
                    ->label('Label')
                    ->required()
                    ->helperText('Human-friendly name shown on the dashboard chip.'),

                Select::make('driver')
                    ->label('Driver')
                    ->required()
                    ->live()
                    ->default('https')
                    ->options([
                        'https' => 'HTTPS (remote, client certificate)',
                        'socket' => 'Unix socket (local, break-glass)',
                    ])
                    ->helperText('Remote/customer clusters are always HTTPS. Socket is local-only.'),

                TextInput::make('url')
                    ->label('API URL')
                    ->url()
                    ->visible(fn (Get $get) => $get('driver') === 'https')
                    ->required(fn (Get $get) => $get('driver') === 'https')
                    ->placeholder('https://10.0.0.5:8443')
                    ->helperText('The cluster’s Incus HTTPS API endpoint.'),

                TextInput::make('socket')
                    ->label('Socket path')
                    ->visible(fn (Get $get) => $get('driver') === 'socket')
                    ->required(fn (Get $get) => $get('driver') === 'socket')
                    ->placeholder('/var/lib/incus/unix.socket'),

                Textarea::make('client_cert')
                    ->label('Client certificate (PEM)')
                    ->rows(6)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => $get('driver') === 'https')
                    ->required(fn (string $operation, Get $get) => $operation === 'create' && $get('driver') === 'https')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->afterStateHydrated(fn (Textarea $component) => $component->state(''))
                    ->placeholder(fn (string $operation) => $operation === 'edit'
                        ? 'Stored — leave blank to keep the current certificate'
                        : null)
                    ->helperText(fn (string $operation, ?ClusterRecord $record) => $operation === 'edit'
                        ? (self::storedCertSummary($record) ?? 'No certificate stored.').' Leave blank to keep it, or paste a new PEM to replace it.'
                        : 'Paste the PEM.'),

                Textarea::make('client_key')
                    ->label('Client key (PEM)')
                    ->rows(6)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => $get('driver') === 'https')
                    ->required(fn (string $operation, Get $get) => $operation === 'create' && $get('driver') === 'https')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->afterStateHydrated(fn (Textarea $component) => $component->state(''))
                    ->placeholder(fn (string $operation) => $operation === 'edit'
                        ? '•••••• stored encrypted — never displayed'
                        : null)
                    ->helperText(fn (string $operation) => $operation === 'edit'
                        ? 'A private key is stored encrypted and is never displayed here. Leave blank to keep it, or paste a new PEM to replace it.'
                        : 'Paste the PEM private key. Stored encrypted.'),

                Toggle::make('verify')
                    ->label('Verify TLS certificate')
                    ->default(false)
                    ->visible(fn (Get $get) => $get('driver') === 'https')
                    ->helperText('Off for self-signed cluster certs (typical).'),

                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Inactive clusters are hidden from the dashboard.'),

                TextInput::make('sort')
                    ->label('Sort order')
                    ->numeric()
                    ->default(0)
                    ->helperText('Lower numbers appear first.'),
            ]);
    }

    /**
     * A safe, reassuring summary of the STORED certificate for the edit
     * form: fingerprint + expiry (a certificate is public material — this
     * proves "we still have it" without ever echoing the PEM back, and
     * the private key is never summarized at all).
     */
    private static function storedCertSummary(?ClusterRecord $record): ?string
    {
        $pem = $record?->client_cert; // encrypted cast decrypts on read

        if (! $pem) {
            return null;
        }

        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');

        if ($fingerprint === false) {
            return 'A certificate is stored encrypted.';
        }

        $parsed = @openssl_x509_parse($pem);
        $expires = isset($parsed['validTo_time_t'])
            ? gmdate('Y-m-d', $parsed['validTo_time_t'])
            : null;

        return 'A certificate is stored (SHA-256 …'.substr($fingerprint, -8).')'
            .($expires ? ", expires {$expires}." : '.');
    }
}
