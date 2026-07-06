{
  description = "kixctl — Laravel + Livewire + Filament control plane over an Incus cluster";

  inputs = {
    # Unstable, for a current PHP 8.4. If you'd rather share your system's
    # store closure, pin this to your machine's channel (check `nixos-version`),
    # e.g. "github:NixOS/nixpkgs/nixos-XX.YY".
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
  };

  outputs =
    { self, nixpkgs }:
    let
      forAllSystems =
        f:
        nixpkgs.lib.genAttrs [ "x86_64-linux" "aarch64-linux" ] (
          system: f (import nixpkgs { inherit system; })
        );
    in
    {
      devShells = forAllSystems (
        pkgs:
        let
          # php84 already ships pdo_pgsql, pgsql, pcntl, posix, gd, intl, zip,
          # sodium, mbstring, curl, dom, openssl, sockets, tokenizer, fileinfo...
          # curl is what the IncusClient uses to hit the unix socket; pcntl/posix
          # /sockets are what Reverb will want later. Only phpredis is added
          # (native Redis client, wire-compatible with your Valkey).
          php = pkgs.php84.buildEnv {
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
          default = pkgs.mkShell {
            name = "kixctl-dev";

            packages = [
              php
              pkgs.php84Packages.composer # composer, pinned to this php
              pkgs.nodejs_22 # Vite assets for Livewire & Filament custom themes
              pkgs.postgresql_17 # psql client -> postgres-nixos (.46)
              pkgs.valkey # valkey-cli (Redis-wire compatible)
              pkgs.incus # incus CLI -> local admin socket (powerhouse is a member)
              pkgs.jq # poke the Incus REST API / JSON by hand

              # --- Closed Rust services (backup / placement / orchestration).
              # Uncomment when you `cargo init` the first one (Phase 3+), not before.
              # pkgs.rustc
              # pkgs.cargo
              # pkgs.rust-analyzer
              # pkgs.clippy
              # pkgs.rustfmt
            ];

            # Toolchain-level convenience ONLY — never secrets, never app config.
            # These let a bare `psql`/`valkey-cli` connect without flags. They do
            # NOT collide with Laravel: the app reads DB_HOST from .env, which is
            # a separate variable from PGHOST. Secrets live in .env (gitignored)
            # or sops; the DB password belongs in ~/.pgpass, never here.
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
