## List enabled dde system services
#
# Command
#    system:services:enabled

function system:services:enabled() {
    cd ${ROOT_DIR}
    _logGreen "Enabled System services:"
    _getYamlServices
}
