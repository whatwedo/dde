## fix permissions in webcontainer and locally
#
# resolve permission issues by `chown dde:dde` in the container
# and `chown {yourLocalUser}:{yourLocalGroup}` in the local host.
#
# Command:
#   project:fix-permission [container] [persmission] [path]
#
# Options:
#   [container]     name of the container, default first service in docker-compose
#   [permission]    [owner]:[group] combination applicable on chown, default dde:dde
#   [path]          path to apply chown, default '/var/www'

function project:fix-permissions() {
    _checkProject

    local container=$(docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//')
    local permission="dde:dde"
    local path="/var/www"
    local user=$(id -u)
    local group=$(id -g)

    if [[ ${1} ]]; then
        container=${1}
    fi

    if [[ ${2} ]]; then
        permission=${2}
    fi

    if [[ ${3} ]]; then
        path=${3}
    fi

    _logYellow "appling 'chown -hR ${permission} ${path}' on container ${container}"
    ${DOCKER_COMPOSE} exec ${container} chown -hR ${permission} ${path}

    _logYellow "appling 'chown -hR ${user}:${group}' locally"
    sudo chown -hR ${user}:${group} .
}
