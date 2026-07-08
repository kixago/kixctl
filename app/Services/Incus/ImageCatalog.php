<?php

namespace App\Services\Incus;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ImageCatalog
{
    protected string $streamsUrl = 'https://images.linuxcontainers.org/streams/v1/images.json';

    protected int $ttl = 21600; // 6 hours

    /**
     * Parsed catalog, cached. Returns a flat list of usable images:
     * [ ['alias','os','release','variant','arch','container','vm','label'], ... ]
     */
    public function all(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget('incus.image_catalog');
        }

        return Cache::remember('incus.image_catalog', $this->ttl, function () {
            try {
                $response = Http::timeout(15)->get($this->streamsUrl);
                $response->throw();
                return $this->parse($response->json('products', []));
            } catch (\Throwable $e) {
                return []; // caller falls back to a curated shortlist
            }
        });
    }

    protected function parse(array $products): array
    {
        $out = [];

        foreach ($products as $product) {
            $alias = $product['aliases'] ?? null;
            if (! $alias) {
                continue; // no usable alias
            }
            // aliases can be comma-separated; take the first (canonical) one.
            $alias = trim(explode(',', $alias)[0]);

            $versions = $product['versions'] ?? [];
            if (empty($versions)) {
                continue;
            }

            // Newest version = highest timestamp key.
            $latestKey = collect(array_keys($versions))->sort()->last();
            $items = $versions[$latestKey]['items'] ?? [];

            $hasContainer = false;
            $hasVm = false;
            foreach ($items as $item) {
                $ftype = $item['ftype'] ?? '';
                if (in_array($ftype, ['squashfs', 'root.tar.xz', 'root.squashfs'], true)) {
                    $hasContainer = true;
                }
                if (in_array($ftype, ['disk-kvm.img', 'disk.qcow2'], true)) {
                    $hasVm = true;
                }
                // combined_* hashes on the metadata item also signal capability
                if (isset($item['combined_squashfs_sha256']) || isset($item['combined_rootxz_sha256'])) {
                    $hasContainer = true;
                }
                if (isset($item['combined_disk-kvm-img_sha256'])) {
                    $hasVm = true;
                }
            }

            if (! $hasContainer && ! $hasVm) {
                continue;
            }

            $os = $product['os'] ?? '';
            $release = (string) ($product['release_title'] ?? $product['release'] ?? '');
            $variant = $product['variant'] ?? 'default';
            $arch = $product['arch'] ?? '';

            $out[] = [
                'alias'     => $alias,
                'os'        => $os,
                'release'   => $release,
                'variant'   => $variant,
                'arch'      => $arch,
                'container' => $hasContainer,
                'vm'        => $hasVm,
                'label'     => trim("{$os} {$release}" . ($variant !== 'default' ? " ({$variant})" : '')),
            ];
        }

        // Sort: os, then release desc (newest first), then variant.
        usort($out, function ($a, $b) {
            return [$a['os'], $b['release'], $a['variant']] <=> [$b['os'], $a['release'], $b['variant']];
        });

        return $out;
    }

    /** Architectures present in the catalog, for the arch selector. */
    public function architectures(): array
    {
        $arches = collect($this->all())->pluck('arch')->unique()->filter()->values()->all();
        sort($arches);
        return $arches ?: ['amd64'];
    }
}
