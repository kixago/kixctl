# NixOS module that runs the kixctl control-plane panel:
#   - php-fpm pool serving public/index.php (using the redis-extended PHP the
#     app derivation was built with, via package.phpPackage),
#   - Caddy in front, with an internal-CA cert by default (tls internal),
#     reverse-proxying the Reverb WebSocket path to the local Reverb server,
#   - a bundled Postgres 18 and Valkey the appliance owns,
#   - the background workers (Reverb, Horizon, scheduler) as their own units,
#   - a `kixctl-setup` oneshot that runs the env-dependent artisan steps
#     (migrate, config/view/event cache, filament:optimize) and seeds the first
#     super_admin BEFORE php-fpm comes up, generating APP_KEY + the Reverb app
#     secret on first boot.
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

  # The Reverb app id + key are NOT secrets: the key is shipped in the browser
  # bundle by design (Pusher-protocol clients present it to open a connection).
  # Only the app SECRET is sensitive, and it is generated once at first boot.
  #
  # reverbAppKey MUST match VITE_REVERB_APP_KEY baked into the kixctl derivation
  # in flake.nix (preInstall, before `npm run build`) — the browser presents the
  # baked key and Reverb validates it against this one. Change one, change both.
  reverbAppId = "kixctl-appliance";
  reverbAppKey = "kixctl-appliance-key";

  # Config baked into the store — NEVER secrets. APP_KEY, the Reverb app secret
  # (and future SOPS material) are layered on at runtime through a second,
  # state-dir env file written on first boot.
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
    # Horizon's incus supervisor allows a job up to 1800s; the queue's retry
    # window must sit above that or a long image import gets re-released as
    # stalled while it is still running.
    REDIS_QUEUE_RETRY_AFTER=2000

    BROADCAST_CONNECTION=reverb

    # Self-hosted Reverb, entirely on this box. The app pushes events to Reverb
    # over plain localhost; the browser reaches it through Caddy, which
    # reverse-proxies /app/* to 127.0.0.1:8080. The app secret is filled in at
    # runtime from secrets.env (generated on first boot).
    REVERB_APP_ID=${reverbAppId}
    REVERB_APP_KEY=${reverbAppKey}
    REVERB_HOST=127.0.0.1
    REVERB_PORT=8080
    REVERB_SCHEME=http

    KIXCTL_ADMIN_EMAIL=${cfg.seedAdmin.email}
    KIXCTL_ADMIN_PASSWORD=${cfg.seedAdmin.password}
  '';

  secretsEnv = "${cfg.stateDir}/secrets.env";

  # Runs before php-fpm; idempotent. Generates the runtime secrets once, runs
  # migrations, seeds roles + the first super_admin, then caches the
  # env-dependent config.
  setupScript = pkgs.writeShellScript "kixctl-setup" ''
    set -euo pipefail
    umask 077

    # Clear generated caches from any previous build first. Laravel's cached
    # config bakes absolute /nix/store paths, so a stale cache left by an older
    # build poisons the framework boot before any artisan command can run. That
    # rules out `artisan optimize:clear` (it can't boot through a poisoned cache
    # itself), so the clear must happen at the filesystem level.
    rm -rf "${cfg.stateDir}/cache"/* "${cfg.stateDir}/storage/framework/views"/* 2>/dev/null || true

    # Runtime secrets — generated once into the state dir, never written to the
    # Nix store. APP_KEY is base64 of 32 random bytes (Laravel's AES-256 format).
    # The Reverb app secret is an arbitrary shared secret; alphanumeric keeps it
    # safe in an env file and a URL. Each is guarded independently so an existing
    # appliance that predates the Reverb secret gains it on upgrade without
    # rotating APP_KEY (which would invalidate every stored encrypted value).
    if [ ! -f "${secretsEnv}" ]; then
      echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" > "${secretsEnv}"
    fi
    if ! grep -q '^REVERB_APP_SECRET=' "${secretsEnv}"; then
      secret="$(head -c 48 /dev/urandom | base64 | tr -dc 'a-zA-Z0-9')"
      echo "REVERB_APP_SECRET=''${secret:0:40}" >> "${secretsEnv}"
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

    # Cache the env-dependent config now that secrets + DB are present. The
    # Reverb app secret is read via env() inside config/reverb.php and
    # config/broadcasting.php, so it is captured into the cached config here.
    ${artisan} config:cache
    ${artisan} route:cache
    ${artisan} event:cache
    ${artisan} view:cache
    ${artisan} filament:optimize
  '';

  # Every long-running / scheduled unit runs as the kixctl user, reads the same
  # env, and waits on the setup oneshot (cached config + secrets) plus its data
  # dependencies. Factored out so the three unit definitions stay honest copies.
  workerServiceConfig = {
    User = cfg.user;
    Group = cfg.group;
    EnvironmentFile = [
      "${baseEnv}"
      "-${secretsEnv}"
    ];
  };
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

    # State dirs the read-only store symlinks (storage, bootstrap/cache) resolve
    # to. Laravel needs the full storage/framework/{cache,sessions,views},
    # storage/logs and storage/app/public subtree to exist, or Blade refuses to
    # boot ("Please provide a valid cache path"), so create the whole skeleton.
    systemd.tmpfiles.settings."10-kixctl" =
      let
        dir.d = {
          inherit (cfg) user group;
          mode = "0750";
        };
      in
      lib.genAttrs [
        "${cfg.stateDir}"
        "${cfg.stateDir}/cache"
        "${cfg.stateDir}/storage"
        "${cfg.stateDir}/storage/app"
        "${cfg.stateDir}/storage/app/public"
        "${cfg.stateDir}/storage/framework"
        "${cfg.stateDir}/storage/framework/cache"
        "${cfg.stateDir}/storage/framework/sessions"
        "${cfg.stateDir}/storage/framework/views"
        "${cfg.stateDir}/storage/logs"
      ] (_: dir);

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

    # Valkey via the redis module: `package` is a top-level option shared by all
    # servers (there is no per-server `package`). Valkey's serverBin passthru
    # makes the unit run valkey-server rather than redis-server.
    services.redis = {
      package = pkgs.valkey;
      servers.kixctl = {
        enable = true;
        port = 6379;
        bind = "127.0.0.1";
      };
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
        "postgresql.target"
        "redis-kixctl.service"
      ];
      wants = [
        "postgresql.target"
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

    # Reverb — the WebSocket server. Binds localhost only; Caddy is the sole
    # front door (it reverse-proxies /app/* here). --host/--port set the bind
    # explicitly, independent of cache state; the app id/key/secret come from the
    # cached config seeded by setup.
    systemd.services.kixctl-reverb = {
      description = "kixctl Reverb WebSocket server";
      after = [
        "kixctl-setup.service"
        "redis-kixctl.service"
      ];
      requires = [ "kixctl-setup.service" ];
      wants = [ "redis-kixctl.service" ];
      wantedBy = [ "multi-user.target" ];
      serviceConfig = workerServiceConfig // {
        ExecStart = "${artisan} reverb:start --host=127.0.0.1 --port=8080";
        Restart = "on-failure";
        RestartSec = 3;
      };
    };

    # Horizon — the queue supervisor that runs the deploy/build jobs and fires
    # the broadcasts. It handles SIGTERM gracefully: on stop it stops pulling new
    # jobs and lets in-flight ones finish (up to each supervisor's `timeout`,
    # 1800s for the incus queue). TimeoutStopSec is set above that so a
    # switch/reboot never kills a running image import mid-flight; lower it if a
    # bounded shutdown ever matters more than never interrupting a deploy.
    systemd.services.kixctl-horizon = {
      description = "kixctl Horizon queue supervisor";
      after = [
        "kixctl-setup.service"
        "redis-kixctl.service"
        "postgresql.target"
      ];
      requires = [ "kixctl-setup.service" ];
      wants = [
        "redis-kixctl.service"
        "postgresql.target"
      ];
      wantedBy = [ "multi-user.target" ];
      serviceConfig = workerServiceConfig // {
        ExecStart = "${artisan} horizon";
        Restart = "on-failure";
        RestartSec = 3;
        TimeoutStopSec = 1810;
      };
    };

    # Scheduler — the minute tick that drives the commit poller (the webhook's
    # safety net). A oneshot fired by a systemd timer, the appliance equivalent
    # of the cron `schedule:run` line.
    systemd.services.kixctl-scheduler = {
      description = "kixctl scheduled tasks (php artisan schedule:run)";
      after = [
        "kixctl-setup.service"
        "redis-kixctl.service"
        "postgresql.target"
      ];
      requires = [ "kixctl-setup.service" ];
      serviceConfig = workerServiceConfig // {
        Type = "oneshot";
        ExecStart = "${artisan} schedule:run";
      };
    };

    systemd.timers.kixctl-scheduler = {
      description = "Run kixctl scheduled tasks every minute";
      wantedBy = [ "timers.target" ];
      timerConfig = {
        OnCalendar = "minutely";
        Persistent = false;
        AccuracySec = "1s";
      };
    };

    services.caddy = {
      enable = true;
      virtualHosts.${cfg.hostName}.extraConfig = ''
        encode zstd gzip

        # Reverb's WebSocket + HTTP API. The browser opens wss to /app/<key>
        # here and Caddy tunnels it to the local Reverb server; kept ahead of the
        # php-fpm handler so these paths never reach PHP.
        @reverb path /app/* /apps/*
        handle @reverb {
          reverse_proxy 127.0.0.1:8080
        }

        # Everything else is the Laravel front controller.
        handle {
          root * ${cfg.package}/public
          php_fastcgi unix/${socket}
          file_server
        }
      ''
      + lib.optionalString (cfg.tls == "internal") ''
        tls internal
      '';
    };
  };
}
