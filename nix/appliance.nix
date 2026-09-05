# The appliance's NixOS module list, shared by two consumers in flake.nix:
#   - nixosConfigurations.appliance  (`nixos-rebuild build-vm` — the boot-test loop)
#   - packages.<system>.appliance-qcow  (nixos-generators — the distributable image)
# Returning the module list rather than a built nixosSystem is what lets one
# appliance definition drive both paths without duplication. Enables the local
# Incus daemon the appliance always carries (D30); the vmVariant block at the
# bottom is inert for the qcow image and only applies under build-vm.
{
  self,
  system ? "x86_64-linux",
}:
[
  self.nixosModules.kixctl
  (
    { config, ... }:
    {
      services.kixctl = {
        enable = true;
        package = self.packages.${system}.kixctl;
        # Reached via a forwarded port in the VM, so serve on localhost — that
        # way Caddy's internal cert matches the host the browser asks for.
        hostName = "localhost";
        appUrl = "https://localhost:8443";
        seedAdmin = {
          email = "admin@kixctl.local";
          password = "kixctl-dev";
        };
      };

      # D30 — the appliance always runs a local Incus daemon. The first-run
      # wizard decides at runtime whether kixctl drives THIS daemon (start
      # fresh, the default socket driver) or enrolls against an existing
      # remote cluster (bolt on, https). NixOS owns only the daemon and a
      # pool for kixctl's `kix` profile to resolve; kixctl creates kixbr0,
      # the `kix` profile and CoreDNS itself over the socket, exactly as it
      # does against a remote cluster — one code path, both drivers.
      virtualisation.incus = {
        enable = true;
        preseed = {
          # `dir` has no external dependency (no ZFS/btrfs dataset), the right
          # default for a self-contained box. State lives under /var/lib/incus,
          # which is ordinary mutable state and survives immutable rebuilds.
          # No network or profile is seeded here — those are kixctl's to own.
          storage_pools = [
            {
              name = "kixpool";
              driver = "dir";
              config.source = "/var/lib/incus/storage-pools/kixpool";
            }
          ];
        };
      };

      # Incus on NixOS needs the nftables backend to coexist cleanly with any
      # other firewall management on the box.
      networking.nftables.enable = true;

      # The workers that drive deploys run as the kixctl user; incus-admin
      # grants them unrestricted access to the local Incus API over the
      # socket (the `incus` group would scope them to a single project).
      users.users.${config.services.kixctl.user}.extraGroups = [ "incus-admin" ];

      system.stateVersion = "25.11";

      # Applies ONLY under `nixos-rebuild build-vm` (the vmVariant sub-evaluation,
      # where qemu-vm's options are in scope). Keeps the throwaway VM plumbing —
      # root password, port forwards, firewall-off — out of the real appliance
      # config, and is ignored entirely by the qcow image build. Memory and disk
      # are sized up to give the Incus daemon and a test workload headroom; these
      # bounds touch the test VM only.
      virtualisation.vmVariant = {
        virtualisation = {
          memorySize = 4096;
          diskSize = 12288;
          cores = 4;
          graphics = false;
          forwardPorts = [
            {
              from = "host";
              host.port = 18443;
              guest.port = 443;
            }
            {
              from = "host";
              host.port = 12222;
              guest.port = 22;
            }
          ];
        };
        users.users.root.initialPassword = "root";
        services.openssh.enable = true;
        services.openssh.settings.PermitRootLogin = "yes";
        networking.firewall.enable = false;
      };
    }
  )
]
