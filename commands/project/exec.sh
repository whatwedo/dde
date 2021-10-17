## Opens shell with user dde on first container

function project:exec() {
    _checkProject
    docker-compose exec $(docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//') gosu dde sh
}

