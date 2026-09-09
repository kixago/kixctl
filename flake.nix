{
  description = "kixctl — Laravel + Livewire + Filament control plane over an Incus cluster";

  inputs = {
    # Unstable, for a current PHP 8.4 and the v2 composer builders.
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
  };

  outputs =
    {
      self,
      nixpkgs,
    }:
    let
      systems = [
        "x86_64-linux"
        "aarch64-linux"
      ];
      forAllSystems = f: nixpkgs.lib.genAttrs systems (system: f (import nixpkgs { inherit system; }));

      # php84 + phpredis, shared by the dev shell AND the app derivation — so the
      # toolchain that BUILDS kixctl is the same one the dev loop and the appliance
      # RUN it with. php.buildEnv re-exposes the v2 composer builders
      # (buildComposerProject2 / mkComposerVendor), so the extended php packages
      # the app directly, with no extension surprises.
      #
      # php84 already ships pdo_pgsql, pgsql, pcntl, posix, gd, intl, zip, sodium,
      # mbstring, curl, dom, openssl, sockets, tokenizer, fileinfo... curl is what
      # the IncusClient uses to hit the unix socket; pcntl/posix/sockets are what
      # Reverb wants. Only phpredis is added (native Redis client, wire-compatible
      # with your Valkey).
      mkPhp =
        pkgs:
        pkgs.php84.buildEnv {
          extensions =
            { enabled, all }:
            enabled ++ (with all; [ redis ]);
          extraConfig = ''
            memory_limit = 512M
            upload_max_filesize = 64M
            post_max_size = 64M
            ; uncomment to enable step-debugging (slower):
            ; xdebug.mode = debug
          '';
        };
    in
    {
      # The reusable service module — runs kixctl on any NixOS host.
      nixosModules.kixctl = import ./nix/kixctl-module.nix;

      # The appliance system, for `nixos-rebuild build-vm --flake .#appliance`.
      # appliance.nix now returns the shared module list; both this and the
      # appliance-qcow image below build from the same definition.
      nixosConfigurations.appliance = nixpkgs.lib.nixosSystem {
        system = "x86_64-linux";
        modules = import ./nix/appliance.nix {
          inherit self;
          system = "x86_64-linux";
        };
      };

      packages = forAllSystems (
        pkgs:
        let
          php = mkPhp pkgs;
        in
        {
          # kixctl as an immutable derivation: composer vendor + the Vite asset
          # build + Filament's published assets, laid out for php-fpm to serve
          # public/index.php. Writable state (storage, bootstrap/cache) is
          # symlinked to a runtime dataDir the NixOS module provisions — the store
          # path itself stays read-only. Mirrors the proven nixpkgs
          # Laravel-with-assets pattern (bookstack + firefly-iii).
          kixctl = php.buildComposerProject2 (finalAttrs: {
            pname = "kixctl";
            version = "0.1.0";

            src = self;

            # Our composer.json carries app-level scripts and no `version`, so skip
            # strict validation. composerNoScripts defaults true, so the
            # post-autoload-dump (package:discover / filament:upgrade) does NOT run
            # during vendoring — those are runtime / first-boot concerns.
            composerStrictValidation = false;
            vendorHash = "sha256-sq1wcQao9zRCPqNgr8EVDOb3Pgl0dDyJDA4dAF/Su7s=";

            nativeBuildInputs = [
              pkgs.nodejs_22
              pkgs.npmHooks.npmConfigHook # stages node_modules from npmDeps, offline
            ];

            npmDeps = pkgs.fetchNpmDeps {
              inherit (finalAttrs) src;
              name = "${finalAttrs.pname}-npm-deps";
              hash = "sha256-tSAGkG+IkgalEHptQvJkXwFRBs6GZhWFxpm/uL2V9zI=";
            };

            # Build the Vite assets while node_modules is present and before the
            # install hook copies the tree into $out. Fonts resolve from
            # @fontsource (node_modules), so this needs no network.
            preInstall = ''
              # Bake the (public, non-secret) Reverb app key into the browser
              # bundle so Echo can connect. Host/port/scheme stay unset — the
              # bundle falls back to the page origin at runtime. MUST match
              # REVERB_APP_KEY / reverbAppKey in nix/kixctl-module.nix.
              export VITE_REVERB_APP_KEY=kixctl-appliance-key
              npm run build
            '';

            # The project (incl. vendor) is now assembled under $out/share/php/kixctl.
            # Flatten it to $out, publish Filament's own JS/CSS into public/ (the
            # throwaway APP_KEY only lets artisan boot — nothing is encrypted and the
            # key never lands in the store), then hand writable dirs to the runtime
            # dataDir and drop node_modules from the closure.
            postInstall = ''
              chmod -R u+w $out/share
              mv $out/share/php/${finalAttrs.pname}/* $out/
              rm -rf $out/share

              export APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
              ( cd $out && php artisan filament:assets )

              rm -rf $out/node_modules $out/storage $out/bootstrap/cache
              ln -s ${finalAttrs.dataDir}/storage $out/storage
              ln -s ${finalAttrs.dataDir}/cache $out/bootstrap/cache
            '';

            dataDir = "/var/lib/kixctl";

            passthru.phpPackage = php;

            meta = {
              description = "kixctl control plane — packaged app derivation (appliance foundation)";
              homepage = "https://kixctl.com";
              license = nixpkgs.lib.licenses.agpl3Plus;
              platforms = nixpkgs.lib.platforms.linux;
            };
          });

          default = self.packages.${pkgs.stdenv.hostPlatform.system}.kixctl;
        }
        // nixpkgs.lib.optionalAttrs (pkgs.stdenv.hostPlatform.system == "x86_64-linux") {
          appliance-qcow =
            (nixpkgs.lib.nixosSystem {
              system = "x86_64-linux";
              modules =
                (import ./nix/appliance.nix {
                  inherit self;
                  system = "x86_64-linux";
                })
                ++ [
                  (
                    { lib, modulesPath, ... }:
                    {
                      # systemd-boot in the BASE config, so the qemu-efi variant
                      # lays down a real ESP (image.modules is too late to affect
                      # the partition table). Dev-only login + serial console for
                      # the test image. nixosConfigurations.appliance stays
                      # bootloader-free, so build-vm still direct-boots the kernel.
                      imports = [ "${modulesPath}/profiles/qemu-guest.nix" ];
                      boot = {
                        kernelParams = [
                          "console=ttyS0,115200"
                          "console=tty0"
                        ];
                        growPartition = true;
                        loader = {
                          systemd-boot.enable = true;
                          efi.canTouchEfiVariables = false;
                          grub.enable = lib.mkForce false;
                          timeout = lib.mkDefault 3;
                        };
                      };
                      fileSystems."/".autoResize = true;
                      users.users.root.initialPassword = "root";
                      services.openssh.enable = true;
                      services.openssh.settings.PermitRootLogin = "yes";
                    }
                  )
                ];
            }).config.system.build.images.qemu-efi;
        }
      );

      devShells = forAllSystems (
        pkgs:
        let
          php = mkPhp pkgs;
        in
        {
          default = pkgs.mkShell {
            name = "kixctl-dev";

            packages = [
              php
              php.packages.composer # composer, pinned to this php
              pkgs.nodejs_22 # Vite assets for Livewire & Filament custom themes
              pkgs.postgresql_17 # psql client -> postgres-nixos (.46)
              pkgs.valkey # valkey-cli (Redis-wire compatible)
              pkgs.incus # incus CLI -> local admin socket (powerhouse is a member)
              pkgs.jq # poke the Incus REST API / JSON by hand
            ];

            # Toolchain-level convenience ONLY — never secrets, never app config.
            env = {
              PGHOST = "192.168.2.46";
              PGPORT = "5432";
            };

            shellHook = ''
              echo "kixctl: php $(php -r 'echo PHP_VERSION;')  |  node $(node --version)  |  $(composer --version 2>/dev/null | head -1)"
              echo "data:   psql -h ''${PGHOST}  |  valkey-cli -h ''${PGHOST}"
              echo "incus:  local admin socket — 'incus cluster list' should just work (powerhouse is a cluster member)"
            '';
          };
        }
      );
    };
}
