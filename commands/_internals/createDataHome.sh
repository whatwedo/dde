_createDataHome() {
    # Check if DDE_DATA_HOME is set
    if [ -z "$DDE_DATA_HOME" ]; then
        _logRed "DDE_DATA_HOME is not set."
        return 1
    fi

    # Create the main directory if it doesn't exist
    if [ ! -d "$DDE_DATA_HOME" ]; then
        mkdir -p "$DDE_DATA_HOME"
    fi

    # Check if DATA_DIR exists
    if [ -d "$DATA_DIR" ]; then
        # Move contents of DATA_DIR to DDE_DATA_HOME
        # Assuming DDE_DATA_HOME/data is the target directory
        local targetDir="$DDE_DATA_HOME/data"
        mkdir -p "$targetDir"
        mv "$DATA_DIR/*" "$targetDir/"
    fi

    # Create config.yml if it doesn't exist
    local configFile="$DDE_DATA_HOME/config.yml"
    if [ ! -f "$configFile" ]; then
        touch "$configFile"
    fi

    # Create dde.conf in the nginx conf.d directory if it doesn't exist
    local nginxConfFile="$DDE_DATA_HOME/data/reverseproxy/etc/nginx/conf.d/dde.conf"
    if [ ! -f "$nginxConfFile" ]; then
        mkdir -p "$(dirname "$nginxConfFile")"
        echo "client_max_body_size 100m;" > "$nginxConfFile"
    fi
}
