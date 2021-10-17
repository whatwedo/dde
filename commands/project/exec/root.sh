## Opens privileged shell on first container

function project:exec:root() {
    _checkProject
    docker-compose exec $(docker run --rm -v $(pwd):/workdir mikefarah/yq:3 yq r --printMode p docker-compose.yml 'services.*' | head -n1 | sed 's/.*\.//') sh
}
