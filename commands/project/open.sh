## open the project url
#
# looks for an OPEN_URL environement in the web service
#
# open then in your standard browser or the program defined in the evironment DDE_BROWSER
#
# Command
#    project:open
#    open
#
function project:open() {
    _checkProject

    _serviceExists web

    for openUrl in $(${DOCKER_COMPOSE} config | _yq_stdin e '.services.*.environment.OPEN_URL'); do
        if [[ "${openUrl}" != "null" ]]; then
            _logGreen "open ${openUrl}"

            echo "DDE_BROWSER ${DDE_BROWSER}"

            if [[ "${DDE_BROWSER}" != "" ]]; then
              ${DDE_BROWSER} "${openUrl}" &
            elif [[ "${OSTYPE}" == "linux-gnu" ]]; then
                xdg-open "${openUrl}"
            elif [[ "${OSTYPE}" == "darwin" ]]; then
               open "${openUrl}"
            fi
        fi
    done
}

open() {
    project:open
}









