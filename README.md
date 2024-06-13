![Logo](assets/img/logo.png)


# dde (Docker based Development Environment)

Local development environment toolset based on Docker supporting multiple projects.

Features include:

* Simplified Docker web application development
* Installation of system wide services:
    * `*.test` domain lookup based on [dnsmasq](http://www.thekelleys.org.uk/dnsmasq/doc.html)
    * Reverse Proxy based on [jwilder/nginx-proxy](https://github.com/jwilder/nginx-proxy) to run multiple projects on same port (80/443) with autoconfigured SSL certificates
    * [MariaDB](https://mariadb.org/) ([MySQL](https://www.mysql.com/) alternative)
    * [MailCrab](https://github.com/tweedegolf/mailcrab) (SMTP testing server)
    * [ssh-agent](https://www.ssh.com/ssh/agent) used for sharing your SSH key without adding it to your project Docker containers.
* Choose you preferred file sharing
    * docker volume export
    * Performance optimized file sharing based on [docker-sync](http://docker-sync.io/) 
    * [Mutagen](https://mutagen.io/)

**Note:** dde is currently under heavy development and we don't offer any backward compatibility. However we use it at [whatwedo](https://www.whatwedo.ch/) on daily bases and it's safe to use it in your development environment.


## Requirements

* macOS, Linux or Windows (WSL 2)
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
~/dde/dde.sh system:dde:install
~/dde/dde system:services:enable dnsmasq
~/dde/dde system:services:enable mailhog
~/dde/dde system:services:enable mariadb
~/dde/dde system:services:enable reverseproxy
~/dde/dde.sh system:up
```

`system:dde:install` modifies your .profile files based on your shell:

* autocompletion 
* aliases 

dde can now be used in a new shell, enjoy!

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

To ensure DNS functionality in Docker on macOS X:

In the Docker GUI: Go to `"Settings" → "Resources" → "Network"`, then turn off "Use kernel networking for UDP".
In the configuration file: Set `kernelForUDP` to `false` in `~/Library/Group Containers/group.com.docker/settings.json`.

#### Linux

Set your DNS to `127.0.0.1` with fallbacks of your choice.

Trust the newly generated Root-CA found here:

```
~/dde/data/reverseproxy/etc/nginx/certs/ca.pem
```

##### Linux

1. **Convert PEM to CRT and Add Globally:**

   ```bash
   openssl x509 -outform der -in ~/dde/data/reverseproxy/etc/nginx/certs/ca.pem -out ~/dde/data/reverseproxy/etc/nginx/certs/ca.crt
   sudo cp ~/dde/data/reverseproxy/etc/nginx/certs/ca.crt /usr/local/share/ca-certificates/
   sudo update-ca-certificates
   ```

#### Windows (WSL 2)

Set your DNS to `127.0.0.1` with fallbacks of your choice.

1. In WSL-Terminal, adjust the file `/etc/wsl.conf` to

```
[network]
generateResolvConf = false
```

2. Remove the file / link `resolv.conf` and close the WSL-Terminal

3. To restart WSL, run in powershell (admin):

```
wsl --shutdown
Get-Service LxssManager | Restart-Service
```

4. Open a WSL-Terminal and create a file `/etc/resolv.conf` with the following content:

```
nameserver 127.0.0.1
nameserver 1.1.1.1
```

5. Restart WSL again (step #3)

## File-Sync

File-sync can be done by mutagen, docker-sync or by native docker volumes. 

### Mutagen

put the `mutagen.yml` file in the project root directory see [mutagen-example](example/with-mutagen).

In the `docker-compose.yml` the volume is not exposed.

### Docker-Sync

put the `docker-sync.yml` file in the project root directory see [docker-sync-example](example/with-dockersync).

In the `docker-compose.yml` the volume is not exposed.

### native Docker

define in your `docker-compose.yml` or `docker-compose.override.yml` the exposed volumes.

### override the sync Settings

If you have project where the file-sync done by mutagen or docker-sync. you are able to override
the setting in a `docker-compose.override.yml` file.

copy sample `docker-compose.override.yml`:

```
$ dde project:docker-override
```

this command copies [docker-compose.override.yml](example/docker-compose.override.yml) to your project directory.

edit the file. With the custom-tag  `x-dde-sync` you can now choose you preferred syncing mode. 

valid values are `docker-sync`, `mutagen` or `volume`

if you use `volume` you must expose the volume in the `docker-compose.override.yml` file. 

## Tip and Tricks

### Configuring a Custom Shell using `.dde.yml`

To configure a custom shell in whatwedo/dde using the `.dde.yml` configuration file, specify your preferred shell with the container.shell key. This setting instructs whatwedo/dde to use the specified shell within the container. For instance, to utilize zsh as the container shell, your configuration would appear as follows:

```yml
version: "1"

container:
  shell: zsh
```

And in your docker-compose.yml file, add the corresponding environment variable:

```yml
environment:
  - DDE_CONTAINER_SHELL: ${DDE_CONTAINER_SHELL}
```

This ensures that the custom shell setting is effectively utilized within the container.

To specify a default shell in the Dockerfile, include the ARG directive for customization. For example:

```dockerfile
ARG DDE_CONTAINER_SHELL
```

### Configuring DNS Forwarding in DDE

The environment variables `DDE_DNS_FORWARD_1` and `DDE_DNS_FORWARD_2` allow for setting custom DNS servers in the Docker Development Environment (DDE). This is useful when local Internet DNS servers are to be used.

#### Instructions

1. Set `DDE_DNS_FORWARD_1` and `DDE_DNS_FORWARD_2` to the IP addresses of your preferred DNS servers.

   Example:

   ```bash
   export DDE_DNS_FORWARD_1=192.168.1.100
   export DDE_DNS_FORWARD_2=192.168.1.101
   ```

#### Adding `DDE_DNS_FORWARD_1` and `DDE_DNS_FORWARD_2` to `bashrc`

For a more permanent solution, you can add the `DDE_DNS_FORWARD_1` and `DDE_DNS_FORWARD_2` variables to your `bashrc` file. This ensures that these variables are automatically set every time a new shell session is started.

To do this, append the `export` commands to your `~/.bashrc` file:

1. Open your `~/.bashrc` file in a text editor, for example, you can use `nano`:

   ```bash
   nano ~/.bashrc
   ```

Add the following lines at the end of the file:

    ```bash
    export DDE_DNS_FORWARD_1=<Your_First_DNS_IP_Address>
    export DDE_DNS_FORWARD_2=<Your_Second_DNS_IP_Address>
    ```
Replace <Your_First_DNS_IP_Address> and <Your_Second_DNS_IP_Address> with the IP addresses of your preferred DNS servers.

Save and close the file.

To apply the changes immediately, source your ~/.bashrc file:

```bash
source ~/.bashrc
```

Now, `DDE_DNS_FORWARD_1` and `DDE_DNS_FORWARD_2` will be set automatically in each new shell session.

### Fix Permission

Services such as nginx in Docker containers normally runs with the `root` user. With the `dde exec` command
you login into the container with the user `dde`. If services writes files, ex. `var/cache`, `root` is the owner. 
On the host files also the `root` is the owner. In this case you normally not able to delete or change the files 
created by the services.

the [command](commands/project/fix-permissions.sh) `project:fix-permissions` resolve this issue by `chown dde:dde` 
in the container and `chown {yourLocalUser}:{yourLocalGroup}` in the local host.


### OPEN_URL & DDE_BROWSER

Add `OPEN_URL` in the `environment` array of your `docker-compose.yml`.

On the `project:up` or `project:open` command the website(s) will be opened in your standard browser.


```yaml
services:
    web:
        ...
        environment:
            - VIRTUAL_HOST=cloud.project.test
            - OPEN_URL=http://cloud.project.test/
    storage:
        ...
        environment:
            - VIRTUAL_HOST=minio.project.test
            - OPEN_URL=http://minio.project.test:9000/

```

Set the environment variable `DDE_BROWSER` if you what to start a specific browser.


`command/local.sh`
```bash
DDE_BROWSER=/usr/bin/firefox
```

## Services

With DDE you can install, enable and disable central system services.

By adding new service in the `services` directory you can add new central services:

example

```
services\
    mycustomserver\
        docker-compose.yaml
```

Enable Service:

```shell
> dde system:services:enable mycustomserver
> dde system:up

```

### Manage Services

  - list available service `dde system:services:available`
  - list enabled service `dde system:services:enabled`
  - enable service ex. `dde system:services:enable mailhog`
  - disable service ex. `dde system:services:disable mailhog`




## Usage

```
$ dde help
```

### Project configuration

Due to the early stage of this project there is no full documentation available. We created a [example](example) project with all required and optional configuration. Please checkout the [example](example) directory.


## Including custom command

you can include custom commands by adding them in the `commands/local/` directory. 
Custom commands must be prefixed with the `local:` namespace.

### Anatomy of a command

`commands\local\my_command.sh`
```bash
## inline help for local:my_command
#
# more help for the command
#
# this will be displayed with the --help argument on the command
#
# e.g dde local:command --help
#

function local:my_command() {
    echo 'execute local:my_command'
    _localCommand_someInternalFunction arg1
}

function _localCommand_someInternalFunction() {
    echo "do something with ${1}"
}
```

* script must be located in the `command` directory
* Help text for the commands
  * `:` will be replaced by `/` for locating the help script
  * the first line beginning with `##` is the help text displayed be the help command
  * all following lines beginning with `#` will displayed in the command help 
* you can add as many functions as you want in the script
* to avoid conflicts prefix internal functions 
* functions and can also be defined in the `command/local.sh` file
* the `command/local.sh` file is the last loaded source, so you are able to overwrite system variables
and functions there

`command/local.sh`
```bash
function _local_someGlobalHelperFunction() {
    echo "a global helper function"
}


# overwrite a variable
SYNC_MODE=volume


# overwrite a function/command
function project:env() {
    echo "my custom project env"
}


```

## Known solutions
* **failed to remove network dde**  
If you get this error it means your project `docker-compose.yml` is wrongly configured.
Be sure to mark the `dde` network as external, like in our examples:
```yml
networks:
    default:
        name: "dde"
        external: true # <-- important
```

## Known problems

* Files of filesystem mapped with docker-sync will get group id `0`.

## Bugs and Issues

If you have any problems with this image, feel free to open a new issue in our issue tracker https://github.com/whatwedo/dde/issues


## License

This project is under the MIT license. See the complete license in the repository: [LICENSE](LICENSE)
