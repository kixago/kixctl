# NixOS module that runs the kixctl control-plane panel:
#   - php-fpm pool serving public/index.php (using the redis-extended PHP the
#     app derivation was built with, via package.phpPackage),
#   - Caddy in front, with an internal-CA cert by default (tls internal),
#   - a bundled Postgres 18 and Valkey the appliance owns,
#   - a `kixctl-setup` oneshot that runs the env-dependent artisan steps
#     (migrate, config/view/event cache, filament:optimize) and seeds the first
#     super_admin BEFORE php-fpm comes up, generating APP_KEY on first boot.
#
# Background workers (Horizon, Reverb, scheduler) are slice 2b — deliberately
# not here yet, so this stays a single provable step: the panel serves and you
# can log in.
{
  config,
  lib,
  pkgs,
  ...
}:
let
  cfg = config.services.kixctl;
  inherit (lib) mkEnableOption mkOption mkIf types;

  # artisan always invoked through the app's own PHP, so the store shebang
  # doesn't matter and the extension set is exactly what the app was built with.
  php = "${cfg.package.phpPackage}/bin/php";
  artisan = "${php} ${cfg.package}/artisan";

  socket = config.services.phpfpm.pools.kixctl.socket;

  # Config baked into the store — NEVER secrets. APP_KEY (and future SOPS
  # material) are layered on at runtime through a second, state-dir env file.
  baseEnv = pkgs.writeText "kixctl-base.env" ''
    APP_NAME=Kixctl
    APP_ENV=production
    APP_DEBUG=false
    APP_URL=${cfg.appUrl}

    LOG_CHANNEL=stderr
    LOG_LEVEL=warning

    DB_CONNECTION=pgsql
    DB_HOST=/run/postgresql
    DB_DATABASE=${cfg.user}
    DB_USERNAME=${cfg.user}

    REDIS_CLIENT=phpredis
    REDIS_HOST=127.0.0.1
    REDIS_PORT=6379

    CACHE_STORE=redis
    SESSION_DRIVER=redis
    QUEUE_CONNECTION=redis

    KIXCTL_ADMIN_EMAIL=${cfg.seedAdmin.email}
    KIXCTL_ADMIN_PASSWORD=${cfg.seedAdmin.password}
  '';

  secretsEnv = "${cfg.stateDir}/secrets.env";

  # Runs before php-fpm; idempotent. Generates APP_KEY once, runs migrations,
  # seeds roles + the first super_admin, then caches the env-dependent config.
  setupScript = pkgs.writeShellScript "kixctl-setup" ''
    set -euo pipefail
    umask 077

    # APP_KEY is a runtime secret — generated once into the state dir, never
    # written to the Nix store. base64 of 32 random bytes is exactly Laravel's
    # AES-256 key format.
    if [ ! -f "${secretsEnv}" ]; then
      echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" > "${secretsEnv}"
    fi

    set -a
    . ${baseEnv}
    . "${secretsEnv}"
    set +a

    ${artisan} migrate --force
    ${artisan} db:seed --class=ShieldPresetSeeder --force

    # Ensure the gated super_admin role exists, then create/promote the first
    # admin from the seed creds. firstOrCreate + role guard keep it idempotent.
    ${artisan} tinker --execute='
      use Spatie\Permission\Models\Role;
      use App\Models\User;
      Role::firstOrCreate(["name" => "super_admin", "guard_name" => "web"]);
      $u = User::firstOrCreate(
        ["email" => getenv("KIXCTL_ADMIN_EMAIL")],
        ["name" => "Administrator", "password" => bcrypt(getenv("KIXCTL_ADMIN_PASSWORD"))],
      );
      if (! $u->hasRole("super_admin")) { $u->assignRole("super_admin"); }
    '

    # Cache the env-dependent config now that secrets + DB are present.
    # route:cache is intentionally omitted: routes/web.php has a closure route
    # ('/'), which route:cache rejects. Re-enable once that route is a controller.
    ${artisan} config:cache
    ${artisan} event:cache
    ${artisan} view:cache
    ${artisan} filament:optimize
  '';
in
{
  options.services.kixctl = {
    enable = mkEnableOption "the kixctl control-plane panel";

    package = mkOption {
      type = types.package;
      description = "The kixctl application derivation (self.packages.<system>.kixctl).";
    };

    hostName = mkOption {
      type = types.str;
      default = "kixctl.home.arpa";
      description = "Host name Caddy serves the panel on.";
    };

    appUrl = mkOption {
      type = types.str;
      default = "https://${cfg.hostName}";
      defaultText = "https://\${hostName}";
      description = "APP_URL Laravel uses to build absolute links.";
    };

    stateDir = mkOption {
      type = types.path;
      default = "/var/lib/kixctl";
      description = "Writable runtime state the read-only store symlinks point at.";
    };

    user = mkOption {
      type = types.str;
      default = "kixctl";
    };

    group = mkOption {
      type = types.str;
      default = "kixctl";
    };

    tls = mkOption {
      type = types.enum [
        "internal"
        "off"
      ];
      default = "internal";
      description = ''
        TLS mode. "internal" uses Caddy's local CA (default; no public ACME,
        works fully offline on a LAN). "off" serves plain HTTP for a reverse
        proxy that terminates TLS in front. Public ACME / Let's Encrypt is a
        deliberate future addition, not wired yet.
      '';
    };

    # DEV-only seed for the build-vm proof. Replaced by the first-run wizard
    # (P7-2) and SOPS-managed secrets (P7-3).
    seedAdmin = {
      email = mkOption {
        type = types.str;
        default = "admin@kixctl.local";
      };
      password = mkOption {
        type = types.str;
        default = "changeme";
      };
    };
  };

  config = mkIf cfg.enable {
    users.users.${cfg.user} = {
      isSystemUser = true;
      inherit (cfg) group;
      home = cfg.stateDir;
    };
    users.groups.${cfg.group} = { };

    # State dirs the read-only store symlinks (storage, bootstrap/cache) resolve to.
    systemd.tmpfiles.settings."10-kixctl" = {
      "${cfg.stateDir}".d = {
        inherit (cfg) user group;
        mode = "0750";
      };
      "${cfg.stateDir}/storage".d = {
        inherit (cfg) user group;
        mode = "0750";
      };
      "${cfg.stateDir}/cache".d = {
        inherit (cfg) user group;
        mode = "0750";
      };
    };

    # The appliance owns its data layer.
    services.postgresql = {
      enable = true;
      package = pkgs.postgresql_18;
      ensureDatabases = [ cfg.user ];
      ensureUsers = [
        {
          name = cfg.user;
          ensureDBOwnership = true;
        }
      ];
    };

    services.redis.servers.kixctl = {
      enable = true;
      package = pkgs.valkey;
      port = 6379;
      bind = "127.0.0.1";
    };

    # php-fpm pool serving public/index.php. clear_env=no so the systemd
    # EnvironmentFiles reach the workers; the socket is owned by caddy.
    services.phpfpm.pools.kixctl = {
      inherit (cfg) user group;
      phpPackage = cfg.package.phpPackage;
      settings = {
        "listen.owner" = "caddy";
        "listen.group" = "caddy";
        "listen.mode" = "0660";
        "clear_env" = "no";
        "pm" = "dynamic";
        "pm.max_children" = 16;
        "pm.start_servers" = 2;
        "pm.min_spare_servers" = 1;
        "pm.max_spare_servers" = 4;
        "pm.max_requests" = 500;
      };
    };

    # php-fpm workers read config from the cached config built by setup, but also
    # get the env directly (belt-and-suspenders for any uncached lookup).
    systemd.services.phpfpm-kixctl = {
      after = [ "kixctl-setup.service" ];
      requires = [ "kixctl-setup.service" ];
      serviceConfig.EnvironmentFile = [
        "${baseEnv}"
        "-${secretsEnv}"
      ];
    };

    systemd.services.kixctl-setup = {
      description = "kixctl first-boot / activation setup (migrate, seed, cache)";
      after = [
        "postgresql.service"
        "redis-kixctl.service"
      ];
      requires = [
        "postgresql.service"
        "redis-kixctl.service"
      ];
      requiredBy = [ "phpfpm-kixctl.service" ];
      before = [ "phpfpm-kixctl.service" ];
      restartTriggers = [ cfg.package ];
      serviceConfig = {
        Type = "oneshot";
        RemainAfterExit = true;
        User = cfg.user;
        Group = cfg.group;
        StateDirectory = "kixctl";
        ExecStart = setupScript;
        EnvironmentFile = [
          "${baseEnv}"
          "-${secretsEnv}"
        ];
      };
    };

    services.caddy = {
      enable = true;
      virtualHosts.${cfg.hostName}.extraConfig = ''
        root * ${cfg.package}/public
        php_fastcgi unix/${socket}
        file_server
        encode zstd gzip
      ''
      + lib.optionalString (cfg.tls == "internal") ''
        tls internal
      '';
    };
  };
}
