## Remove system dde infrastructure and nukes data
#
# Command
#    system:nuke
#    system-nuke

function system:nuke() {
    _logRed "Removing dde sytem"
    system:destroy

    _logRed "Removing data"
    sudo rm -rf ${DATA_DIR}/*

    _logGreen "Finished nuking successfully"
}

function system-nuke() {
    system:nuke
}
