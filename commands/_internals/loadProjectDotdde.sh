# Enhanced version of _version_gte that also supports simple versions like 1 or 1.1
function _version_gte() {
    # Normalize both versions to the MAJOR.MINOR.PATCH format
    local version1=$(echo "$1" | awk -F. '{printf "%d.%d.%d", $1, $2+0, $3+0}')
    local version2=$(echo "$2" | awk -F. '{printf "%d.%d.%d", $1, $2+0, $3+0}')

    # Extract the MAJOR version numbers
    local major1=$(echo "$version1" | cut -d. -f1)
    local major2=$(echo "$version2" | cut -d. -f1)

    # Check if the MAJOR versions are equal
    if [ "$major1" -eq "$major2" ]; then
        # Perform the comparison using the normalized versions if MAJOR versions are equal
        if printf '%s\n%s' "$version2" "$version1" | sort -V -C 2> /dev/null; then
            return 0 # Version1 is greater than or equal to Version2
        else
            return 1 # Version1 is less than Version2
        fi
    else
        # The MAJOR versions are not equal, implying Version1 is not greater than or equal to Version2
        return 1
    fi
}


# Loads the project's .dde.yml file if present
function _loadProjectDotdde() {
    # Define the filename for the .dde.yml file
    local DDE_FILE=".dde.yml"

    # Check if the .dde.yml file exists in the current directory
    if [ ! -f "$DDE_FILE" ]; then
        _logYellow "File $DDE_FILE not found. Continuing without loading .dde.yml."
    else
        # Read the version number from the .dde.yml file
        local DDE_VERSION=$(_yq_stdin e '.version' < "$DDE_FILE")

        # Check if the version is compatible
        if _version_gte "$DDE_VERSION" "1.0.0"; then
            _logGreen "Compatible version detected. Processing .dde.yml based on version $DDE_VERSION."

            # Extract and export the container shell configuration

            DDE_CONTAINER_SHELL=$(_yq_stdin e '.container.shell' < "$DDE_FILE")
            export DDE_CONTAINER_SHELL

            # Apply specific logic for versions greater than or equal to 1.1.0
            # if _version_gte "$DDE_VERSION" "1.1.0"; then

            # fi
        else
            _logYellow "Unsupported version ($DDE_VERSION) in .dde.yml. Using default settings."
        fi
    fi

}
