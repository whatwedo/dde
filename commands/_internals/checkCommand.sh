function _checkCommand() {
    if [[ "${1}" == "_*" ]]; then
        _logRed "Invalid command ${1}"
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
