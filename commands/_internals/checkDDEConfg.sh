function _checkDdeConfig() {

    if [ -f ${DDE_CONFIG} ]; then
        _logGreen "dde.yml check OK"
        return
    fi

    ## copy dde.yml.dist
    if [ -f ${ROOT_DIR}/dde.yml.dist ]; then
        _logYellow "copy dde.yml.dist as new dde config file"
        cp ${ROOT_DIR}/dde.yml.dist ${DDE_CONFIG}
        return
    fi

    _logRed "dde.yml does not exists!"
    exit 1

}
