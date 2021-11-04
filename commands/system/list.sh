## List Projects running on DDE
#
# Command
#    system:list
function system:list() {
    _logGreen "Projects running on DDE"
    local currentDir=${pwd}

    for serviceName in $(docker ps --format {{.Names}}); do
#        echo "${serviceName}"
        local dirs
        if [ "$(docker inspect --format='{{json .NetworkSettings.Networks.dde}}' ${serviceName})" != "null" ]; then
            for dir in $(docker inspect --format='{{json .Config.Labels}}' ${serviceName} | _yq e '."com.docker.compose.project.working_dir"'); do

                if [[ ! " ${dirs[*]} " =~ " ${dir} " ]]; then
                    dirs+=" ${dir} "
                    echo ""
                    _logYellow "Project ${dir} is running"
                    cd ${dir}
                    docker-compose ps --services
                fi
            done
        fi
    done

    cd ${currentDir}
}







