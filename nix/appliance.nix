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
          # Loop-backed btrfs pool (no dedicated device): Incus creates a big
          # file under /var/lib/incus and mkfs.btrfs's it, so the pool is real
          # btrfs — CoW clones and snapshots — even though the root is ext4. No
          # `source`, so it's a loop file; explicit size because the adaptive
          # default caps at 30GiB, which is wrong for a storage box. GA revisits
          # sizing (dedicated btrfs partition vs. a larger loop). kixctl still
          # creates kixbr0/kix/CoreDNS itself; NixOS only lays down the pool.
          storage_pools = [
            {
              name = "kixpool";
              driver = "btrfs";
              config = {
                size = "10GiB";
              };
            }
          ];
        };
      };

      # Incus on NixOS needs the nftables backend to coexist cleanly with any
      # other firewall management on the box.
      networking.nftables.enable = true;

      # btrfs kernel module + userspace (mkfs.btrfs) so Incus can create and
      # mount the loop-backed btrfs kixpool. Applies to build-vm and the image
      # alike, since both stand up the pool.
      boot.supportedFilesystems = [ "btrfs" ];

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
          diskSize = 20480;
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
