## List available dde system services
#
# Command
#    system:services:available

function system:services:available() {
    cd ${ROOT_DIR}
    _logGreen "Available System services:"

    declare -a allServices

    _getServices allServices
    for service in "${allServices[@]}"; do
        echo "$service"
    done
}
