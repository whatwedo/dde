## Print dde services
#
# Command
#    project:services

function project:services() {
    _checkProject
    _logGreen "Available project services:"
    _existingServices
}
