# -*- mode: ruby -*-
# vi: set ft=ruby :

Vagrant.configure("2") do |config|

  # Set vagrant box
  config.vm.box = "ubuntu/bionic64"

  # Forward ports
  config.vm.network "forwarded_port", guest: 53, host: 8053, host_ip: "127.0.0.1", protocol: "udp"
  config.vm.network "forwarded_port", guest: 53, host: 8053, host_ip: "127.0.0.1", protocol: "tcp"
  config.vm.network "forwarded_port", guest: 80, host: 8080, host_ip: "127.0.0.1"
  config.vm.network "forwarded_port", guest: 443, host: 8443, host_ip: "127.0.0.1"
  config.vm.network "forwarded_port", guest: 3306, host: 3306, host_ip: "127.0.0.1"

  # Configure private network (used for NFS shared folder)
  config.vm.network "private_network", type: "dhcp"

  # Coonfigure shared folder
  config.vm.synced_folder ".", "/vagrant", type: "nfs"

  # Configure virtualbox
  config.vm.provider "virtualbox" do |vb|
    vb.memory = "2048"
    vb.gui = true
  end

  # Forward SSH agent
  config.ssh.forward_agent = true

  # Provisioning
  config.vm.provision "file", source: "~/.ssh/id_rsa", destination: "/home/vagrant/.ssh/id_rsa"
  config.vm.provision "file", source: "~/.ssh/id_rsa.pub", destination: "/home/vagrant/.ssh/id_rsa.pub"
  config.vm.provision "shell", inline: <<-SHELL

     # Install docker
     curl -fsSL get.docker.com -o get-docker.sh
     sh get-docker.sh
     docker --version
     usermod -a -G docker vagrant

     # Install docker-compose
     curl -L https://github.com/docker/compose/releases/download/1.18.0/docker-compose-`uname -s`-`uname -m` -o /usr/local/bin/docker-compose
     chmod +x /usr/local/bin/docker-compose
     docker-compose --version

    # Install docker-sync
    apt-get install -qq ruby-full
    gem install docker-sync

    # Install GUI
    apt-get install -qq lubuntu-core chromium-browser terminator

    # Install dde
    echo "alias dde='make -f /vagrant/Makefile'" >> /home/vagrant/.bashrc

    # Enable autologin
    echo "[SeatDefaults]
    autologin-user=vagrant
    autologin-user-timeout=0
    user-session=Lubuntu
    greeter-session=lightdm-gtk-greeter" > /etc/lightdm/lightdm.conf.d/20-lubuntu.conf

    # You wil go mad if this is installed, locks the session all the time
    apt-get purge -y light-locker

    # Set SSH key permissions
    chown vagrant:vagrant /home/vagrant/.ssh/id_rsa
    chmod 600 /home/vagrant/.ssh/id_rsa
  SHELL

  # Restart VM
  if Vagrant.has_plugin?("vagrant-reload")
    config.vm.provision :reload
  else
    warn "The recommeded plugin 'vagrant-reload' is currently not installed. You can install it by executing: 'vagrant plugin install vagrant-reload'"
  end
end
