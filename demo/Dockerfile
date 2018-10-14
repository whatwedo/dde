FROM whatwedo/nginx:latest as base

# Set workdir
WORKDIR /var/www


########################################################################################################################

# Development environment
FROM base as dev


########################################################################################################################

# Production environment
FROM base as prod

# Add files
ADD . /var/www
