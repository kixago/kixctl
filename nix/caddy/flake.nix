{
  description = "kixctl managed Caddy edge — internal reverse proxy for the apps zone, driven by a kixctl-pushed, watched Caddyfile (Incus container)";

  # Self-contained: the ONLY input is nixpkgs. The image-build outputs
  # (config.system.build.metadata + .tarball) that kixctl-build consumes come
  # from nixpkgs' lxc-container module — the same base coredns and your app
  # images already build on. No kixctl-base needed. flake.lock is cloned from
  # nix/coredns so both owned images pin the exact same nixpkgs.
  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";

  outputs =
    { nixpkgs, ... }:
    let
      system = "x86_64-linux";

      # kixctl pushes the rendered Caddyfile here via the Incus files API (the
      # exact channel coredns uses for its zonefile — a local admin-socket write,
      # never host->bridge HTTP, which the smoke test proved unreliable). caddy
      # runs with --watch and graceful-reloads on every push. No site config
      # lives in this flake; every route is runtime data rendered from app_routes.
      configPath = "/var/lib/kixctl-caddy/Caddyfile";

      caddyModule =
        { modulesPath, lib, pkgs, ... }:
        let
          # A valid placeholder so caddy starts clean before kixctl's first push,
          # and after a rebuild until ensure() re-asserts the real routes (kixctl
          # re-renders from Postgres on every ensure, exactly like the zonefile).
          seedCaddyfile = pkgs.writeText "kixctl-caddy-seed" ''
            {
            	auto_https off
            }

            :80 {
            	respond "kixctl-caddy: no routes yet" 200
            }
          '';
        in
        {
          imports = [ "${modulesPath}/virtualisation/lxc-container.nix" ];

          # Use services.caddy for the user, StateDirectory and the :80 ambient
          # capability + hardening — but drive it from the pushed, watched file
          # instead of a nix-baked config. The module's own generated config is
          # never used; ExecStart/ExecReload below override it.
          services.caddy.enable = true;

          # Seed the writable config dir + a valid placeholder Caddyfile. `C`
          # copies only if absent, so a config kixctl already pushed survives a
          # reboot; a fresh container gets the placeholder.
          systemd.tmpfiles.rules = [
            "d /var/lib/kixctl-caddy 0755 caddy caddy -"
            "C ${configPath} 0644 caddy caddy - ${seedCaddyfile}"
          ];

          # Run caddy from the pushed Caddyfile with --watch (zero-downtime
          # graceful reload on change). This is the whole "push and reload on the
          # fly" mechanism — no admin API exposure, no exec primitive needed.
          #
          # The caddy module ships its unit as a package, so our override lands as
          # a systemd DROP-IN — where a bare `ExecStart=` APPENDS to the module's
          # (giving two ExecStart under Type=notify => "bad-setting", unit dead).
          # The leading empty-string element is the systemd reset: it clears the
          # inherited ExecStart/ExecReload first, then sets ours. Result: exactly
          # one command each.
          systemd.services.caddy.serviceConfig = {
            ExecStart = lib.mkForce [
              ""
              "${pkgs.caddy}/bin/caddy run --config ${configPath} --adapter caddyfile --watch"
            ];
            ExecReload = lib.mkForce [
              ""
              "${pkgs.caddy}/bin/caddy reload --config ${configPath} --adapter caddyfile --force"
            ];
          };

          networking = {
            # Inbound HTTP only — the reverse-proxy edge. The admin API stays on
            # its localhost default (unexposed); kixctl never needs it for the
            # managed caddy. The firewall is on by default under NixOS.
            firewall.allowedTCPPorts = [ 80 ];

            # systemd-networkd ONLY. The lxc-container base ships dhcpcd and does
            # NOT enable networkd — so we tear out dhcpcd and force networkd on,
            # or eth0 never comes up. kixbr0 serves DHCP; this makes the container
            # request it via networkd and nothing else. (Identical to coredns.)
            useNetworkd = true;
            useDHCP = false;
            dhcpcd.enable = false;
            useHostResolvConf = false;
          };

          # Force networkd into the image (useNetworkd alone doesn't survive the
          # lxc-container base). Match eth0 generically — never a host-specific
          # interface name.
          systemd.network = {
            enable = true;
            wait-online.enable = false;
            networks."10-eth0" = {
              matchConfig.Name = "eth0";
              networkConfig.DHCP = "ipv4";
            };
          };

          # Stateless edge — match your fleet's stateVersion if you prefer.
          system.stateVersion = lib.mkDefault "26.11";
        };
    in
    {
      nixosConfigurations.caddy = nixpkgs.lib.nixosSystem {
        inherit system;
        modules = [ caddyModule ];
      };
    };
}
