<?php

namespace App\Filament\Resources\Clusters\Pages;

use App\Filament\Resources\Clusters\ClusterResource;
use App\Models\Cluster;
use App\Services\Incus\IncusEnroller;
use App\Services\Licensing\Entitlements;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Throwable;

class ListClusters extends ListRecords
{
    protected static string $resource = ClusterResource::class;

    protected function getHeaderActions(): array
    {
        $entitlements = app(Entitlements::class);

        if ($entitlements->canAddCluster()) {
            return [
                $this->connectClusterAction(),
                CreateAction::make()
                    ->label('Add manually')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray'),
            ];
        }

        return [
            Action::make('clusterLimit')
                ->label(__('licensing.gate.button_locked'))
                ->icon(Heroicon::OutlinedLockClosed)
                ->color('gray')
                ->action(fn () => ClusterResource::notifyClusterLimit($entitlements)),
        ];
    }

    /**
     * Connect an existing Incus cluster with a one-time trust token — the same
     * enrollment the setup wizard uses, surfaced permanently so operators can add
     * more clusters (their own, or customers') without re-running first-run setup.
     * The manual add form stays for people who already hold a cert/key.
     */
    protected function connectClusterAction(): Action
    {
        return Action::make('connectCluster')
            ->label('Connect a cluster')
            ->icon(Heroicon::OutlinedLink)
            ->modalHeading('Connect an Incus cluster')
            ->modalSubmitActionLabel('Connect')
            ->schema([
                TextInput::make('label')
                    ->label('Name')
                    ->required()
                    ->maxLength(64)
                    ->live(onBlur: true)
                    ->placeholder("e.g. Acme's cluster"),

                TextInput::make('project')
                    ->label('Project')
                    ->default('default')
                    ->required()
                    ->maxLength(63)
                    ->live(onBlur: true)
                    ->helperText('The Incus project kixctl is scoped to. Leave as "default" unless your apps live in their own project.'),

                Placeholder::make('trustCommand')
                    ->label('1. Run this on that cluster')
                    ->content(fn (Get $get): HtmlString => $this->trustCommandHtml($get('label'), $get('project'))),

                Textarea::make('token')
                    ->label('2. Paste the token it prints')
                    ->required()
                    ->rows(3)
                    ->live(debounce: 600)
                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                        $json = base64_decode(trim((string) $state), true);
                        if ($json === false) {
                            return;
                        }
                        $decoded = json_decode($json, true);
                        if (is_array($decoded) && ! empty($decoded['addresses'][0])) {
                            $set('endpoint', 'https://'.$decoded['addresses'][0]);
                        }
                    }),

                TextInput::make('endpoint')
                    ->label('Cluster address')
                    ->required()
                    ->url()
                    ->startsWith('https://')
                    ->placeholder('https://192.168.1.10:8443')
                    ->helperText('Auto-filled from the token — you rarely need to touch this. Change it only if your cluster answers at a different address.'),
            ])
            ->action(function (array $data): void {
                $entitlements = app(Entitlements::class);
                if (! $entitlements->canAddCluster()) {
                    ClusterResource::notifyClusterLimit($entitlements);

                    return;
                }

                $slug = Str::slug($data['label']) ?: 'cluster';
                $certName = 'kixctl-'.$slug;

                try {
                    $enrolled = app(IncusEnroller::class)->enroll(trim($data['endpoint']), trim($data['token']), $certName);
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Could not connect')
                        ->body($this->humanizeEnrollError($e->getMessage()))
                        ->danger()
                        ->send();

                    return;
                }

                Cluster::create([
                    'key' => $this->uniqueClusterKey($slug),
                    'label' => $data['label'],
                    'driver' => 'https',
                    'url' => rtrim(trim($data['endpoint']), '/'),
                    'socket' => null,
                    'client_cert' => $enrolled['certificate'],
                    'client_key' => $enrolled['key'],
                    'verify' => false,
                    'is_active' => false,
                    'sort' => (int) (Cluster::query()->max('sort') ?? 0) + 1,
                ]);

                Notification::make()
                    ->title("Connected to {$enrolled['server_name']}")
                    ->body('Added as "'.$data['label'].'". Make it active from the list to view it.')
                    ->success()
                    ->send();
            });
    }

    /** The copy-paste trust command, rebuilt live from the name + project fields. */
    /** The copy-paste trust command, rebuilt live from the name + project fields.
     *  Uses inline styles + an SVG icon — Tailwind classes inside a runtime
     *  HtmlString are never compiled, so utility classes would not apply. */
    protected function trustCommandHtml(?string $label, ?string $project): HtmlString
    {
        $slug = Str::slug((string) $label);
        $name = 'kixctl'.($slug !== '' ? '-'.$slug : '');
        $project = trim((string) $project) !== '' ? trim((string) $project) : 'default';
        $cmd = e("incus config trust add {$name} --restricted --projects {$project}");

        $box = 'flex:1;min-width:0;overflow-x:auto;white-space:nowrap;padding:0.5rem 0.75rem;border-radius:0.5rem;background:rgba(120,120,120,0.14);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:0.8rem;line-height:1.25rem;color:inherit;';
        $btn = 'flex:none;display:inline-flex;align-items:center;justify-content:center;width:2.25rem;border-radius:0.5rem;border:1px solid rgba(120,120,120,0.4);background:transparent;color:inherit;cursor:pointer;';
        $clip = '<svg x-show="!copied" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612a.75.75 0 0 1-.75.75H9a.75.75 0 0 1-.75-.75c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.2 48.2 0 0 1 1.927-.184"/></svg>';
        $check = '<svg x-show="copied" x-cloak xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#059669" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>';

        return new HtmlString(
            '<div x-data="{copied:false}" style="display:flex;align-items:stretch;gap:0.5rem;">'
            .'<code x-ref="cmd" style="'.$box.'">'.$cmd.'</code>'
            .'<button type="button" title="Copy" style="'.$btn.'" '
            .'x-on:click="navigator.clipboard.writeText($refs.cmd.innerText);copied=true;setTimeout(()=>copied=false,1500)">'
            .$clip.$check.'</button></div>'
        );
    }

    protected function uniqueClusterKey(string $base): string
    {
        $key = $base;
        $i = 2;
        while (Cluster::query()->where('key', $key)->exists()) {
            $key = $base.'-'.$i++;
        }

        return $key;
    }

    /** Turn raw transport/Incus errors into something a human can act on. */
    protected function humanizeEnrollError(string $raw): string
    {
        $r = strtolower($raw);

        return match (true) {
            str_contains($r, 'curl error 7'), str_contains($r, 'curl error 28'),
            str_contains($r, 'failed to connect'), str_contains($r, 'connection refused'),
            str_contains($r, 'timed out'), str_contains($r, 'could not resolve')
                => 'Could not reach the cluster at that address. Check the address and that Incus is listening for HTTPS there.',
            str_contains($r, 'no matching'), str_contains($r, 'not found'), str_contains($r, 'token')
                => 'That token was not accepted — it may be expired or already used. Generate a fresh one on your cluster and paste it in.',
            str_contains($r, 'did not accept'), str_contains($r, 'not trusted')
                => 'The cluster registered the certificate but did not accept it. Check the project you entered matches the token.',
            default => 'Could not connect to the cluster: '.$raw,
        };
    }
}
