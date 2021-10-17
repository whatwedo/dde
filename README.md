![Logo](assets/img/logo.png)


# dde (Docker based Development Environment)

Local development environment toolset based on Docker supporting multiple projects.

Features include:

* Simplified Docker web application development
* Installation of system wide services:
    * `*.test` domain lookup based on [dnsmasq](http://www.thekelleys.org.uk/dnsmasq/doc.html)
    * Reverse Proxy based on [jwilder/nginx-proxy](https://github.com/jwilder/nginx-proxy) to run multiple projects on same port (80/443) with autoconfigured SSL certificates
    * [MariaDB](https://mariadb.org/) ([MySQL](https://www.mysql.com/) alternative)
    * [MailHog](https://github.com/mailhog/MailHog) (SMTP testing server)
    * [Portainer](https://www.portainer.io/) (Docker Webinterface)
    * [ssh-agent](https://www.ssh.com/ssh/agent) used for sharing your SSH key without adding it to your project Docker containers.
* Choose you preferred file sharing
    * docker volume export
    * Performance optimized file sharing based on [docker-sync](http://docker-sync.io/) 
    * [Mutagen](https://mutagen.io/)

**Note:** dde is currently under heavy development and we don't offer any backward compatibility. However we use it at [whatwedo](https://www.whatwedo.ch/) on daily bases and it's safe to use it in your development environment.


## Requirements

* macOS or Ubuntu
* [Docker 17.09.0+](https://docs.docker.com/)
* [docker-compose 1.22+](https://docs.docker.com/compose/)
* [docker-sync 0.5+](http://docker-sync.io/) 
* [Mutagen v0.10.0+](https://mutagen.io/)
* [Bash](https://www.gnu.org/software/bash/)
* [openssl](https://www.openssl.org/)
* No other services listening localhost on:
    * Port 53
    * Port 80
    * Port 443
    * Port 3306


# Architecture

![Architecture](assets/img/architecture.svg)


## Installation

```
cd ~
git clone https://github.com/whatwedo/dde.git
~/dde/dde.sh system:dde:setup
~/dde/dde.sh system:up
```

`system:dde:install` modifies your .profile files based on your shell:

* autocompletion 
* aliases 

dde can know be used in a new shell, enjoy!

### Aliases
```
# if you're using bash
echo "alias dde='~/dde/dde.sh'" >> ~/.bash_profile

# if you're using zsh
echo "alias dde='~/dde/dde.sh'" >> ~/.zshrc
```

### Autocompletion

add `eval $(~/dde/dde.sh --autocomplete)`  to  `~/.zshrc` or `~/.bash_profile`  


### Additional OS specific installation steps


#### macOS

Forward requests for `.test`-domains to the local DNS resolver:

```
sudo mkdir -p /etc/resolver
echo -e "nameserver 127.0.0.1" | sudo tee /etc/resolver/test
```

Trust the newly generated Root-CA for the self-signed certificates

```
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ~/dde/data/reverseproxy/etc/nginx/certs/ca.pem
```


#### Linux

Set your DNS to `127.0.0.1` with fallbacks of your choice.

Trust the newly generated Root-CA found here:

```
~/dde/data/reverseproxy/etc/nginx/certs/ca.pem
```


## Usage

```
$ dde help
```

### Insparations

https://github.com/stylemistake/runner

https://github.com/nickjj/docker-flask-example

https://github.com/adriancooney/Taskfile



### Project configuration

Due to the early stage of this project there is no full documentation available. We created a [example](example) project with all required and optional configuration. Please checkout the [example](example) directory.


## Known problems

* Files of filesystem mapped with docker-sync will get group id `0`.


## Bugs and Issues

If you have any problems with this image, feel free to open a new issue in our issue tracker https://github.com/whatwedo/dde/issues


## License

This project is under the MIT license. See the complete license in the repository: [LICENSE](LICENSE)
