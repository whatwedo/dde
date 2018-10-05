# dde (Docker based Development Environment)

> Local development environment toolset on Docker supporting multiple projects.


## Requirements

* macOS, Arch Linux or Ubuntu
* Docker 17.09.0+
* docker-compose 1.22+
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
echo "alias dde='make -f ~/dde/Makefile'" >> .bashrc
dde system-up
```
    


## TODO:

* SSH-Agent
* Apache Config UID
* Logging
* Symfony ohne dotenv
* Sample project docker-compose.yml


## License

This project is under the MIT license. See the complete license in the bundle: [LICENSE](LICENSE)
