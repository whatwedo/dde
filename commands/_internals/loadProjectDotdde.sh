function _loadProjectDotdde {

    # Define the filename for the .dde.yml file
    DDE_FILE=".dde.yml"

    # Check if the .dde.yml file exists in the current directory
    if [ ! -f "$DDE_FILE" ]; then
        _logYellow "File $DDE_FILE not found. Continuing without loading .dde.yml."
    else
        # Read the version number from the .dde.yml file using the _yq_stdin function
        DDE_VERSION=$(_yq_stdin e '.version' < "$DDE_FILE")

        case $DDE_VERSION in
            1)
                _logGreen "Version 1 detected. Processing .dde.yml."

                # Read and process the 'container.shell' value from the .dde.yml file
                DDE_CONTAINER_SHELL=$(_yq_stdin e '.container.shell' < "$DDE_FILE")
                export DDE_CONTAINER_SHELL

                # Read and process the 'databases.type' value from the .dde.yml file
                DDE_DATABASE_TYPE=$(_yq_stdin e '.databases.type' < "$DDE_FILE")

                # Process the list of database names under 'databases.names'
                DDE_DATABASE_NAMES=$(_yq_stdin e '.databases.names[]' < "$DDE_FILE")
                ;;
            *)
                _logYellow "Unsupported version ($DDE_VERSION) or no version found in .dde.yml."
                ;;
        esac
    fi
}
