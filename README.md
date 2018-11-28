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

On OSX you can create file `/etc/resolver/test` with content `nameserver 127.0.0.1` to forward all requests for `.test`-domains.

Otherwise set your DNS to `127.0.0.1` with fallbacks of your choice.


# Known problems

* Files of filesystem mapped with docker-sync will get owner group with id `0`.


## TODO:

* Linux testing


## License

This project is under the MIT license. See the complete license in the bundle: [LICENSE](LICENSE)
