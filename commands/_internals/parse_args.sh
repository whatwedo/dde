
function _parse_args() {
    local _help=0
    ## Clean up currently defined arguments
    command_args=()
    ## Iterate over the provided arguments
    while [[ ${#} -gt 0 ]]; do
        ## Stop parsing after the first non-flag argument
        #if [[ ${1} != -* ]]; then
        #  break
        #fi
        ## Help message
        if [[ ${1} == '-h' || ${1} == '--help' ]]; then
            _help=1
        elif [[ ${1} == '--autocomplete' ]]; then
            _autocomplete
            exit 0
        else
            command_args+=("${1}")
        fi
        ## Append unclassified flags back to runner_args
        shift 1
    done

    if [[ ${_help} -eq 1 ]]; then
        _checkCommand ${command_args[@]}
        help ${command_args[@]}
        exit 0
    fi

    ## Append remaining arguments that will be passed to the
    ## bootstrap function
    command_args+=("${@}")
}

