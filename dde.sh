#!/usr/bin/env bash

set -eo pipefail


DC="${DC:-exec}"
SOURCE=${BASH_SOURCE}
ROOT_DIR=$( cd -- "$( dirname -- "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )
CERT_DIR=${ROOT_DIR}/data/reverseproxy/etc/nginx/certs
HELPER_DIR=${ROOT_DIR}/helper
NETWORK_NAME=dde
DOCKER_BUILDKIT=1
DDE_UID=$(id -u)
DDE_GID=$(id -g)
DDE_SH=${0}
export DDE_UID
export DDE_GID


# If we're running in CI we need to disable TTY allocation for docker-compose
# commands that enable it by default, such as exec and run.
TTY=""
if [[ ! -t 1 ]]; then
  TTY="-T"
fi


if [ -f ${ROOT_DIR}/dde.local.sh ]; then
    source ${ROOT_DIR}/dde.local.sh
fi
# -----------------------------------------------------------------------------
# Helper functions start with _ and aren't listed in this script's help menu.
# -----------------------------------------------------------------------------

function _dc {
  docker-compose "${DC}" ${TTY} "${@}"
}

function _build_run_down {
  docker-compose build
  docker-compose run ${TTY} "${@}"
  docker-compose down
}

# -----------------------------------------------------------------------------

function system:env {  ## show the dde system env
    _logGreen 'dde.sh system environment'
    echo OSTYPE=${OSTYPE}
    echo SOURCE=${SOURCE}
    echo ROOT_DIR=${ROOT_DIR}
    echo CERT_DIR=${CERT_DIR}
    echo HELPER_DIR=${HELPER_DIR}
    echo NETWORK_NAME=${NETWORK_NAME}
    echo DOCKER_BUILDKIT=${DOCKER_BUILDKIT}
    echo DDE_UID=${DDE_UID}
    echo DDE_GID=${DDE_GID}
    echo DDE_SH=${DDE_SH}

    if [ -f ${ROOT_DIR}/dde.local.sh ]; then
        _logYellow "include: ${ROOT_DIR}/dde.local.sh"
    fi
}

function system:up {  ## Initializes and starts dde system infrastructure
    _logYellow "Creating network if required"
    docker network inspect ${NETWORK_NAME} &> /dev/null
    if [ $? -ne 0 ]; then
	    docker network create ${NETWORK_NAME}
    fi

	_logYellow "Creating default docker config.json"
	if [ ! -f ~/.docker/config.json ]; then
		mkdir -p ~/.docker
		echo '{}' > ~/.docker/config.json;
	fi

	_logYellow "Creating CA cert if required"
	mkdir -p ${CERT_DIR}
	cd ${CERT_DIR}
    if [ ! -f ca.pem ]; then
        openssl genrsa -out ca.key 2048
        openssl req -x509 -new -nodes -key ca.key -sha256 -days 3650 -subj "/C=CH/ST=Bern/L=Bern/O=dde/CN=dde" -out ca.pem;
    fi

	_logYellow "Creating certs used by system services"
	${HELPER_DIR}/generate-vhost-cert.sh ${CERT_DIR} portainer.test;
	${HELPER_DIR}/generate-vhost-cert.sh ${CERT_DIR} mailhog.test;

	_logYellow "Starting containers"
	cd ${ROOT_DIR}
	docker-compose up -d

	_addSshKey

	_logGreen "Finished startup successfully"
}

function system:status {   ## Print dde system status
    cd ${ROOT_DIR}
    docker-compose ps
}

function system:start { ## Start already created system dde environment
    cd ${ROOT_DIR}
    docker-compose start
	_addSshKey
}

function system:stop { ## Start already created system dde environment
    cd ${ROOT_DIR}
    docker-compose stop
}

function system:destroy { ## Remove system dde infrastructure
    _logRed "Removing containers"
	docker rm -f $(docker network inspect -f '{{ range $$key, $$value := .Containers }}{{ printf "%s\n" $$key }}{{ end }}' ${NETWORK_NAME}) &> /dev/null
	cd ${ROOT_DIR}
	docker-compose down -v --remove-orphans

	_logRed "Removing network if created"
	if [ $(docker network ls | grep ${NETWORK_NAME}) ]; then
		docker network rm ${NETWORK_NAME}
	fi

	_logGreen "Finished destroying successfully"
}

function system:update { ## Update dde system
    _logRed "Removing dde (system)"
    system:destroy

    _logYellow "Updating dde repository"
    cd ${ROOT_DIR}
    git pull

    _logYellow "Updating docker images"
    cd $(ROOT_DIR)
    docker-compose pul
    docker-compose build --pull

    _logYellow "Starting dde (system)"
    system:up

    _logGreen "Finished update successfully"
}

function system:nuke {  ## Remove system dde infrastructure and nukes data
    _logRed "Removing dde sytem"
	system:destroy

	_logRed "Removing data"
	cd ${ROOT_DIR}
	sudo find ./data/* -maxdepth 1 -not -name .gitkeep -exec rm -rf {} ';'

	_logGreen "Finished nuking successfully"
}

function system:cleanup { ## Cleanup whole docker environment. USE WITH CAUTION
	_logYellow "Running docker-gc"
	docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -e REMOVE_VOLUMES=1 spotify/docker-gc sh -c "/docker-gc || true"

	_logYellow "Shrinking down docker data"
	@docker run --rm -it --privileged --pid=host walkerlee/nsenter -t 1 -m -u -i -n fstrim /var/lib/docker

	_logGreen "Finished system cleanup"
}

function system:log {  ## Show log output of system services
	cd ${ROOT_DIR}
	docker-compose logs -f --tail=100
}


function _autocomplete {   ## add bash completions

        commands=""

        for commandName in `compgen -A function | grep "system:"`
        do
            commands="${commands} ${commandName}"
        done

        commands="${commands} project"
        for commandName in `compgen -A function | grep "project:"`
        do
            commands="${commands} ${commandName}"
        done


        if [ -f ${ROOT_DIR}/dde.local.sh ]; then
            for commandName in `compgen -A function | grep ":"`
            do
                commands="${commands} ${commandName}"
            done
        fi

        echo -n "complete -W '${commands}' dde.sh"
}



function project:env  { ## Show system environment
    _checkProject
    system:env

    _logGreen  "DDE Project env:"
    if [ -f ./docker-compose.override.yml ]; then
        echo ./docker-compose.override.yml defined
    fi
    echo SYNC_MODE=${SYNC_MODE}

}

function project:docker-override  { ## creates  docker-compose.override.yml in your project
    _checkProject
    if [ -f ./docker-compose.override.yml ]; then
        _logRed "docker-compose.override.yml already installed"
    else
        cp ${ROOT_DIR}/docker-compose.override.yml .
        _logGreen "docker-compose.override.yml installed, make your custom changes"
    fi

}


function project:up  {          ## Creates and starts project containers
	_checkProject

	_logYellow "Generating SSL cert"

	for vhost in `grep VIRTUAL_HOST= docker-compose.yml | cut -d'=' -f2`
    do
		${HELPER_DIR}/generate-vhost-cert.sh ${CERT_DIR} ${vhost}
	done

    if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _logYellow "Skipping docker-sync because Mutagen config exists"
        _startDockerSync
    fi

	_logYellow "Starting containers"
	docker-compose up -d

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _startOrResumeMutagen
    fi
	_logGreen "Finished startup successfully"


	for vhost in `grep VIRTUAL_HOST= docker-compose.yml | cut -d'=' -f2`
    do
		_logYellow "visit: https://${vhost}"
	done

}

function project:start {   ## Start already created project environment
	_checkProject
	if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _logYellow "Skipping docker-sync because Mutagen config exists"
        _startDockerSync
    fi

	_logYellow "Starting docker containers"
	docker-compose start

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _startOrResumeMutagen
    fi
}

function project:stop {  ## Stop project environment
	_checkProject

	_logYellow "Stopping docker containers"
	docker-compose stop

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _pauseMutagen
    fi

    if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _stopDockerSyn
    fi
}

function project:update {  ## Update/rebuild project
	_checkProject

	_logRed "Destroying project"
	project:destroy

	_logYellow " Pulling/building images"
	docker-compose build --pull
	docker-compose pull

	_logYellow "Starting project"
	projectup

	_logGreen "Finished update successfully"
}

function project:destroy {  ## Remove central project infrastructure
	_checkProject

	_logYellow "Removing containers"
	docker-compose down -v --remove-orphans

	_logYellow "Deleting SSL certs"

    for vhost in `grep VIRTUAL_HOST= docker-compose.yml | cut -d'=' -f2`
    do
        _logYellow "Delete certs for ${vhost}"
		rm -f ${CERT_DIR}/${vhost}.*
	done

    if [ "${SYNC_MODE}" = "mutagen" ]; then
        _terminateMutagen
    fi

    if [ "${SYNC_MODE}" = "docker-sync" ]; then
        _cleanDockerSync
    fi

	_logGreen "Finished destroying successfully"
}


function project:exec { ## Opens shell with user dde on first container
	_checkProject
	docker-compose exec `docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//'` gosu dde sh
}

function project:exec:root {  ## Opens privileged shell on first container
	_checkProject
	docker-compose exec `docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//'` sh
}

function project:log {  ## Show log output
    _checkProject
	docker-compose logs -f --tail=100
}

function project:status {  ## Print project status
	_checkProject
	docker-compose ps
}

function _syncMode {
    SYNC_MODE=volume
    if [ -f ./mutagen.yml ]; then
        SYNC_MODE=mutagen
    fi

    if [ -f ./docker-sync.yml ]; then
        SYNC_MODE=docker-sync
    fi

    if [ -f ./docker-compose.override.yml ]; then
        if [ $(grep "^x-dde-sync:" docker-compose.override.yml | cut -d':' -f2) ]; then
            SYNC_MODE=$(grep "x-dde-sync:" docker-compose.override.yml | cut -d':' -f2 | xargs)
        fi
    fi

    if [[ "${SYNC_MODE}" != "volume" ]] && [[ "${SYNC_MODE}" != "docker-sync" ]] && [[ "${SYNC_MODE}" != "mutagen" ]]; then
        _logRed "Unknown Sync mode ${SYNC_MODE}"
        exit 1
    fi
}


function _checkProject {
    if [ "${ROOT_DIR}" == "$(pwd)" ]; then
		_logRed "dde root is not a valid project directory"
		exit 1
	fi
	if [ ! -f docker-compose.yml ]; then
		_logRed "docker-compose.yml not found"
		exit 1
	fi
	mkdir -p .dde
	cp -R ${ROOT_DIR}/helper/configure-image.sh .dde/configure-image.sh
	_syncMode
}

function _logYellow {
    printf "\033[1;33m${@}\033[0m\n"
}
function _logRed {
    printf "\033[0;31m${@}\033[0m\n"
}
function _logGreen {
    printf "\033[0;32m${@}\033[0m\n"
}

function _addSshKey {
	_logYellow "Adding SSH key (maybe passphrase required)"
	cd ${ROOT_DIR}
	docker-compose exec ssh-agent sh -c /import-keys.sh
}


function _startDockerSync {
    if [ "${SYNC_MODE}" != "docker-sync" ]; then
        return
    fi

    if [ $(which docker-sync) ]; then
        _logYellow "docker-sync is installed"
    else
        _logRed "docker-sync is not installed, see: https://docker-sync.io"
         exit 1
    fi
	_logYellow "Starting docker-sync. This can take several minutes depending on your project size"
	docker-sync stop
	docker-sync start
}


function _stopDockerSync {
    if [ "${SYNC_MODE}" != "docker-sync" ]; then
        return
    fi

	_logYellow "Stopping docker-sync"
	docker-sync stop
}

function _cleanDockerSync {
    if [ "${SYNC_MODE}" != "docker-sync" ]; then
        return
    fi

	_logYellow "Cleaning up docker-sync"
	_stopDockerSync
	docker-sync clean
}


function _startOrResumeMutagen {
    if [ "${SYNC_MODE}" != "mutagen" ]; then
        return
    fi

	if [ $(which mutagen) ]; then
	    _logYellow "Mutagen is installed"
	else
	    _logRed "Mutagen is not installed, see: https://mutagen.io"
	    exit 1
    fi
    _logYellow "Starting Mutagen. This can take several minutes depending on your project size"
	mutagen project resume 2>/dev/null || mutagen project start;
	mutagen project flush;
}

function _pauseMutagen {
    if [ "${SYNC_MODE}" != "mutagen" ]; then
        return
    fi

    _logYellow "Stopping Mutagen"
    mutagen project pause;
}

function _terminateMutagen {
    if [ "${SYNC_MODE}" != "mutagen" ]; then
        return
    fi
	_logYellow "Terminating Mutagen"
    mutagen project terminate;
}

function _getFunctionHelp {
    cat ${2} | grep "function ${1} .*##" | sed -e 's/function.*##\s*//'
}


function help {
    printf "%s <command> [args]\n" "${0}"

    local _functionName="                          "

    _logYellow "System Commands:"
    for commandName in `compgen -A function | grep "system:"`
    do
        echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName} ${DDE_SH})"
    done

    _logYellow "\nProject Commands:"
    for commandName in `compgen -A function | grep "project:"`
    do
        echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName} ${DDE_SH})"
    done

    if [ -f ${ROOT_DIR}/dde.local.sh ]; then
        _logYellow "\nLocal Commands:"
        for commandName in `compgen -A function | grep "local:"`
        do
            echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName} ${ROOT_DIR}/dde.local.sh)"
        done
	fi
}



# This idea is heavily inspired by: https://github.com/adriancooney/Taskfile
"${@:-help}"

