## Remove system dde infrastructure and nukes data
#
# Command
#    system:nuke
#    system-nuke

function system:nuke() {
    _logRed "Removing dde sytem"
    system:destroy

    if [ -z ${DATA_DIR} ]; then
        _logRed "DATA_DIR is not defined"
        exit 1
    fi

    _logRed "Removing data"
    sudo rm -rf ${DATA_DIR}/*

    _logGreen "Finished nuking successfully"
}

function system-nuke() {
    system:nuke
}
