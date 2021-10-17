
function _autocomplete() { ## add bash completions

    commands=""

    for commandName in $(compgen -A function | grep "system:"); do
        commands="${commands} ${commandName}"
    done

    commands="${commands} project"
    for commandName in $(compgen -A function | grep "project:"); do
        commands="${commands} ${commandName}"
    done

    if [ -f ${ROOT_DIR}/dde.local.sh ]; then
        for commandName in $(compgen -A function | grep ":"); do
            commands="${commands} ${commandName}"
        done
    fi

    echo -n "complete -W '${commands}' dde.sh"
}
