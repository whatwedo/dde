# dde (Docker based Development Environment)

> Local development environment toolset on Docker supporting multiple projects.

## Requirements

* macOS or Ubuntu
* [Docker 17.09.0+](https://docs.docker.com/)
* [docker-compose 1.22+](https://docs.docker.com/compose/)
* [docker-sync 0.5+](http://docker-sync.io/)
* No other services listening localhost on:
    * Port 53
    * Port 80
    * Port 443
    * Port 3306
* Application service images based on the official [Ubuntu](https://hub.docker.com/_/ubuntu/) image with AMD64 as platform.


## Setup

```
cd ~
git clone git@dev.whatwedo.ch:wwd-internal/dde.git

# if you're using bash
echo "alias dde='make -f ~/dde/Makefile'" >> ~/.bash_profile

# if you're using zsh
echo "alias dde='make -f ~/dde/Makefile'" >> ~/.zshrc

dde system-up
```

### macOS
Forward requests for `.test`-domains to the local DNS resolver:

```
mkdir -p /etc/resolver
echo -e "nameserver 127.0.0.1" | sudo tee /etc/resolver/test
```

Trust the newly generated Root-CA for the self-signed certificates
```
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ~/dde/data/reverseproxy/etc/nginx/certs/ca.pem
```

### Linux / Unix
Set your DNS to `127.0.0.1` with fallbacks of your choice.

Trust the newly generated Root-CA found here:
```
~/dde/data/reverseproxy/etc/nginx/certs/ca.pem
```

## Usage
```
$ dde
destroy              Remove central project infrastructure
exec                 Opens shell with user dde on first container
exec_root            Opens privileged shell on first container
help                 Display this message
log                  Show log output
start                Start already created project environment
status               Print project status
stop                 Stop project environment
system-cleanup       Cleanup whole docker environment. USE WITH CAUTION
system-destroy       Remove system dde infrastructure
system-log           Show log output of system services
system-nuke          Remove system dde infrastructure and nukes data
system-start         Start already created system dde environment
system-status        Print dde system status
system-stop          Stop system dde environment
system-up            Initializes and starts dde system infrastructure
system-update        Update dde system
up                   Creates and starts project containers
```


# Known problems

* Files of filesystem mapped with docker-sync will get owner group with id `0`.


# TODO:

* Linux testing


# License

This project is under the MIT license. See the complete license in the bundle: [LICENSE](LICENSE)

