_getServices() {

    local -n return_array=$1

    for f in services/*; do
    if [ -d "$f" ]; then
        # Will not run if no directories are available
        if [ -f "${f}/docker-compose.yml" ]; then
            return_array+=("${f#services/}")
        fi
    fi
    done
}


_serviceExists() {
    local exists=0

    _getServices allServices

    if [[ ${allServices[@]} =~ $1 ]]
    then
      exists=0
    else
      exists=1
    fi

    return ${exists}
}
