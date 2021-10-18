function _checkCommand() {
    if [[ "${1%%:*}" != "system"
            && "${1%%:*}" != "s"
            && "${1%%:*}" != "project"
            && "${1%%:*}" != "p"
            && "${1%%:*}" != "local"
            && "${1%%:*}" != "help" ]]; then
        _logRed "Invalid command ${1}"
        _logYellow "Command must be prefixed with system: project: or :local"
        echo ""
        help
        exit 1;
    fi
    if [[ "`type -t ${1}`" != "function" ]]; then
        _logRed "Invalid command ${1}"
        _logYellow "Command does not exists"
        echo ""
        help
        exit 1
    fi
}
