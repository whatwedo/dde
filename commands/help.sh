
function help() {
    if [[ ${1} && -f "${HELP_DIR}/${1//:/.}.txt" ]]; then
        _logGreen "help for ${1}"
        cat "${HELP_DIR}/${1//:/.}.txt"
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
            echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName} ${DDE_SH})"
        done

        _logYellow "\nProject Commands:"
        for commandName in $(compgen -A function | grep "project:"); do
            echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName} ${DDE_SH})"
        done

        if [ -f ${ROOT_DIR}/dde.local.sh ]; then
            _logYellow "\nLocal Commands:"
            for commandName in $(compgen -A function | grep "local:"); do
                echo "   ${commandName:0:${#_functionName}}${_functionName:0:$((${#_functionName} - ${#commandName}))} $(_getFunctionHelp ${commandName})"
            done
        fi
    fi
}


function _getFunctionHelp() {

    local _scriptFile="${ROOT_DIR}/commands/${1//:/\/}.sh"
    if [[ -f ${_scriptFile} ]]; then
        sed -e '/^##.*/!d;p' ${_scriptFile} | head -1 | sed -e 's/^##\s*//g' | tr -d '\n'
    fi
}
