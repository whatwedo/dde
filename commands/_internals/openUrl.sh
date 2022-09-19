function _openUrl() {
    for vhost in $(${DOCKER_COMPOSE} config | _yq_stdin e '.services.*.environment.VIRTUAL_HOST');  do
         if [[ "${vhost}" != "null" ]]; then
            _logYellow "visit: https://${vhost}"
        fi
    done
}
