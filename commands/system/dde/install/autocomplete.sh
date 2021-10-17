## installs autocompletion for your shell

function system:dde:install:autocomplete() {
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

    local _rcLine="eval \$(${ROOT_DIR}/dde.sh --autocomplete)"

    if [[ $(cat ${_rcFile} | grep -c "${_rcLine}") -eq 0 ]]; then
        echo "" >> ${_rcFile}
        echo "${_rcLine}" >> ${_rcFile}
        _logGreen "dde autocomplete added to your ${_rcFile}"
    else
        _logRed "dde autocomplete already added to your ${_rcFile}"
    fi
}

