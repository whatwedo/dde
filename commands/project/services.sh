## Print dde services
#
# Command
#    project:services

function system:services() {
    _checkProject
    _logGreen "Available project services:"
    _existingServices
}
