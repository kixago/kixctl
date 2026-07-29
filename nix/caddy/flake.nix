{
  description = "kixctl managed Caddy edge — internal reverse proxy for the apps zone, driven entirely by the admin API (Incus container)";

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

      # Where caddy's admin API listens. kixctl (on the host, which owns kixbr0)
      # renders JSON from app_routes and PATCHes it here — no site config ever
      # lives in this flake, and no config files are pushed into the container.
      # 0.0.0.0 so the host can reach it across kixbr0; the bridge is an isolated
      # auto-subnet and the firewall below only opens 2019 on it. Tighten to the
      # container's kixbr0 address if you want belt-and-suspenders.
      adminListen = "0.0.0.0:2019";

      caddyModule =
        { modulesPath, lib, ... }:
        {
          imports = [ "${modulesPath}/virtualisation/lxc-container.nix" ];

          # Caddy started from a FULL JSON config (settings), never a Caddyfile.
          # It comes up with the admin API on and ZERO http servers — an empty,
          # valid edge that does nothing until kixctl pushes routes over the API.
          # kixctl re-asserts the whole route set from Postgres on every ensure(),
          # so the container staying immutable/ephemeral is fine: a rebuild just
          # gets re-populated, exactly like coredns re-pushes its zonefile.
          services.caddy = {
            enable = true;
            settings = {
              admin = {
                listen = adminListen;
              };
              apps = {
                http = {
                  # No servers yet. kixctl adds an "apps" server (listen :80)
                  # with @id-tagged routes via the admin API. Keeping this empty
                  # (not absent) makes the starting config valid and loadable.
                  servers = { };
                };
              };
            };
          };

          networking = {
            # Inbound HTTP (the reverse-proxy edge) + the admin API. The firewall
            # is on by default under NixOS; 2019 is only reachable across the
            # isolated kixbr0 subnet.
            firewall = {
              allowedTCPPorts = [
                80
                2019
              ];
            };

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
