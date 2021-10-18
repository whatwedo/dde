## install alias for your shell

function system:dde:install:alias() {
    local _rcFile=""
    if [[ `basename $SHELL` == "zsh" ]]; then
        _rcFile="${HOME}/.zshrc"
    elif [[ `basename $SHELL` == "bash" ]]; then
        _rcFile="${HOME}/.bash_profile"
    fi

    if [[ "${_rcFile}" == "" ]]; then
        _logRed "unkown shell ${SHELL}"
        exit 1
    fi

    local _rcLine="alias dde='${ROOT_DIR}/dde.sh'"

    if [[ $(cat ${_rcFile} | grep -c "${_rcLine}") -eq 0 ]]; then
        echo "${_rcLine}" >> ${_rcFile}
        _logGreen "dde alias added to your ${_rcFile}"
    else
        _logRed "dde alias already added to your ${_rcFile}"
    fi
}

