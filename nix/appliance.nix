# The appliance system, used to prove slice 2 with `nixos-rebuild build-vm`.
# Imports the reusable kixctl module and adds throwaway VM plumbing (root
# password, port forwards, memory). This is the boot-test target, not the
# shippable image — that's slice 5 (nixos-generators).
{
  self,
  nixpkgs,
  system ? "x86_64-linux",
}:
nixpkgs.lib.nixosSystem {
  inherit system;
  modules = [
    self.nixosModules.kixctl
    (
      { ... }:
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

        # --- build-vm proof plumbing (throwaway) ---
        users.users.root.initialPassword = "root";

        virtualisation = {
          memorySize = 2048;
          diskSize = 6144;
          forwardPorts = [
            {
              from = "host";
              host.port = 8443;
              guest.port = 443;
            }
            {
              from = "host";
              host.port = 2222;
              guest.port = 22;
            }
          ];
        };

        services.openssh.enable = true;
        networking.firewall.enable = false;

        system.stateVersion = "25.11";
      }
    )
  ];
}
