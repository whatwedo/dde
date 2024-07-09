## Enable dde system service
#
# Command
#    system:services:enable <service>

function system:services:enable() {
    cd ${ROOT_DIR}

    _checkDdeConfig

    # docker run --rm -i -v /home/mauri/dev/dde/data:/workdir mikefarah/yq -i '.services += "new_service"' dde.yml


    if _serviceExists ${1}; then
        _logGreen "System service: ${1} found"
    else
        _logRed "System service: ${1} not found"
        return
    fi
    _logYellow "Enable System services: ${1}"

    _addYamlService ${1}
}
