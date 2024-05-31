# Changelog

All notable changes to this project will be documented in this file. This project adheres to [Semantic Versioning](http://semver.org/).

## 2024-04-03

### Bugfixes

-   removed DOCKER_DEFAULT_PLATFORM due to missing arm containers
-   apple silicon arm platform is supported now

## 2024-03-19

### Bugfixes

-   mailcrap cert

## 2024-02-29

### Features

-   Add Mailcrab as replacement for Mailhog
-   Add warning if branch not master/main
-   Add add-ssh-keys command

### Bugfixes

-   Prevents accidental overwrite of the $service variable. (#74)

### Performance Improvements

-   Refactored \_serviceExists to use grep, enhancing efficiency. (#74)

### BREAKING CHANGES

-   Mailhog was removed. Migrate any configuration overwrites in `docker-compose.override.yml` from `mailhog` to `mailcrab`.

## 2024-02-23

### Features

-   Both commands/project/shell.sh and commands/project/shell/root.sh scripts have been enhanced with the addition of \_loadProjectDotdde. This new line ensures that the project's .dde configuration is loaded into the script's environment before proceeding with its operations. By explicitly loading the .dde configuration at the beginning of the script execution, we ensure that all subsequent operations within the script are informed by the project's specific settings and preferences. This addition enhances the robustness and reliability of script executions, especially in complex project environments where customized configurations are common.
-   \_loadProjectDotdde Function for .dde.yml File Processing
-   Integration of .dde.yml Configurations in Shell Scripts
-   Flexible Container Shell Configuration via .dde.yml
-   Modifications in configure-image.sh for Shell Configuration
-   Implements support for different versions of .dde.yml, allowing for future expansion and modifications.
-   Adds robust error checking and messages for missing or incorrect configurations in the .dde.yml file.
-   Ensures backward compatibility by skipping .dde.yml processing if the file does not exist, maintaining current functionality for projects without a .dde.yml file.
