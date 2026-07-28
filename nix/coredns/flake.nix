{
  description = "kixctl managed CoreDNS resolver — authoritative-only for the apps zone (Incus container)";

  # Self-contained: the ONLY input is nixpkgs. The image-build outputs
  # (config.system.build.metadata + .tarball) that kixctl-build consumes come
  # from nixpkgs' lxc-container module — the same base your whole fleet
  # (common.nix) and your app images already build on. No kixctl-base needed.
  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";

  outputs =
    { self, nixpkgs }:
    {
      nixosConfigurations.coredns = nixpkgs.lib.nixosSystem {
        system = "x86_64-linux";
        modules = [
          (
            { modulesPath, lib, ... }:
            {
              imports = [ "${modulesPath}/virtualisation/lxc-container.nix" ];

              # Authoritative-only for the apps zone (no recursion — Caddy only
              # ever asks for <app>.<zone>). The `file` plugin reloads the
              # zonefile when its SOA serial changes; kixctl pushes that file and
              # bumps the serial on every deploy/cutover.
              #
              # ── ZONE lives in TWO places that must match: this server block
              #    AND the GUI "zone" field. Default is apps.internal. If you
              #    want Caddy to serve it with a real DNS-01 wildcard cert on
              #    your caddy-server, change BOTH to something under a domain you
              #    control, e.g. apps.lan.kixago.com.
              services.coredns = {
                enable = true;
                config = ''
                  apps.internal:53 {
                      file /var/lib/kixctl-dns/apps.db {
                          reload 5s
                      }
                      errors
                      log
                  }
                '';
              };

              # kixctl pushes the zonefile here (0644, root:root). CoreDNS runs
              # as a DynamicUser, so it must be world-readable — which it is.
              systemd.tmpfiles.rules = [
                "d /var/lib/kixctl-dns 0755 root root -"
              ];

              # Inbound DNS. (The firewall is on by default under NixOS.)
              networking.firewall.allowedTCPPorts = [ 53 ];
              networking.firewall.allowedUDPPorts = [ 53 ];

              # Stateless resolver — match your fleet's if you prefer.
              system.stateVersion = lib.mkDefault "24.11";
            }
          )
        ];
      };
    };
}
