## Print dde services
#
# Command
#    system:services

function system:services() {
    cd ${ROOT_DIR}
    _logGreen "Available System services:"
    _existingServices
}
