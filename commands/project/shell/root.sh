## Opens privileged shell on first container
#
# Command
#    project:exec:root
#    p:e:r
#    exec-root

function project:shell:root() {
    _checkProject
    ${DOCKER_COMPOSE} exec $(docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//') sh
}

function p:s:r() {
    project:shell:root
}

function shell-root() {
    project:shell:root
}


