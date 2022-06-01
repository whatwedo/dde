
function help() {
    if [[ ${1} ]]; then
        local _scriptFile="${ROOT_DIR}/commands/${1//:/\/}.sh"
        if [[ -f ${_scriptFile} ]]; then

            _logGreen "help for ${1}"
            sed -n '/^#/,$p;/^function/q' ${_scriptFile} | sed -n -e '/^#/p' | sed -e 's/^#*\s//g' | sed -e 's/^#//g'
        fi
    else

        printf "%s <command> [args]\n" "${0}"
        echo ""

        _logYellow "Arguments:"
        echo "   --autocomplete                  add \`eval \$(~/dde/dde.sh --autocomplete)\`  to  your \`~/.zshrc\` or \`~/.bash_profile\`"
        echo "   --help                          show extended help for command, if exists"
        echo ""

        local _functionName="                               "

        _logYellow "System Commands:"
        for commandName in $(compgen -A function | grep "system:"); do
            echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName})"
        done

        _logYellow "\nProject Commands:"
        for commandName in $(compgen -A function | grep "project:"); do
            echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName})"
        done

        _logYellow "\nLocal Commands:"
        for commandName in $(compgen -A function | grep "local:"); do
            echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName})"
        done
    fi
}


function _getFunctionHelp() {
    local _scriptFile="${ROOT_DIR}/commands/${1//:/\/}.sh"
    if [[ -f ${_scriptFile} ]]; then
        sed -e '/^##.*/!d;p' ${_scriptFile} | head -1 | sed -e 's/^##\s*//g' | tr -d '\n'
    fi
}
