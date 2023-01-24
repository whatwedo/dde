_isDoas() {
    local _test_doas=$(${DOCKER_COMPOSE} exec ${_service} sh -c "if [[ -f /usr/bin/doas ]]; then echo 1; else echo 0; fi")
    echo ${_test_doas}
}
