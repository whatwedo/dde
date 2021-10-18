## Remove system dde infrastructure and nukes data
#
# Command
#    system:nuke

function system:nuke() {
    _logRed "Removing dde sytem"
    system:destroy

    _logRed "Removing data"
    cd ${ROOT_DIR}
    sudo find ./data/* -maxdepth 1 -not -name .gitkeep -exec rm -rf {} ';'

    _logGreen "Finished nuking successfully"
}

