<?php

namespace App\Livewire;

use App\Jobs\StreamInstanceCreate;
use App\Models\User;
use App\Services\Incus\ClusterRegistry;
use App\Services\Incus\ImageCatalog;
use App\Services\Incus\IncusClient;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class CreateInstanceForm extends Component
{
    public bool $open = false;
    public bool $showAdvanced = false;
    public string $clusterKey = '';
    public array $nodeOptions = [];
    public array $profileOptions = [];
    public string $name = '';
    public string $type = 'container';
    public string $imageScope = 'remote';
    public string $arch = 'amd64';
    public string $imageAlias = '';
    public string $imageCustom = '';
    public bool $useCustomImage = false;
    public array $catalog = [];
    public array $archOptions = [];
    public string $target = '';
    public array $profiles = ['power'];
    public string $limitsCpu = '';
    public string $limitsMemory = '';
    public bool $bootAutostart = true;
    public bool $securityNesting = false;
    public string $description = '';
    public string $rawConfig = '';
    public bool $startNow = true;
    public bool $creating = false;
    public string $createToken = '';

    public function mount(): void
    {
        $registry = app(ClusterRegistry::class);
        $incus = app(IncusClient::class);
        $cluster = collect($registry->all())->first();

        if ($cluster) {
            $this->clusterKey = $cluster->key;
            try {
                foreach ($incus->members($cluster) as $m) {
                    $this->nodeOptions[$m['name']] = $m['name'];
                }
            } catch (\Throwable $_e) {
                // cluster unreachable
            }
            try {
                foreach ($incus->profiles($cluster) as $p) {
                    $this->profileOptions[$p] = $p;
                }
            } catch (\Throwable $_e) {
                // ignore
            }
        }

        if (empty($this->profileOptions)) {
            $this->profileOptions = ['default' => 'default', 'power' => 'power'];
        }
        $this->target = array_key_first($this->nodeOptions) ?: '';

        $this->loadCatalog();
    }

    public function loadCatalog(bool $refresh = false): void
    {
        $this->catalog = app(ImageCatalog::class)->all($refresh);
        $this->archOptions = app(ImageCatalog::class)->architectures();

        if (! in_array($this->arch, $this->archOptions, true)) {
            $this->arch = in_array('amd64', $this->archOptions, true) ? 'amd64' : ($this->archOptions[0] ?? 'amd64');
        }
    }

    public function getImageOptionsProperty(): array
    {
        if (empty($this->catalog)) {
            return [
                'debian/12' => __('instances.create.fallback_debian'),
                'ubuntu/24.04' => __('instances.create.fallback_ubuntu'),
                'alpine/3.22' => __('instances.create.fallback_alpine'),
                'fedora/42' => __('instances.create.fallback_fedora'),
            ];
        }

        $wantVm = $this->type === 'virtual-machine';

        return collect($this->catalog)
            ->filter(fn ($img) => $img['arch'] === $this->arch)
            ->filter(fn ($img) => $wantVm ? $img['vm'] : $img['container'])
            ->mapWithKeys(fn ($img) => [$img['alias'] => $img['label'].' — '.$img['alias']])
            ->all();
    }

    public function getImageListProperty(): array
    {
        if (empty($this->catalog)) {
            return [
                ['alias' => 'debian/12', 'label' => __('instances.create.fallback_debian'), 'sub' => 'debian/12'],
                ['alias' => 'ubuntu/24.04', 'label' => __('instances.create.fallback_ubuntu'), 'sub' => 'ubuntu/24.04'],
                ['alias' => 'alpine/3.22', 'label' => __('instances.create.fallback_alpine'), 'sub' => 'alpine/3.22'],
                ['alias' => 'fedora/42', 'label' => __('instances.create.fallback_fedora'), 'sub' => 'fedora/42'],
            ];
        }

        $wantVm = $this->type === 'virtual-machine';

        return collect($this->catalog)
            ->filter(fn ($img) => $img['arch'] === $this->arch)
            ->filter(fn ($img) => $wantVm ? $img['vm'] : $img['container'])
            ->map(fn ($img) => [
                'alias' => $img['alias'],
                'label' => $img['label'],
                'sub' => $img['alias'],
            ])
            ->values()
            ->all();
    }

    public function toggleProfile(string $p): void
    {
        $i = array_search($p, $this->profiles, true);
        if ($i === false) {
            $this->profiles[] = $p;
        } else {
            unset($this->profiles[$i]);
            $this->profiles = array_values($this->profiles);
        }
    }

    protected function userCan(string $permission): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        return $user?->can($permission) ?? false;
    }

    public function canCreate(): bool
    {
        return $this->userCan('instance.create');
    }

    public function create(): void
    {
        if (! $this->userCan('instance.create')) {
            Notification::make()
                ->title(__('common.notifications.unauthorized_title'))
                ->body(__('instances.notifications.unauthorized_create'))
                ->danger()
                ->send();
            return;
        }

        $this->validate([
            'name' => ['required', 'max:63', 'regex:/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/'],
            'target' => ['required'],
            'profiles' => ['required', 'array', 'min:1'],
        ], [
            'name.regex' => __('instances.validation.name_regex'),
            'profiles.min' => __('instances.validation.profiles_min'),
        ]);

        $registry = app(ClusterRegistry::class);
        $cluster = $registry->find($this->clusterKey) ?? collect($registry->all())->first();

        if (! $cluster) {
            Notification::make()->title(__('clusters.notifications.none_available'))->danger()->send();
            return;
        }

        $alias = $this->useCustomImage ? trim($this->imageCustom) : trim($this->imageAlias);
        if ($alias === '') {
            Notification::make()->title(__('instances.notifications.image_required_title'))->body(__('instances.notifications.image_required_body'))->danger()->send();
            return;
        }

        $config = [];
        if ($this->limitsCpu !== '') {
            $config['limits.cpu'] = $this->limitsCpu;
        }
        if ($this->limitsMemory !== '') {
            $config['limits.memory'] = $this->limitsMemory;
        }
        $config['boot.autostart'] = $this->bootAutostart ? 'true' : 'false';
        if ($this->type === 'container' && $this->securityNesting) {
            $config['security.nesting'] = 'true';
        }
        foreach (preg_split('/\r?\n/', $this->rawConfig) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k !== '') {
                $config[$k] = $v;
            }
        }

        $source = $this->imageScope === 'remote'
            ? [
                'type' => 'image',
                'mode' => 'pull',
                'server' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'alias' => $alias,
            ]
            : [
                'type' => 'image',
                'alias' => $alias,
            ];

        $payload = [
            'name' => $this->name,
            'type' => $this->type,
            'source' => $source,
            'profiles' => array_values($this->profiles),
            'config' => $config,
        ];
        if ($this->description !== '') {
            $payload['description'] = $this->description;
        }

        $token = (string) Str::random(24);
        $this->createToken = $token;
        $this->creating = true;

        StreamInstanceCreate::dispatch(
            $token,
            $cluster->key,
            $payload,
            $this->target,
            Auth::id(),
            $this->startNow,
        );
    }

    public function resetCreate(): void
    {
        $this->creating = false;
        $this->createToken = '';
        $this->reset(['name', 'imageCustom', 'useCustomImage', 'limitsCpu', 'limitsMemory', 'description', 'rawConfig', 'securityNesting']);
    }

    public function render()
    {
        return view('livewire.create-instance-form');
    }
}
