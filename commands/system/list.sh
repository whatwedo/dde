## List Projects running on DDE
#
# Command
#    system:list
function system:list() {
    _logGreen "Projects running on DDE"
    local currentDir=${pwd}

    for serviceName in $(${DOCKER_BIN} ps --format {{.Names}}); do
        local dirs
        if [ "$(${DOCKER_BIN} inspect --format='{{json .NetworkSettings.Networks.dde}}' ${serviceName})" != "null" ]; then
            for dir in $(${DOCKER_BIN} inspect --format='{{index .Config.Labels "com.docker.compose.project.working_dir" }}' ${serviceName}); do
                if [[ ! " ${dirs[*]} " =~ " ${dir} " ]]; then
                    dirs+=" ${dir} "
                    echo ""
                    if [ "${dir}" != "${ROOT_DIR}" ]; then
                        _logYellow "Project ${dir} is running"
                    else
                        _logYellow "System ${dir} is running"
                    fi
                    cd ${dir}
                    ${DOCKER_COMPOSE} ps --services
                fi
            done
        fi
    done

    cd ${currentDir}
}







