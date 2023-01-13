## List available dde system services
#
# Command
#    system:services:available

function system:services:available() {
    cd ${ROOT_DIR}
    _logGreen "Available System services:"
    cd services

    for f in *; do
    if [ -d "$f" ]; then
        # Will not run if no directories are available
        if [ -f "${f}/docker-compose.yml" ]; then
            echo "$f"
        fi
    fi
done
}
