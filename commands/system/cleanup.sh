## Cleanup whole docker environment. USE WITH CAUTION

function system:cleanup() {
    _logYellow "Running docker-gc"
    docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -e REMOVE_VOLUMES=1 spotify/docker-gc sh -c "/docker-gc || true"

    _logYellow "Shrinking down docker data"
    @docker run --rm -it --privileged --pid=host walkerlee/nsenter -t 1 -m -u -i -n fstrim /var/lib/docker

    _logGreen "Finished system cleanup"
}
