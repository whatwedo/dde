## Opens shell with user dde on first container
#
# Command
#    project:exec
#    p:e
#    exec

# Arguments
#    service       optional, open shell of service, default open first container
function project:exec() {
    _logYellow "Please note that the command exec has been renamed to 'shell' in the script."
}

function p:e() {
    project:exec ${1}
}

function exec() {
    project:exec ${1}
}
