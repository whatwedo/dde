## Cleanup whole docker environment. USE WITH CAUTION
#
# Command
#    system:cleanup
#    system-cleanup


function system:cleanup() {
    _logYellow "Pruning unused docker resources"
    ${DOCKER_BIN} system prune -f --volumes

    if [[ "$(uname)" == "Linux" ]]; then
        _logYellow "Shrinking down docker data"
        ${DOCKER_BIN} run --rm -it --privileged --pid=host walkerlee/nsenter -t 1 -m -u -i -n fstrim /var/lib/docker
    else
        _logYellow "Skipping fstrim (Linux only)"
    fi

    _logGreen "Finished system cleanup"
}

function system-cleanup() {
    system:cleanup
}
