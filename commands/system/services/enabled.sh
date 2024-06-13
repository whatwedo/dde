## List enabled dde system services
#
# Command
#    system:services:enabled

function system:services:enabled() {
    cd ${ROOT_DIR}
    _logGreen "Enabled System services:"

    for enabledSerice in services/conf.d/*; do
        echo "${enabledSerice#services/conf.d/}"
    done
}
