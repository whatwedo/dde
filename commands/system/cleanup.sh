## Cleanup whole docker environment. USE WITH CAUTION
#
# Command
#    system:cleanup
#    system-cleanup


function system:cleanup() {
    _logYellow "Running docker-gc"
    ${DOCKER_BIN} run --rm -v /var/run/docker.sock:/var/run/docker.sock -e REMOVE_VOLUMES=1 spotify/docker-gc sh -c "/docker-gc || true"

    _logYellow "Shrinking down docker data"
    ${DOCKER_BIN} run --rm -it --privileged --pid=host walkerlee/nsenter -t 1 -m -u -i -n fstrim /var/lib/docker

    _logGreen "Finished system cleanup"
}

function system-cleanup() {
    system:cleanup
}
