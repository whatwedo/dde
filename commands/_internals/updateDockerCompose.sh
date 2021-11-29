function _updateDockerCompose() {
    #
    # Deprecation:
    # network default: network.external.name is deprecated in favor of network.name
    #
    local networksDefaultExternalName=$(_yq eval ".networks.default.external.name" docker-compose.yml)

    if [ "$networksDefaultExternalName" = "dde" ]; then
        _logYellow "docker-compose deprecation: move networks.default.name"
        _yq eval 'del(.networks.default.external.name)' --inplace docker-compose.yml

        if [ "$(yq eval '.networks.default.external | length' docker-compose.yml)" = "0" ]; then
            _yq eval 'del(.networks.default.external)' --inplace docker-compose.yml
        fi

        _yq eval '.networks.default.name = "dde"' --inplace --indent 4 --prettyPrint docker-compose.yml
    fi

    #
    # Deprecation:
    # network default: network.external.name is deprecated in favor of network.name
    #
    local networksDefaultExternalName=$(_yq eval ".volumes.ssh-agent_socket-dir.external.name" docker-compose.yml)

    if [ "$networksDefaultExternalName" = "dde_ssh-agent_socket-dir" ]; then
        _logYellow "docker-compose deprecation: move volumes.ssh-agent_socket-dir.external.name"
        _yq eval 'del(.volumes.ssh-agent_socket-dir.external.name)' --inplace docker-compose.yml

        if [ "$(yq eval '.volumes.ssh-agent_socket-dir.external | length' docker-compose.yml)" = "0" ]; then
            _yq eval 'del(.volumes.ssh-agent_socket-dir.external)' --inplace docker-compose.yml
        fi

        _yq eval '.volumes.ssh-agent_socket-dir.name = "dde_ssh-agent_socket-dir"' --inplace --indent 4 --prettyPrint docker-compose.yml
    fi
}
