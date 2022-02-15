## Opens privileged shell on first container
#
# Command
#    project:exec:root
#    p:e:r
#    exec-root

function project:exec:root() {
    _checkProject
    docker-compose exec $(docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//') sh
}

function p:e:r() {
    project:exec:root
}

function exec-root() {
    project:exec:root
}


