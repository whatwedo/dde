function _openUrl() {
    for vhost in $(${DOCKER_COMPOSE} config | _yq_stdin e '.services.*.environment.VIRTUAL_HOST | select(length>0)'); do
        IFS=',' read -ra hosts <<< "$vhost"
        for host in "${hosts[@]}"; do
            _logYellow "visit: https://${host}"
        done
    done
}
