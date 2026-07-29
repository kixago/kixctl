{
  description = "kixctl managed CoreDNS resolver — authoritative-only for the apps zone (Incus container)";

  # Self-contained: the ONLY input is nixpkgs. The image-build outputs
  # (config.system.build.metadata + .tarball) that kixctl-build consumes come
  # from nixpkgs' lxc-container module — the same base your whole fleet
  # (common.nix) and your app images already build on. No kixctl-base needed.
  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";

  outputs =
    { nixpkgs, ... }:
    let
      system = "x86_64-linux";

      # The apps zone. Authoritative-only (no recursion — Caddy only ever asks
      # for <app>.<zone>). This must match the GUI "zone" field. Default is
      # apps.internal; to have caddy-server serve it with a real DNS-01 wildcard
      # cert, change this AND the GUI field to something under a domain you own,
      # e.g. apps.lan.kixago.com.
      zone = "apps.internal";

      # Where kixctl pushes the zonefile. CoreDNS runs as a DynamicUser, so the
      # directory is world-readable and the file lands 0644 root:root.
      zoneDir = "/var/lib/kixctl-dns";
      zoneFile = "${zoneDir}/apps.db";

      corednsModule =
        { modulesPath, lib, ... }:
        {
          imports = [ "${modulesPath}/virtualisation/lxc-container.nix" ];

          # Authoritative apps zone. The `file` plugin reloads when the SOA
          # serial changes; kixctl pushes the zonefile and bumps the serial on
          # every deploy/cutover.
          services.coredns = {
            enable = true;
            config = ''
              ${zone}:53 {
                  file ${zoneFile} {
                      reload 5s
                  }
                  errors
                  log
              }
            '';
          };

          # kixctl pushes the zonefile into this directory.
          systemd.tmpfiles.rules = [
            "d ${zoneDir} 0755 root root -"
          ];

          networking = {
            # Inbound DNS. (The firewall is on by default under NixOS.)
            firewall = {
              allowedTCPPorts = [ 53 ];
              allowedUDPPorts = [ 53 ];
            };

            # systemd-networkd ONLY. The lxc-container base ships dhcpcd as its
            # DHCP client and does NOT enable networkd — so we must both tear out
            # dhcpcd and force networkd on, or nothing brings eth0 up (the base
            # otherwise wins and eth0 stays unmanaged). kixbr0 serves DHCP; this
            # makes the container request it, via networkd and nothing else.
            useNetworkd = true;
            useDHCP = false;
            dhcpcd.enable = false;
            useHostResolvConf = false;
          };

          # Force networkd into the image (useNetworkd alone doesn't survive the
          # lxc-container base). Match en*/eth* generically — never a host-
          # specific interface name.
          systemd.network = {
            enable = true;
            networks."99-ethernet-default-dhcp" = {
              matchConfig.Name = [
                "en*"
                "eth*"
              ];
              networkConfig.DHCP = "yes";
            };
          };

          # Stateless resolver — match your fleet's if you prefer.
          system.stateVersion = lib.mkDefault "26.11";
        };
    in
    {
      nixosConfigurations.coredns = nixpkgs.lib.nixosSystem {
        inherit system;
        modules = [ corednsModule ];
      };
    };
}
