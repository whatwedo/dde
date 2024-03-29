
function _autocomplete() { ## add bash completions

    commands=()

    for commandName in $(compgen -A function | grep "system:"); do
        commands+="${commandName} "
    done

    for commandName in $(compgen -A function | grep "project:"); do
        commands+="${commandName} "
    done

    for commandName in $(compgen -A function | grep "local:"); do
        commands+="${commandName} "
    done

    echo "complete -W '${commands[*]}' ${ROOT_DIR}/dde.sh"
}
